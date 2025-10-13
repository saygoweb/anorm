<?php

namespace Anorm;

/**
 * Represents a parsed Mango Query
 * Holds all the components of a Mango query in a structured format
 */
class MangoQuery
{
    private array $selector;
    private ?array $fields;
    private ?array $sort;
    private ?int $limit;
    private ?int $skip;
    private ?string $useIndex;

    public function __construct(array $mangoQuery)
    {
        $this->validateMangoQuery($mangoQuery);
        
        $this->selector = $mangoQuery['selector'] ?? [];
        $this->fields = $mangoQuery['fields'] ?? null;
        $this->sort = $mangoQuery['sort'] ?? null;
        $this->limit = $mangoQuery['limit'] ?? null;
        $this->skip = $mangoQuery['skip'] ?? null;
        $this->useIndex = $mangoQuery['use_index'] ?? null;
    }

    public function getSelector(): array
    {
        return $this->selector;
    }

    public function getFields(): ?array
    {
        return $this->fields;
    }

    public function getSort(): ?array
    {
        return $this->sort;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getSkip(): ?int
    {
        return $this->skip;
    }

    public function getUseIndex(): ?string
    {
        return $this->useIndex;
    }

    /**
     * Validate the structure of a Mango query
     */
    private function validateMangoQuery(array $mangoQuery): void
    {
        // Selector is required if provided
        if (isset($mangoQuery['selector']) && !is_array($mangoQuery['selector'])) {
            throw new \InvalidArgumentException('Mango query selector must be an array');
        }

        // Fields must be an array if provided
        if (isset($mangoQuery['fields']) && !is_array($mangoQuery['fields'])) {
            throw new \InvalidArgumentException('Mango query fields must be an array');
        }

        // Sort must be an array if provided
        if (isset($mangoQuery['sort']) && !is_array($mangoQuery['sort'])) {
            throw new \InvalidArgumentException('Mango query sort must be an array');
        }

        // Limit must be a positive integer if provided
        if (isset($mangoQuery['limit'])) {
            if (!is_int($mangoQuery['limit']) || $mangoQuery['limit'] < 0) {
                throw new \InvalidArgumentException('Mango query limit must be a non-negative integer');
            }
        }

        // Skip must be a non-negative integer if provided
        if (isset($mangoQuery['skip'])) {
            if (!is_int($mangoQuery['skip']) || $mangoQuery['skip'] < 0) {
                throw new \InvalidArgumentException('Mango query skip must be a non-negative integer');
            }
        }

        // use_index must be a string if provided
        if (isset($mangoQuery['use_index']) && !is_string($mangoQuery['use_index'])) {
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
