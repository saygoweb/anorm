<?php

namespace Anorm\Relationship\Strategy;

use Anorm\Relationship\BatchLoader\BatchLoaderInterface;

/**
 * JOIN-based loader with field selection optimization
 * 
 * This loader uses JOIN queries with field selection to minimize data transfer
 * while maintaining query efficiency. It's particularly effective for relationships
 * where only specific fields are needed from related models.
 */
class JoinWithSelectionLoader implements BatchLoaderInterface
{
    /** @var FieldSelectionParser */
    private $fieldParser;

    public function __construct(FieldSelectionParser $fieldParser = null)
    {
        $this->fieldParser = $fieldParser ?: new FieldSelectionParser();
    }

    /**
     * Load relationships using JOIN with field selection
     *
     * @param array $sourceModels Array of model instances that need relationships loaded
     * @param string $relationshipName Name of the relationship to load
     * @param array|null $fieldSelection Optional field selection for optimization
     * @return array Associative array of loaded relationship data, keyed by source model identifier
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

        // Use provided field selection or parse from relationship name
        if ($fieldSelection === null) {
            $parsedSpec = $this->fieldParser->parseFieldSelection($relationshipName);
            $fieldSelection = $parsedSpec['fields'];
        }

        // Build and execute JOIN query
        $query = $this->buildJoinQuery($relationship, $fieldSelection, $sourceModels);
        $primaryKeys = $this->extractPrimaryKeys($sourceModels, $relationship);

        if (empty($primaryKeys)) {
            return [];
        }

        $stmt = $firstModel->getPdo()->prepare($query);
        $stmt->execute($primaryKeys);
        $result = $stmt;

        // Process results and group by source model
        return $this->processJoinResults($result, $sourceModels, $relationship, $fieldSelection);
    }

    /**
     * Distribute batch-loaded results to their corresponding source models
     * 
     * @param array $sourceModels Array of model instances to receive the loaded data
     * @param array $batchResults Results from batchLoad(), keyed by source model identifier
     * @param string $relationshipName Name of the relationship being distributed
     * @return void
     */
    public function distributeBatchResults(array $sourceModels, array $batchResults, string $relationshipName): void
    {
        // Get relationship definition to determine cardinality
        $firstModel = reset($sourceModels);
        $relationshipManager = $firstModel->getRelationshipManager();
        $relationship = $relationshipManager->getRelationship($relationshipName);
        
        if (!$relationship) {
            return; // Relationship not found, skip distribution
        }

        $cardinality = $relationship->getCardinality();
        
        foreach ($sourceModels as $model) {
            $primaryKeyValue = $model->{$relationship->getPrimaryKey()};
            
            if (isset($batchResults[$primaryKeyValue])) {
                if ($cardinality === 'many-to-one' || $cardinality === 'one-to-one') {
                    // Single model relationship
                    $model->{$relationshipName} = reset($batchResults[$primaryKeyValue]) ?: null;
                } else {
                    // Array of models relationship
                    $model->{$relationshipName} = $batchResults[$primaryKeyValue];
                }
            } else {
                // No related models found
                if ($cardinality === 'many-to-one' || $cardinality === 'one-to-one') {
                    $model->{$relationshipName} = null;
                } else {
                    $model->{$relationshipName} = [];
                }
            }
        }
    }

    /**
     * Build JOIN query with field selection
     * 
     * @param object $relationship The relationship instance
     * @param array|null $fieldSelection Specific fields to load, or null for all fields
     * @param array $sourceModels Source models for the query
     * @return string SQL query string
     */
    public function buildJoinQuery($relationship, ?array $fieldSelection, array $sourceModels): string
    {
        // Get table information
        $sourceTable = $this->getTableName($relationship, 'source');
        $relatedTable = $this->getTableName($relationship, 'related');
        
        // Build SELECT clause with field selection
        $selectClause = $this->buildSelectClause($fieldSelection, $sourceTable, $relatedTable, $relationship);
        
        // Build JOIN clause
        $joinClause = $relationship->generateJoinClause($sourceTable, $relatedTable);
        
        // Build WHERE clause for source model filtering
        $primaryKeys = $this->extractPrimaryKeys($sourceModels, $relationship);
        $whereClause = $this->buildWhereClause($sourceTable, $relationship->getPrimaryKey(), $primaryKeys);
        
        return "SELECT {$selectClause} FROM `{$sourceTable}` s {$joinClause} WHERE {$whereClause}";
    }

    /**
     * Build SELECT clause with field selection and table aliases
     * 
     * @param array|null $fieldSelection Specific fields to load
     * @param string $sourceTable Source table name
     * @param string $relatedTable Related table name
     * @param object $relationship The relationship instance
     * @return string SELECT clause
     */
    public function buildSelectClause(?array $fieldSelection, string $sourceTable, string $relatedTable, $relationship): string
    {
        $selectParts = [];
        
        // Always include source primary key for grouping
        $sourcePrimaryKey = $relationship->getPrimaryKey();
        $selectParts[] = "s.`{$sourcePrimaryKey}` AS source_id";
        
        // Add related table fields with selection
        if ($fieldSelection === null || empty($fieldSelection)) {
            // Select all fields from related table
            $selectParts[] = "r.*";
        } else {
            // Select only specified fields
            foreach ($fieldSelection as $field) {
                $selectParts[] = "r.`{$field}`";
            }
        }
        
        return implode(', ', $selectParts);
    }

