<?php

namespace Anorm\Relationship\BatchLoader;

/**
 * Batch loader for One-to-Many relationships
 * 
 * Optimizes loading of hasMany relationships by collecting all foreign keys
 * and executing a single IN clause query instead of N individual queries.
 */
class OneHasManyBatchLoader implements BatchLoaderInterface
{
    /**
     * Load one-to-many relationships for multiple source models in a single batch
     * 
     * @param array $sourceModels Array of model instances that need relationships loaded
     * @param string $relationshipName Name of the relationship to load
     * @return array Associative array of loaded relationship data, keyed by source model primary key
     */
    public function batchLoad(array $sourceModels, string $relationshipName): array
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
        $primaryKeys = array_unique($primaryKeys);
        
        // Create an instance of the related model to get its mapper
        $relatedClass = $relationship->getRelatedModelClass();
        $relatedInstance = new $relatedClass($firstModel->_pdo);
        $mapper = $relatedInstance->_mapper;
        
        // Build the batch query using IN clause
        $placeholders = str_repeat('?,', count($primaryKeys) - 1) . '?';
        $sql = "SELECT * FROM `{$mapper->table}` WHERE `{$relationship->getForeignKey()}` IN ({$placeholders})";

        // Execute the batch query
        $result = $mapper->query($sql, $primaryKeys);
        
        // Group results by foreign key value
        $groupedResults = [];
        while ($data = $result->fetch(\PDO::FETCH_ASSOC)) {
            $foreignKeyValue = $data[$relationship->getForeignKey()];
            
            // Create related model instance
            $relatedModel = new $relatedClass($firstModel->_pdo);
            $relatedModel->_mapper->readArray($relatedModel, $data);
            
            // Group by foreign key (which corresponds to source model primary key)
            if (!isset($groupedResults[$foreignKeyValue])) {
                $groupedResults[$foreignKeyValue] = [];
            }
            $groupedResults[$foreignKeyValue][] = $relatedModel;
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
        return $relationship->getType() === 'oneHasMany';
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
}
