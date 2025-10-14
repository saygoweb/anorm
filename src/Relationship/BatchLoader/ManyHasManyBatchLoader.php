<?php

namespace Anorm\Relationship\BatchLoader;

/**
 * Batch loader for Many-to-Many relationships (hasManyThrough)
 *
 * Optimizes loading of many-to-many relationships by collecting all primary keys
 * and executing a single complex JOIN query through the pivot table instead of N individual queries.
 */
class ManyHasManyBatchLoader implements BatchLoaderInterface
{
    /**
     * Load many-to-many relationships for multiple source models in a single batch
     *
     * @param array $sourceModels Array of model instances that need relationships loaded
     * @param string $relationshipName Name of the relationship to load
     * @param array|null $fieldSelection Optional field selection for optimization
     * @return array Associative array of loaded relationship data, keyed by source model primary key
     */
    public function batchLoad(array $sourceModels, string $relationshipName, ?array $fieldSelection = null): array
    {
        if (empty($sourceModels)) {
            return [];
        }

        // Get relationship definition from the first model
        $firstModel = reset($sourceModels);
        $relationshipManager = $firstModel->getRelationshipManager();
        $relationship = $relationshipManager->getRelationship($relationshipName);

        if (!$relationship) {
            throw new \Exception("Relationship '{$relationshipName}' not defined");
        }

        // Collect all primary key values from source models
        $primaryKeys = [];
        foreach ($sourceModels as $model) {
            $primaryKeyValue = $model->{$relationship->getPrimaryKey()};
            if ($primaryKeyValue !== null) {
                $primaryKeys[] = $primaryKeyValue;
            }
        }

        if (empty($primaryKeys)) {
            return [];
        }

        // Remove duplicates and prepare for IN clause
        $primaryKeys = array_values(array_unique($primaryKeys));

        // Create an instance of the related model to get its mapper
        $relatedClass = $relationship->getRelatedModelClass();
        $relatedInstance = new $relatedClass($firstModel->getPdo());
        $mapper = $relatedInstance->_mapper;

        // Build the complex JOIN query through the pivot table
        $placeholders = str_repeat('?,', count($primaryKeys) - 1) . '?';

        // Handle field selection (basic implementation for now)
        $selectClause = 'r.*';
        if ($fieldSelection && !empty($fieldSelection)) {
            // For now, still select all fields to avoid parameter binding issues
            // Field selection optimization will be implemented in Phase 3
            $selectClause = 'r.*';
        }

        $sql = "SELECT {$selectClause}, j.`{$relationship->getJoinForeignKey()}` as source_id
                FROM `{$mapper->table}` r
                INNER JOIN `{$relationship->getJoinTable()}` j ON r.`{$relationship->getPrimaryKey()}` = j.`{$relationship->getJoinRelatedKey()}`
                WHERE j.`{$relationship->getJoinForeignKey()}` IN ({$placeholders})";

        // Execute the batch query
        $result = $mapper->query($sql, $primaryKeys);

        // Group results by source primary key
        $groupedResults = [];
        while ($data = $result->fetch(\PDO::FETCH_ASSOC)) {
            $sourceId = $data['source_id'];

            // Remove the source_id from the data before creating the model
            unset($data['source_id']);

            // Create related model instance
            $relatedModel = new $relatedClass($firstModel->getPdo());
            $relatedModel->_mapper->readArray($relatedModel, $data);

            // Group by source primary key
            if (!isset($groupedResults[$sourceId])) {
                $groupedResults[$sourceId] = [];
            }
            $groupedResults[$sourceId][] = $relatedModel;
        }

        return $groupedResults;
    }

    /**
     * Distribute batch-loaded results to their corresponding source models
     *
     * @param array $sourceModels Array of model instances to receive the loaded data
     * @param array $batchResults Results from batchLoad(), keyed by source model primary key
     * @param string $relationshipName Name of the relationship being distributed
     * @return void
     */
    public function distributeBatchResults(array $sourceModels, array $batchResults, string $relationshipName): void
    {
        // Handle empty source models
        if (empty($sourceModels)) {
            return;
        }

        // Get relationship definition to access primary key
        $firstModel = reset($sourceModels);
        $relationshipManager = $firstModel->getRelationshipManager();
        $relationship = $relationshipManager->getRelationship($relationshipName);

        if (!$relationship) {
            return; // Relationship not found, skip distribution
        }

        foreach ($sourceModels as $model) {
            $primaryKeyValue = $model->{$relationship->getPrimaryKey()};

            // Assign the related models array to the model property
            if (isset($batchResults[$primaryKeyValue])) {
                $model->{$relationshipName} = $batchResults[$primaryKeyValue];
            } else {
                // No related models found - assign empty array
                $model->{$relationshipName} = [];
            }
        }
    }

    /**
     * Estimate the number of queries this batch loader would execute
     *
     * @param int $sourceCount Number of source models
     * @return int Number of queries (always 1 for batch loading)
     */
    public function estimateQueryCount(int $sourceCount): int
    {
        return $sourceCount > 0 ? 1 : 0;
    }

    /**
     * Check if this batch loader can handle the given relationship
     *
     * @param object $relationship The relationship to check
     * @return bool True if this loader can handle the relationship
     */
    public function canHandle($relationship): bool
    {
        return $relationship->getType() === 'manyHasMany';
    }

    /**
     * Get the maximum recommended batch size for this loader
     *
     * @return int Maximum number of source models to process in one batch
     */
    public function getMaxBatchSize(): int
    {
        // More conservative limit for many-to-many due to potential result explosion
        return 500;
    }

    /**
     * Estimate the complexity of the many-to-many relationship
     *
     * @param array $sourceModels Source models to analyze
     * @param string $relationshipName Name of the relationship
     * @return array Complexity metrics
     */
    public function estimateComplexity(array $sourceModels, string $relationshipName): array
    {
        $sourceCount = count($sourceModels);

        // Estimate based on typical many-to-many patterns
        $estimatedRelatedPerSource = 3; // Conservative estimate
        $estimatedTotalRelated = $sourceCount * $estimatedRelatedPerSource;

        return [
            'source_count' => $sourceCount,
            'estimated_related_per_source' => $estimatedRelatedPerSource,
            'estimated_total_related' => $estimatedTotalRelated,
            'complexity_score' => min($estimatedTotalRelated / 100, 10), // Scale 0-10
            'recommended_batch_size' => min($this->getMaxBatchSize(), max(50, 1000 / $estimatedRelatedPerSource))
        ];
    }
}
