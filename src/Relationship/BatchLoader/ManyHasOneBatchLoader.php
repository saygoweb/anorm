<?php

namespace Anorm\Relationship\BatchLoader;

/**
 * Batch loader for Many-to-One relationships (belongsTo)
 *
 * Optimizes loading of belongsTo relationships by collecting all foreign keys
 * and executing a single IN clause query instead of N individual queries.
 */
class ManyHasOneBatchLoader implements BatchLoaderInterface
{
    /**
     * Load many-to-one relationships for multiple source models in a single batch
     *
     * @param array $sourceModels Array of model instances that need relationships loaded
     * @param string $relationshipName Name of the relationship to load
     * @param array|null $fieldSelection Optional field selection for optimization
     * @return array Associative array of loaded relationship data, keyed by related model primary key
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

        // Collect all foreign key values from source models
        $foreignKeys = [];
        foreach ($sourceModels as $model) {
            $foreignKeyValue = $model->{$relationship->getForeignKey()};
            if ($foreignKeyValue !== null) {
                $foreignKeys[] = $foreignKeyValue;
            }
        }

        if (empty($foreignKeys)) {
            return [];
        }

        // Remove duplicates and prepare for IN clause
        $foreignKeys = array_values(array_unique($foreignKeys));

        // Create an instance of the related model to get its mapper
        $relatedClass = $relationship->getRelatedModelClass();
        $relatedInstance = new $relatedClass($firstModel->getPdo());
        $mapper = $relatedInstance->_mapper;

        // Build the batch query using IN clause
        $placeholders = str_repeat('?,', count($foreignKeys) - 1) . '?';

        // Handle field selection (basic implementation for now)
        $selectClause = '*';
        if ($fieldSelection && !empty($fieldSelection)) {
            // For now, still select all fields to avoid parameter binding issues
            // Field selection optimization will be implemented in Phase 3
            $selectClause = '*';
        }

        $sql = "SELECT {$selectClause} FROM `{$mapper->table}` WHERE `{$relationship->getPrimaryKey()}` IN ({$placeholders})";



        // Execute the batch query
        $result = $mapper->query($sql, $foreignKeys);

        // Create lookup map by primary key value
        $lookupMap = [];
        while ($data = $result->fetch(\PDO::FETCH_ASSOC)) {
            $primaryKeyValue = $data[$relationship->getPrimaryKey()];

            // Create related model instance
            $relatedModel = new $relatedClass($firstModel->getPdo());
            $relatedModel->_mapper->readArray($relatedModel, $data);

            // Store in lookup map by primary key
            $lookupMap[$primaryKeyValue] = $relatedModel;
        }

        return $lookupMap;
    }

    /**
     * Distribute batch-loaded results to their corresponding source models
     *
     * @param array $sourceModels Array of model instances to receive the loaded data
     * @param array $batchResults Results from batchLoad(), keyed by related model primary key
     * @param string $relationshipName Name of the relationship being distributed
     * @return void
     */
    public function distributeBatchResults(array $sourceModels, array $batchResults, string $relationshipName): void
    {
        // Get relationship definition to access foreign key
        $firstModel = reset($sourceModels);
        $relationshipManager = $firstModel->getRelationshipManager();
        $relationship = $relationshipManager->getRelationship($relationshipName);

        if (!$relationship) {
            return; // Relationship not found, skip distribution
        }

        foreach ($sourceModels as $model) {
            $foreignKeyValue = $model->{$relationship->getForeignKey()};

            // Assign the related model to the model property
            if ($foreignKeyValue !== null && isset($batchResults[$foreignKeyValue])) {
                $model->{$relationshipName} = $batchResults[$foreignKeyValue];
            } else {
                // No related model found - assign null
                $model->{$relationshipName} = null;
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
        return $relationship->getType() === 'manyHasOne';
    }

    /**
     * Get the maximum recommended batch size for this loader
     *
     * @return int Maximum number of source models to process in one batch
     */
    public function getMaxBatchSize(): int
    {
        // Conservative limit to avoid hitting database IN clause limits
        return 1000;
    }

    /**
     * Get statistics about the batch loading operation
     *
     * @param array $sourceModels Source models that were processed
     * @param array $batchResults Results that were loaded
     * @return array Statistics about the operation
     */
    public function getBatchStatistics(array $sourceModels, array $batchResults): array
    {
        $uniqueForeignKeys = [];
        foreach ($sourceModels as $model) {
            $firstModel = reset($sourceModels);
            $relationshipManager = $firstModel->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship(''); // This would need the relationship name

            if ($relationship) {
                $foreignKeyValue = $model->{$relationship->getForeignKey()};
                if ($foreignKeyValue !== null) {
                    $uniqueForeignKeys[$foreignKeyValue] = true;
                }
            }
        }

        return [
            'source_models' => count($sourceModels),
            'unique_foreign_keys' => count($uniqueForeignKeys),
            'loaded_models' => count($batchResults),
            'cache_hit_ratio' => count($uniqueForeignKeys) > 0 ? count($batchResults) / count($uniqueForeignKeys) : 0,
        ];
    }
}
