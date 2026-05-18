<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

use Givanov95\DataTable\DataTableConfig;

final class TranslatableColumn extends Column
{
    public readonly string|int $locale;

    public readonly string $translationKey;

    public function __construct(
        string|int $locale,
        string $translationKey,
        ?string $label = null,
        bool $searchable = false,
        bool $orderable = false,
        bool $exactMatch = false,
    ) {
        parent::__construct(
            databaseColumnName: DataTableConfig::getTranslatableColumnName(),
            label: $label,
            searchable: $searchable,
            orderable: $orderable,
            exactMatch: $exactMatch,
        );

        $this->translationKey = $translationKey;
        $this->locale = $locale;
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    public function getLocale(): string|int
    {
        return $this->locale;
    }
}
