<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

use Givanov95\DataTable\DataTableConfig;

final class TranslatableColumn extends Column
{
    public readonly string|int $locale;

    public readonly string $translationKey;

    /** @var list<string|int> */
    public readonly array $fallbackLocales;

    /**
     * @param string|int|\UnitEnum                $locale          an enum (`Locale::Bg`) is taken as its value
     * @param array<array-key, string|int|\UnitEnum> $fallbackLocales languages tried in order when the
     *                                                                row has no text (or an empty one) in `$locale`
     */
    public function __construct(
        string|int|\UnitEnum $locale,
        string $translationKey,
        ?string $label = null,
        bool $searchable = false,
        bool $orderable = false,
        bool $exactMatch = false,
        bool $filterable = false,
        array $fallbackLocales = [],
    ) {
        parent::__construct(
            databaseColumnName: DataTableConfig::getTranslatableColumnName(),
            label: $label,
            searchable: $searchable,
            orderable: $orderable,
            exactMatch: $exactMatch,
            filterable: $filterable,
        );

        $this->translationKey = $translationKey;
        $this->locale = self::localeValue($locale);
        $this->fallbackLocales = array_values(array_filter(
            array_map(self::localeValue(...), $fallbackLocales),
            fn (string|int $fallback) => (string) $fallback !== (string) $this->locale,
        ));
    }

    private static function localeValue(string|int|\UnitEnum $locale): string|int
    {
        return $locale instanceof \UnitEnum ? \Illuminate\Support\enum_value($locale) : $locale;
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    public function getLocale(): string|int
    {
        return $this->locale;
    }

    /** @return list<string|int> */
    public function getFallbackLocales(): array
    {
        return $this->fallbackLocales;
    }

    /**
     * The column's language first, then the fallbacks, each once: the order a text is looked for.
     *
     * @return list<string|int>
     */
    public function getLocales(): array
    {
        $locales = [];

        foreach ([$this->locale, ...$this->fallbackLocales] as $locale) {
            $locales[(string) $locale] ??= $locale;
        }

        return array_values($locales);
    }
}
