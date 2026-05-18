<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Illuminate\Http\Request;

final class Ordering
{
    public string $key;

    public string $direction;

    public string $columnName;

    public bool $hasRelations = false;

    public ?string $relationsString = null;

    /** @var string[] */
    public array $relationsArray = [];

    public function __construct(string $key = 'id', string $direction = 'DESC')
    {
        $this->key = $key;
        $this->direction = $direction;

        $this->initPropsFromKey();
    }

    /**
     * Build an Ordering from a Laravel Request, looking up the configured
     * `ordering` parameter (`ordering[key]`, `ordering[direction]`).
     */
    public static function fromRequest(Request $request, string $defaultKey = 'id', string $defaultDirection = 'DESC'): self
    {
        $orderingKey = DataTableConfig::getOrderingKey();
        $values = $request->input($orderingKey, [
            'key'       => $defaultKey,
            'direction' => $defaultDirection,
        ]);

        if (! is_array($values)) {
            $values = [
                'key'       => $defaultKey,
                'direction' => $defaultDirection,
            ];
        }

        return new self(
            key: (string) ($values['key'] ?? $defaultKey),
            direction: (string) ($values['direction'] ?? $defaultDirection),
        );
    }

    private function initPropsFromKey(): void
    {
        $relations = explode('.', $this->key);
        $this->columnName = (string) array_pop($relations);
        $this->relationsArray = $relations;
        $this->hasRelations = ! empty($relations);
        $this->relationsString = $this->hasRelations ? implode('.', $relations) : null;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }
}
