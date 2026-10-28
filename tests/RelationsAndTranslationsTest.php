<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Columns\TranslatableColumn;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\Exceptions\UnsupportedRelationException;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * Ordering and search through relations and translations, on a model whose key
 * is not `id`, with a morph map.
 */
final class RelationsAndTranslationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rl_shops', function (Blueprint $table) {
            $table->increments('uid');
            $table->string('name');
        });

        Schema::create('rl_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('shop_uid');
            $table->string('city');
        });

        Schema::create('rl_images', function (Blueprint $table) {
            $table->id();
            $table->string('imageable_type');
            $table->unsignedInteger('imageable_id');
            $table->string('name');
        });

        Schema::create('rl_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('shop_uid');
            $table->string('name');
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('translatable_id');
            $table->string('translatable_type');
            $table->string('locale', 8);
            $table->string('key');
            $table->text('text');
        });

        Relation::enforceMorphMap(['shop' => RlShop::class]);

        // uid 10, 11, 12: no id lines up with a uid.
        foreach ([[10, 'Zeta', 'Varna', 'b.png', 'Ябълка', 'Apple'], [11, 'Alpha', 'Sofia', 'c.png', 'Круша', 'Pear'], [12, 'Mid', 'Burgas', 'a.png', 'Банан', 'Banana']] as [$uid, $name, $city, $image, $bg, $en]) {
            $shop = RlShop::create(['uid' => $uid, 'name' => $name]);
            $shop->profile()->create(['city' => $city]);
            $shop->logo()->create(['name' => $image]);
            $shop->items()->create(['name' => "{$name} item"]);
            $shop->translations()->create(['locale' => 'en', 'key' => 'title', 'text' => $en]);
            $shop->translations()->create(['locale' => 'bg', 'key' => 'title', 'text' => $bg]);
        }
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    /** @return list<int> */
    private function uids(DataTable $table): array
    {
        return $table->getData()->pluck('uid')->map(fn ($uid) => (int) $uid)->all();
    }

    private function request(array $query): Request
    {
        return Request::create('/', 'GET', $query);
    }

    public function testOrdersByATranslationUnderAMorphMapAndAKeyThatIsNotId(): void
    {
        $table = (new DataTable(RlShop::query(), $this->request(['ordering' => ['key' => 'title', 'direction' => 'asc']])))
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title', orderable: true))
            ->process();

        // Apple (10), Banana (12), Pear (11)
        $this->assertSame([10, 12, 11], $this->uids($table));
    }

    public function testTheDefaultSerializationNamesTheModelsKey(): void
    {
        $table = (new DataTable(RlShop::query(), $this->request([])))->process();

        $this->assertSame('uid', $table->keyName);
        $this->assertSame('uid', json_decode((string) json_encode($table), true)['keyName']);
    }

    public function testShowsTheTranslationOfTheColumnsLocale(): void
    {
        $table = (new DataTable(RlShop::query(), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn('bg', 'title', 'Title'))
            ->process();

        $this->assertSame(['Банан', 'Круша', 'Ябълка'], $table->getData()->pluck('title')->all());
    }

    public function testShowsTheTranslationWhenTheTranslationModelCastsItsLocaleOrKey(): void
    {
        Config::set('data-table.translatable_relation', 'castTranslations');

        // The locale cast to an enum, the key to a value object; the column takes an enum too.
        $table = (new DataTable(RlShop::query(), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn(RlLocale::Bg, 'title', 'Title'))
            ->process();

        $this->assertSame(['Банан', 'Круша', 'Ябълка'], $table->getData()->pluck('title')->all());
    }

    public function testLoadsTheTranslationsWithThePage(): void
    {
        Model::preventLazyLoading();

        $table = (new DataTable(RlShop::query(), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title'))
            ->process();

        $this->assertSame(['Banana', 'Pear', 'Apple'], $table->getData()->pluck('title')->all());
        // Only the translations the columns show.
        $this->assertCount(1, $table->getData()->first()->translations);
    }

    public function testLeavesTheAppsOwnLoadOfTheTranslations(): void
    {
        $table = (new DataTable(RlShop::query()->with('translations'), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title'))
            ->process();

        $this->assertCount(2, $table->getData()->first()->translations);
        $this->assertSame('Banana', $table->getData()->first()->title);
    }

    public function testSearchesTheTranslations(): void
    {
        $table = (new DataTable(RlShop::query(), $this->request(['filter' => ['global' => 'Круш']])))
            ->setTranslatableColumn(new TranslatableColumn('bg', 'title', 'Title', searchable: true))
            ->process();

        $this->assertSame([11], $this->uids($table));
    }

    /**
     * Shops for the fallback languages, next to 10–12 (which have both `en` and `bg`):
     * 13 only `en`; 14 an empty `en` and a `bg`; 15 nothing; 16 only `xx`, a language
     * neither the columns nor the enum know.
     */
    private function seedFallbackShops(): void
    {
        foreach ([[13, ['en' => 'Cherry']], [14, ['en' => '  ', 'bg' => 'Date']], [15, []], [16, ['xx' => 'Ghost']]] as [$uid, $texts]) {
            $shop = RlShop::create(['uid' => $uid, 'name' => "Shop {$uid}"]);

            foreach ($texts as $locale => $text) {
                $shop->translations()->create(['locale' => $locale, 'key' => 'title', 'text' => $text]);
            }
        }
    }

    /** @return array<int, string|null> uid => the title the row shows */
    private function titles(DataTable $table): array
    {
        return $table->getData()->mapWithKeys(fn ($shop) => [(int) $shop->uid => $shop->title])->sortKeys()->all();
    }

    public function testShowsTheFirstLanguageWithAText(): void
    {
        $this->seedFallbackShops();

        $table = (new DataTable(RlShop::query(), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn('bg', 'title', 'Title', fallbackLocales: ['en']))
            ->process();

        // 13: no bg; 14: bg has the text; 15 and 16: nothing in bg or en (xx is not listed).
        $this->assertSame(
            [10 => 'Ябълка', 11 => 'Круша', 12 => 'Банан', 13 => 'Cherry', 14 => 'Date', 15 => null, 16 => null],
            $this->titles($table),
        );
    }

    public function testAnEmptyTextFallsBackToTheNextLanguage(): void
    {
        $this->seedFallbackShops();

        $table = (new DataTable(RlShop::query(), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title', fallbackLocales: ['bg']))
            ->process();

        $this->assertSame('Date', $this->titles($table)[14]);
    }

    public function testWithoutFallbacksAnEmptyTextStaysAsItIs(): void
    {
        $this->seedFallbackShops();

        $table = (new DataTable(RlShop::query(), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title'))
            ->process();

        $this->assertSame('  ', $this->titles($table)[14]);
    }

    public function testFallbackLanguagesTakeEnumsAndSkipALanguageTheCastDoesNotKnow(): void
    {
        $this->seedFallbackShops();
        Config::set('data-table.translatable_relation', 'castTranslations');

        // Shop 16 stores `xx`, which RlLocale cannot cast: it is skipped, not thrown on.
        $table = (new DataTable(RlShop::query(), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn(RlLocale::Bg, 'title', 'Title', fallbackLocales: [RlLocale::En, RlLocale::Bg]))
            ->process();

        $this->assertSame(['bg', 'en'], (new TranslatableColumn(RlLocale::Bg, 'title', fallbackLocales: [RlLocale::En, RlLocale::Bg]))->getLocales());
        $this->assertSame([13 => 'Cherry', 14 => 'Date', 15 => null, 16 => null], array_slice($this->titles($table), 3, null, true));
    }

    public function testLoadsTheFallbackLanguagesWithThePage(): void
    {
        $this->seedFallbackShops();
        Model::preventLazyLoading();

        $table = (new DataTable(RlShop::query(), $this->request([])))
            ->setTranslatableColumn(new TranslatableColumn('bg', 'title', 'Title', fallbackLocales: ['en']))
            ->process();

        $this->assertSame('Cherry', $this->titles($table)[13]);
        // bg and en of the column, not the xx of shop 16.
        $this->assertCount(0, $table->getData()->firstWhere('uid', 16)->translations);
        $this->assertCount(2, $table->getData()->firstWhere('uid', 10)->translations);
    }

    public function testSearchesEveryLanguageOfTheColumn(): void
    {
        $this->seedFallbackShops();

        $global = (new DataTable(RlShop::query(), $this->request(['filter' => ['global' => 'Cherr']])))
            ->setTranslatableColumn(new TranslatableColumn('bg', 'title', 'Title', searchable: true, fallbackLocales: ['en']))
            ->process();

        $byColumn = (new DataTable(RlShop::query(), $this->request(['filter' => ['title' => 'Cherr']])))
            ->setTranslatableColumn(new TranslatableColumn('bg', 'title', 'Title', filterable: true, fallbackLocales: ['en']))
            ->process();

        // A language the column does not list is not searched.
        $ghost = (new DataTable(RlShop::query(), $this->request(['filter' => ['global' => 'Ghost']])))
            ->setTranslatableColumn(new TranslatableColumn('bg', 'title', 'Title', searchable: true, fallbackLocales: ['en']))
            ->process();

        $this->assertSame([13], $this->uids($global));
        $this->assertSame([13], $this->uids($byColumn));
        $this->assertSame([], $this->uids($ghost));
    }

    public function testOrdersByTheShownTextWithTheRowsWithoutOneLastInBothDirections(): void
    {
        $this->seedFallbackShops();

        $order = fn (string $direction) => $this->uids((new DataTable(RlShop::query(), $this->request(['ordering' => ['key' => 'title', 'direction' => $direction]])))
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title', orderable: true, fallbackLocales: ['bg']))
            ->process());

        // Apple 10, Banana 12, Cherry 13, Date 14 (bg, en is empty), Pear 11; then 15 and 16 by key.
        $this->assertSame([10, 12, 13, 14, 11, 15, 16], $order('asc'));
        $this->assertSame([11, 14, 13, 12, 10, 16, 15], $order('desc'));
    }

    public function testWithoutFallbacksRowsWithoutATextAreLastInBothDirections(): void
    {
        $this->seedFallbackShops();

        $order = fn (string $direction) => $this->uids((new DataTable(RlShop::query(), $this->request(['ordering' => ['key' => 'title', 'direction' => $direction]])))
            ->setTranslatableColumn(new TranslatableColumn('en', 'title', 'Title', orderable: true))
            ->process());

        // 14's en is empty: it counts as no text.
        $this->assertSame([10, 12, 13, 11, 14, 15, 16], $order('asc'));
        $this->assertSame([11, 13, 12, 10, 16, 15, 14], $order('desc'));
    }

    public function testOrdersByAHasOneRelation(): void
    {
        $table = (new DataTable(RlShop::query(), $this->request(['ordering' => ['key' => 'profile.city', 'direction' => 'asc']])))
            ->setRelationColumn(new RelationColumn('profile.city', 'City', orderable: true))
            ->process();

        // Burgas (12), Sofia (11), Varna (10)
        $this->assertSame([12, 11, 10], $this->uids($table));
    }

    public function testOrdersByAMorphOneRelation(): void
    {
        $table = (new DataTable(RlShop::query(), $this->request(['ordering' => ['key' => 'logo.name', 'direction' => 'asc']])))
            ->setRelationColumn(new RelationColumn('logo.name', 'Logo', orderable: true))
            ->process();

        // a.png (12), b.png (10), c.png (11)
        $this->assertSame([12, 10, 11], $this->uids($table));
    }

    public function testAnOrderableToManyRelationIsRejectedWhenDeclared(): void
    {
        $this->expectException(UnsupportedRelationException::class);

        (new DataTable(RlShop::query(), $this->request([])))
            ->setRelationColumn(new RelationColumn('items.name', 'Items', orderable: true));
    }

    public function testAnOrderableRelationColumnPassedToSetColumnIsCheckedToo(): void
    {
        $this->expectException(UnsupportedRelationException::class);

        (new DataTable(RlShop::query(), $this->request([])))
            ->setColumn(new RelationColumn('items.name', 'Items', orderable: true));
    }

    public function testAnOrderableOneOfManyRelationIsRejectedWhenDeclared(): void
    {
        $this->expectException(UnsupportedRelationException::class);

        (new DataTable(RlShop::query(), $this->request([])))
            ->setRelationColumn(new RelationColumn('latestItem.name', 'Latest item', orderable: true));
    }

    public function testAnOrderableColumnOnAMethodThatIsNotARelationIsRejected(): void
    {
        $this->expectException(UnsupportedRelationException::class);

        (new DataTable(RlShop::query(), $this->request([])))
            ->setRelationColumn(new RelationColumn('nope.name', 'Nope', orderable: true));
    }

    public function testASearchableToManyRelationColumnStillFilters(): void
    {
        $table = (new DataTable(RlShop::query(), $this->request(['filter' => ['global' => 'Alpha item']])))
            ->setRelationColumn(new RelationColumn('items.name', 'Items', searchable: true))
            ->process();

        $this->assertSame([11], $this->uids($table));
    }

    public function testTheTranslationRelationAndColumnsFollowTheConfig(): void
    {
        Schema::create('rl_texts', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedInteger('owner_id');
            $table->string('lang', 8);
            $table->string('field');
            $table->text('body');
        });

        foreach (RlShop::all() as $shop) {
            $shop->texts()->create(['lang' => 'de', 'field' => 'title', 'body' => "{$shop->name} DE"]);
        }

        Config::set('data-table.translatable_relation', 'texts');
        Config::set('data-table.translatable_column', 'field');
        Config::set('data-table.translatable_locale_column', 'lang');
        Config::set('data-table.translatable_text_column', 'body');

        $table = (new DataTable(RlShop::query(), $this->request([
            'filter'   => ['global' => 'DE'],
            'ordering' => ['key' => 'title', 'direction' => 'asc'],
        ])))
            ->setTranslatableColumn(new TranslatableColumn('de', 'title', 'Title', searchable: true, orderable: true))
            ->process();

        $this->assertSame(['Alpha DE', 'Mid DE', 'Zeta DE'], $table->getData()->pluck('title')->all());
    }
}

class RlShop extends Model
{
    protected $table = 'rl_shops';

    protected $primaryKey = 'uid';

    protected $guarded = [];

    public $timestamps = false;

    public function profile(): HasOne
    {
        return $this->hasOne(RlProfile::class, 'shop_uid');
    }

    public function logo(): MorphOne
    {
        return $this->morphOne(RlImage::class, 'imageable');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RlItem::class, 'shop_uid');
    }

    public function latestItem(): HasOne
    {
        return $this->hasOne(RlItem::class, 'shop_uid')->latestOfMany();
    }

    public function translations(): MorphMany
    {
        return $this->morphMany(RlTranslation::class, 'translatable');
    }

    public function castTranslations(): MorphMany
    {
        return $this->morphMany(RlCastTranslation::class, 'translatable');
    }

    public function texts(): MorphMany
    {
        return $this->morphMany(RlText::class, 'owner');
    }

    public function nope(): string
    {
        return 'not a relation';
    }
}

class RlProfile extends Model
{
    protected $table = 'rl_profiles';

    protected $guarded = [];

    public $timestamps = false;
}

class RlImage extends Model
{
    protected $table = 'rl_images';

    protected $guarded = [];

    public $timestamps = false;
}

class RlItem extends Model
{
    protected $table = 'rl_items';

    protected $guarded = [];

    public $timestamps = false;
}

class RlTranslation extends Model
{
    protected $table = 'translations';

    protected $guarded = [];

    public $timestamps = false;
}

class RlText extends Model
{
    protected $table = 'rl_texts';

    protected $guarded = [];

    public $timestamps = false;
}

enum RlLocale: string
{
    case En = 'en';
    case Bg = 'bg';
}

class RlCastTranslation extends Model
{
    protected $table = 'translations';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['locale' => RlLocale::class, 'key' => AsStringable::class];
}
