<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests;

use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Columns\TranslatableColumn;
use Givanov95\DataTable\DataTable;
use Givanov95\DataTable\Exceptions\UnsupportedRelationException;
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
