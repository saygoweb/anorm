<?php

namespace Anorm\Relationship\Strategy;

/**
 * Interface for selecting optimal query strategies for relationship loading
 *
 * This interface defines the contract for choosing between different
 * relationship loading strategies based on data characteristics and requirements.
 */
interface QueryStrategyInterface
{
    /** Strategy constants */
    const STRATEGY_IN_CLAUSE_BATCH = 'in_clause_batch';
    const STRATEGY_JOIN_WITH_SELECTION = 'join_with_selection';
    const STRATEGY_INDIVIDUAL_LOADING = 'individual_loading';

    /**
     * Select the optimal query strategy for a relationship
     *
     * Analyzes the relationship characteristics, source model count, and field
     * selection requirements to determine the most efficient loading strategy.
     *
     * @param object $relationship The relationship instance to analyze
     * @param int $sourceCount Number of source models that need this relationship loaded
     * @param array|null $fieldSelection Specific fields to load, or null for all fields
     * @return string One of the STRATEGY_* constants
     */
    public function selectStrategy($relationship, int $sourceCount, ?array $fieldSelection = null): string;

    /**
     * Get metadata about a strategy selection decision
     *
     * Provides detailed information about why a particular strategy was chosen,
     * including estimated data sizes, query counts, and performance implications.
     *
     * @param string $strategy The selected strategy
     * @param object $relationship The relationship that was analyzed
     * @param int $sourceCount Number of source models
     * @param array|null $fieldSelection Field selection used
     * @return array Metadata about the strategy decision
     */
    public function getStrategyMetadata(string $strategy, $relationship, int $sourceCount, ?array $fieldSelection = null): array;

    /**
     * Check if a strategy is supported for a given relationship type
     *
     * @param string $strategy Strategy to check
     * @param string $relationshipType Type of relationship (oneHasMany, manyHasOne, etc.)
     * @return bool True if the strategy is supported for this relationship type
     */
    public function isStrategySupported(string $strategy, string $relationshipType): bool;
}
