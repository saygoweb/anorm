<?php

namespace Anorm\Relationship\Strategy;

/**
 * Selects optimal query strategies for relationship loading
 * 
 * This class implements the decision logic for choosing between different
 * relationship loading strategies based on data characteristics, performance
 * requirements, and system constraints.
 */
class QueryStrategySelector implements QueryStrategyInterface
{
    /** @var DataSizeEstimator */
    private $dataEstimator;

    /** @var array Configuration options for strategy selection */
    private $config;

    public function __construct(DataSizeEstimator $dataEstimator = null, array $config = [])
    {
        $this->dataEstimator = $dataEstimator ?: new DataSizeEstimator();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Select the optimal query strategy for a relationship
     */
    public function selectStrategy($relationship, int $sourceCount, ?array $fieldSelection = null): string
    {
        // For very small datasets, individual loading might be acceptable
        if ($sourceCount <= $this->config['individual_loading_threshold']) {
            return self::STRATEGY_INDIVIDUAL_LOADING;
        }

        // Check if JOIN strategy is supported and beneficial
        if ($this->shouldUseJoinStrategy($relationship, $sourceCount, $fieldSelection)) {
            return self::STRATEGY_JOIN_WITH_SELECTION;
        }

        // Default to IN clause batch loading
        return self::STRATEGY_IN_CLAUSE_BATCH;
    }

    /**
     * Determine if JOIN strategy should be used
     */
    private function shouldUseJoinStrategy($relationship, int $sourceCount, ?array $fieldSelection): bool
    {
        // JOIN strategy is only beneficial with field selection
        if ($fieldSelection === null || empty($fieldSelection)) {
            return false;
        }

        // Check if relationship type supports JOIN optimization
        $cardinality = $relationship->getCardinality();
        if (!$this->isJoinOptimalForCardinality($cardinality)) {
            return false;
        }

        // Compare estimated data sizes
        $inClauseSize = $this->dataEstimator->estimateInClauseDataSize($relationship, $sourceCount);
        $joinSize = $this->dataEstimator->estimateJoinDataSize($relationship, $sourceCount, $fieldSelection);

        // Use JOIN if it reduces data transfer by the configured threshold
        $reductionRatio = 1 - ($joinSize / max($inClauseSize, 1));
        return $reductionRatio >= $this->config['join_strategy_threshold'];
    }

    /**
     * Check if JOIN strategy is optimal for a given cardinality
     */
    private function isJoinOptimalForCardinality(string $cardinality): bool
    {
        switch ($cardinality) {
            case 'one-to-one':
            case 'many-to-one':
                return true; // No data duplication
            case 'one-to-many':
                return true; // Can be beneficial with field selection
            case 'many-to-many':
                return false; // High risk of data explosion
            default:
                return false;
        }
    }

    /**
     * Get metadata about a strategy selection decision
     */
    public function getStrategyMetadata(string $strategy, $relationship, int $sourceCount, ?array $fieldSelection = null): array
    {
        $metadata = [
            'strategy' => $strategy,
            'source_count' => $sourceCount,
            'field_selection' => $fieldSelection,
            'cardinality' => $relationship->getCardinality(),
            'estimated_queries' => $this->estimateQueryCount($strategy, $sourceCount),
        ];

        // Add data size estimates
        if ($strategy === self::STRATEGY_IN_CLAUSE_BATCH) {
            $metadata['estimated_data_size'] = $this->dataEstimator->estimateInClauseDataSize($relationship, $sourceCount);
        } elseif ($strategy === self::STRATEGY_JOIN_WITH_SELECTION) {
            $metadata['estimated_data_size'] = $this->dataEstimator->estimateJoinDataSize($relationship, $sourceCount, $fieldSelection);
        }

        // Add decision reasoning
        $metadata['decision_factors'] = $this->getDecisionFactors($strategy, $relationship, $sourceCount, $fieldSelection);

        return $metadata;
    }

    /**
     * Estimate number of queries for a strategy
     */
    private function estimateQueryCount(string $strategy, int $sourceCount): int
    {
        switch ($strategy) {
            case self::STRATEGY_INDIVIDUAL_LOADING:
                return $sourceCount; // N queries
            case self::STRATEGY_IN_CLAUSE_BATCH:
                return 1; // Single batch query
            case self::STRATEGY_JOIN_WITH_SELECTION:
                return 1; // Single JOIN query
            default:
                return $sourceCount;
        }
    }

    /**
     * Get factors that influenced the strategy decision
     */
    private function getDecisionFactors(string $strategy, $relationship, int $sourceCount, ?array $fieldSelection): array
    {
        $factors = [];

        if ($sourceCount <= $this->config['individual_loading_threshold']) {
            $factors[] = 'Small dataset size favors individual loading';
        }

        if ($fieldSelection !== null && !empty($fieldSelection)) {
            $factors[] = 'Field selection available for optimization';
        } else {
            $factors[] = 'No field selection - full records needed';
        }

        $cardinality = $relationship->getCardinality();
        $factors[] = "Relationship cardinality: {$cardinality}";

        if ($strategy === self::STRATEGY_JOIN_WITH_SELECTION) {
            $factors[] = 'JOIN strategy selected for data transfer optimization';
        } elseif ($strategy === self::STRATEGY_IN_CLAUSE_BATCH) {
            $factors[] = 'IN clause batch loading selected for query optimization';
        }

        return $factors;
    }

    /**
     * Check if a strategy is supported for a given relationship type
     */
    public function isStrategySupported(string $strategy, string $relationshipType): bool
    {
        // All strategies are supported for all relationship types
        // Individual implementations may have specific limitations
        return in_array($strategy, [
            self::STRATEGY_INDIVIDUAL_LOADING,
            self::STRATEGY_IN_CLAUSE_BATCH,
            self::STRATEGY_JOIN_WITH_SELECTION
        ]);
    }

    /**
     * Get default configuration options
     */
    private function getDefaultConfig(): array
    {
        return [
            'individual_loading_threshold' => 10, // Use individual loading for <= 10 models
            'join_strategy_threshold' => 0.5,     // Use JOIN if it reduces data by 50%+
            'max_in_clause_size' => 1000,         // Maximum items in IN clause
            'enable_join_strategy' => true,       // Enable JOIN optimization
            'debug_mode' => false,                 // Enable debug logging
        ];
    }

    /**
     * Update configuration options
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
