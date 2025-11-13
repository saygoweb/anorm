<?php

namespace Anorm;

/**
 * Represents a parsed Mango Query
 * Holds all the components of a Mango query in a structured format
 */
class MangoQuery
{
    // Mango Query field constants
    public const MANGO_SELECTOR = 'selector';
    public const MANGO_FIELDS = 'fields';
    public const MANGO_SORT = 'sort';
    public const MANGO_LIMIT = 'limit';
    public const MANGO_SKIP = 'skip';
    public const MANGO_USE_INDEX = 'use_index';

    public array $selector;
    public ?array $fields;
    public ?array $sort;
    public ?int $limit;
    public ?int $skip;
    public ?string $useIndex;

    public function __construct()
    {
    }

    /**
     * Create a MangoQuery instance from an array
     *
     * @param array $mangoQuery The Mango Query array
     * @return self
     */
    public static function fromArray(array $mangoQuery): self
    {
        $query  = new MangoQuery();
        $query->validateMangoQuery($mangoQuery);

        $query->selector = $mangoQuery[self::MANGO_SELECTOR] ?? [];
        $query->fields = $mangoQuery[self::MANGO_FIELDS] ?? null;
        $query->sort = $mangoQuery[self::MANGO_SORT] ?? null;
        $query->limit = $mangoQuery[self::MANGO_LIMIT] ?? null;
        $query->skip = $mangoQuery[self::MANGO_SKIP] ?? null;
        $query->useIndex = $mangoQuery[self::MANGO_USE_INDEX] ?? null;
        return $query;
    }

    /**
     * Validate the structure of a Mango query
     */
    private function validateMangoQuery(array $mangoQuery): void
    {
        // Selector is required if provided
        if (isset($mangoQuery[self::MANGO_SELECTOR]) && !is_array($mangoQuery[self::MANGO_SELECTOR])) {
            throw new \InvalidArgumentException('Mango query selector must be an array');
        }

        // Fields must be an array if provided
        if (isset($mangoQuery[self::MANGO_FIELDS]) && !is_array($mangoQuery[self::MANGO_FIELDS])) {
            throw new \InvalidArgumentException('Mango query fields must be an array');
        }

        // Sort must be an array if provided
        if (isset($mangoQuery[self::MANGO_SORT]) && !is_array($mangoQuery[self::MANGO_SORT])) {
            throw new \InvalidArgumentException('Mango query sort must be an array');
        }

        // Limit must be a positive integer if provided
        if (isset($mangoQuery[self::MANGO_LIMIT])) {
            if (!is_int($mangoQuery[self::MANGO_LIMIT]) || $mangoQuery[self::MANGO_LIMIT] < 0) {
                throw new \InvalidArgumentException('Mango query limit must be a non-negative integer');
            }
        }

        // Skip must be a non-negative integer if provided
        if (isset($mangoQuery[self::MANGO_SKIP])) {
            if (!is_int($mangoQuery[self::MANGO_SKIP]) || $mangoQuery[self::MANGO_SKIP] < 0) {
                throw new \InvalidArgumentException('Mango query skip must be a non-negative integer');
            }
        }

        // use_index must be a string if provided
        if (isset($mangoQuery[self::MANGO_USE_INDEX]) && !is_string($mangoQuery[self::MANGO_USE_INDEX])) {
            throw new \InvalidArgumentException('Mango query use_index must be a string');
        }
    }

    /**
     * Check if this query has any conditions
     */
    public function hasConditions(): bool
    {
        return !empty($this->selector);
    }

    /**
     * Check if this query specifies fields to select
     */
    public function hasFields(): bool
    {
        return $this->fields !== null && !empty($this->fields);
    }

    /**
     * Check if this query has sorting
     */
    public function hasSort(): bool
    {
        return $this->sort !== null && !empty($this->sort);
    }

    /**
     * Check if this query has pagination
     */
    public function hasPagination(): bool
    {
        return $this->limit !== null || $this->skip !== null;
    }
}
