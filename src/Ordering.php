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

    /**
     * Set only on an ordering whose key came from the request: the trusted
     * ordering to apply when that key does not resolve to a declared,
     * orderable column.
     */
    public readonly ?self $fallback;

    public function __construct(string $key = 'id', string $direction = 'DESC', ?self $fallback = null)
    {
        $this->key = $key;
        $this->direction = $direction;
        $this->fallback = $fallback;

        $this->initPropsFromKey();
    }

    /**
     * Build an Ordering from a Laravel Request, looking up the configured
     * `ordering` parameter (`ordering[key]`, `ordering[direction]`).
     *
     * A key taken from the request is untrusted: {@see \Givanov95\DataTable\DataTable}
     * only applies it when it matches a declared, orderable column and uses
     * the default ordering otherwise.
     */
    public static function fromRequest(Request $request, string $defaultKey = 'id', string $defaultDirection = 'DESC'): self
    {
        $values = $request->input(DataTableConfig::getOrderingKey());

        if (! is_array($values)) {
            $values = [];
        }

        $direction = (string) ($values['direction'] ?? $defaultDirection);

        if (! isset($values['key'])) {
            return new self($defaultKey, $direction);
        }

        if (! is_string($values['key'])) {
            return new self($defaultKey, $defaultDirection);
        }

        return new self(
            key: $values['key'],
            direction: $direction,
            fallback: new self($defaultKey, $defaultDirection),
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
