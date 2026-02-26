<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class Ordering
{
    /**
     * The key by which to order the results.
     *
     * @var string|mixed
     */
    public string $key;

    /**
     * The direction of the ordering (ASC or DESC).
     *
     * @var string|mixed
     */
    public string $direction;

    /**
     * The name of the column to be used for ordering.
     *
     * @var string
     */
    public string $columnName;

    /**
     * Indicates if the ordering involves relations.
     *
     * @var bool
     */
    public bool $hasRelations;

    /**
     * A string representation of the relations.
     *
     * @var null|string
     */
    public ?string $relationsString;

    /**
     * A string representation of the relations.
     *
     * @var null|array
     */
    public ?array $relationsArray;

    /**
     * Create a new Ordering instance.
     *
     * @param  string                      $key
     * @param  string                      $direction
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(string $key = 'id', string $direction = 'DESC')
    {
        $orderByValues = request()->get('ordering', [
            'key'       => $key,
            'direction' => $direction,
        ]);

        $this->key = $orderByValues['key'];
        $this->direction = $orderByValues['direction'];

        $this->initPropsFromKey();
    }

    /**
     * Initialize properties.
     *
     * @return void
     */
    private function initPropsFromKey(): void
    {
        $relationsArray = explode('.', $this->key);
        $this->columnName = array_pop($relationsArray);
        $this->relationsArray = $relationsArray;
        $this->hasRelations = ! empty($this->relationsArray);

        if ($this->hasRelations) {
            $this->relationsString = implode('.', $this->relationsArray);
        }
    }

    /**
     * Get the value of key.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Set the value of key.
     *
     * @param  string $key
     * @return self
     */
    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Get the value of direction.
     *
     * @return string
     */
    public function getDirection(): string
    {
        return $this->direction;
    }

    /**
     * Set the value of direction.
     *
     * @param  string $direction
     * @return self
     */
    public function setDirection(string $direction): self
    {
        $this->direction = $direction;

        return $this;
    }
}