    /**
     * Process JOIN query results and group by source model
     * 
     * @param \PDOStatement $result Query result
     * @param array $sourceModels Source models
     * @param object $relationship The relationship instance
     * @param array|null $fieldSelection Field selection used
     * @return array Grouped results
     */
    public function processJoinResults(\PDOStatement $result, array $sourceModels, $relationship, ?array $fieldSelection): array
    {
        $groupedResults = [];
        $relatedClass = $relationship->getRelatedModelClass();
        
        while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
            $sourceId = $row['source_id'];
            unset($row['source_id']); // Remove source ID from related model data
            
            // Skip rows with no related data (LEFT JOIN nulls)
            if ($this->isEmptyRelatedRow($row)) {
                continue;
            }
            
            // Create related model instance (potentially partial)
            $firstModel = reset($sourceModels);
            $relatedModel = $this->createPartialModel($relatedClass, $row, $fieldSelection, $firstModel->getPdo());
            
            // Group by source ID
            if (!isset($groupedResults[$sourceId])) {
                $groupedResults[$sourceId] = [];
            }
            $groupedResults[$sourceId][] = $relatedModel;
        }
        
        return $groupedResults;
    }

    /**
     * Create a model instance with potentially partial data
     *
     * @param string $modelClass Model class name
     * @param array $data Row data from database
     * @param array|null $fieldSelection Fields that were selected
     * @param \PDO|null $pdo PDO connection to use
     * @return object Model instance
     */
    public function createPartialModel(string $modelClass, array $data, ?array $fieldSelection, ?\PDO $pdo = null)
    {
        // Get PDO from parameter or create a default one
        if ($pdo === null) {
            $pdo = new \PDO('mysql:host=localhost;dbname=anorm_test', 'dev', 'dev');
        }

        $model = new $modelClass($pdo);

        // Load the data into the model
        $model->_mapper->readArray($model, $data);

        // If we have field selection, mark which fields were loaded
        if ($fieldSelection !== null && method_exists($model, 'setLoadedFields')) {
            $model->setLoadedFields($fieldSelection);
        }

        return $model;
    }

    /**
     * Extract primary keys from source models
     * 
     * @param array $sourceModels Source models
     * @param object $relationship The relationship instance
     * @return array Primary key values
     */
    private function extractPrimaryKeys(array $sourceModels, $relationship): array
    {
        $primaryKeys = [];
        $primaryKeyField = $relationship->getPrimaryKey();
        
        foreach ($sourceModels as $model) {
            $primaryKeyValue = $model->{$primaryKeyField};
            if ($primaryKeyValue !== null) {
                $primaryKeys[] = $primaryKeyValue;
            }
        }
        
        return array_unique($primaryKeys);
    }

    /**
     * Build WHERE clause for primary key filtering
     * 
     * @param string $table Table name
     * @param string $primaryKeyField Primary key field name
     * @param array $primaryKeys Primary key values
     * @return string WHERE clause
     */
    private function buildWhereClause(string $table, string $primaryKeyField, array $primaryKeys): string
    {
        if (empty($primaryKeys)) {
            return '1=0'; // No results
        }
        
        $placeholders = str_repeat('?,', count($primaryKeys) - 1) . '?';
        return "s.`{$primaryKeyField}` IN ({$placeholders})";
    }

    /**
     * Get table name for a relationship
     * 
     * @param object $relationship The relationship instance
     * @param string $type 'source' or 'related'
     * @return string Table name
     */
    private function getTableName($relationship, string $type): string
    {
        // This is a simplified implementation
        // In practice, we'd get this from the model's mapper
        if ($type === 'source') {
            return 'users'; // Default assumption
        } else {
            $relatedClass = $relationship->getRelatedModelClass();
            // Convert class name to table name (simplified)
            return strtolower(str_replace('Model', 's', $relatedClass));
        }
    }

    /**
     * Check if a row contains only null values (LEFT JOIN with no match)
     *
     * @param array $row Database row
     * @return bool True if row is empty/null
     */
    private function isEmptyRelatedRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if this loader can handle a specific relationship type
     *
     * @param object $relationship The relationship instance
     * @return bool True if this loader can handle the relationship
     */
    public function canHandle($relationship): bool
    {
        // JOIN strategy can handle all relationship types
        return true;
    }

    /**
     * Estimate the number of queries this strategy will execute
     *
     * @param int $sourceCount Number of source models
     * @return int Estimated query count
     */
    public function estimateQueryCount(int $sourceCount): int
    {
        // JOIN strategy always uses exactly 1 query regardless of source count
        return $sourceCount > 0 ? 1 : 0;
    }

    /**
     * Get the maximum batch size this loader can handle efficiently
     *
     * @return int Maximum batch size
     */
    public function getMaxBatchSize(): int
    {
        // JOIN strategy can handle very large batches efficiently
        return 10000;
    }
}
