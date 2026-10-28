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

        // Each language once, compared as text (`'1'` and `1` are one locale), the column's own excluded.
        $fallbacks = [];

        foreach ($fallbackLocales as $fallback) {
            $fallback = self::localeValue($fallback);

            if ((string) $fallback !== (string) $this->locale) {
                $fallbacks[(string) $fallback] ??= $fallback;
            }
        }

        $this->fallbackLocales = array_values($fallbacks);
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
        return [$this->locale, ...$this->fallbackLocales];
    }
}
