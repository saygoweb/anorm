<?php

namespace Anorm\Relationship\BatchLoader;

/**
 * Interface for batch loading relationships to optimize N+1 query problems
 * 
 * This interface defines the contract for loading relationships in batches
 * rather than individually, reducing database queries from O(N) to O(1).
 */
interface BatchLoaderInterface
{
    /**
     * Load relationships for multiple source models in a single batch operation
     * 
     * This method should collect all necessary foreign keys from the source models,
     * execute a single optimized query (using IN clause or JOIN), and return
     * the results grouped appropriately for distribution.
     * 
     * @param array $sourceModels Array of model instances that need relationships loaded
     * @param string $relationshipName Name of the relationship to load
     * @return array Associative array of loaded relationship data, keyed by source model identifier
     */
    public function batchLoad(array $sourceModels, string $relationshipName): array;

    /**
     * Distribute batch-loaded results to their corresponding source models
     * 
     * This method takes the results from batchLoad() and assigns them to the
     * appropriate properties on each source model. The assignment pattern
     * depends on the relationship type (single model vs array of models).
     * 
     * @param array $sourceModels Array of model instances to receive the loaded data
     * @param array $batchResults Results from batchLoad(), keyed by source model identifier
     * @param string $relationshipName Name of the relationship being distributed
     * @return void
     */
    public function distributeBatchResults(array $sourceModels, array $batchResults, string $relationshipName): void;
}
