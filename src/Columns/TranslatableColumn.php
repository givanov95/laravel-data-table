<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Columns;

class TranslatableColumn extends Column
{
    /**
     * @var string
     */
    public readonly string $translationKey;

    /**
     * @var string|int
     */
    public readonly string|int $locale;

    public function __construct(
        string|int $locale,
        string $translationKey,
        ?string $label = null,
        bool $searchable = false,
        bool $orderable = false,
        bool $exactMatch = false
    ) {
        parent::__construct($label, $searchable, $orderable, $exactMatch);

        $this->locale = $locale;
        $this->translationKey = $translationKey;
    }

    /**
     * Get the value of translationKey
     *
     * @return string
     */
    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    /**
     * Get the value of locale
     */
    public function getLocale()
    {
        return $this->locale;
    }
}
