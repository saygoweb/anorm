<?php

namespace Anorm\Relationship;

use Anorm\DataMapper;
use Anorm\Relationship\BatchLoader\OneHasManyBatchLoader;

/**
 * One-to-Many relationship
 * This model has many instances of another model
 */
class OneHasMany extends Relationship
{
    /**
     * Get the relationship type
     */
    public function getType()
    {
        return 'oneHasMany';
    }

    /**
     * Load the related models for a one-to-many relationship
     * 
     * @param object $sourceModel The model instance that owns the relationship
     * @param \PDO $pdo The database connection
     * @return array Array of related model instances
     */
    public function load($sourceModel, \PDO $pdo)
    {
        $relatedClass = $this->relatedModelClass;
        $sourceValue = $sourceModel->{$this->primaryKey};
        
        if ($sourceValue === null) {
            return [];
        }

        // Create an instance of the related model to get its mapper
        $relatedInstance = new $relatedClass($pdo);
        $mapper = $relatedInstance->_mapper;
        
        // Build the query to find related models
        // The foreign key should be a database column name, not a property name
        $sql = "SELECT * FROM `{$mapper->table}` WHERE `{$this->foreignKey}` = ?";

        $result = $mapper->query($sql, [$sourceValue]);
        $relatedModels = [];
        
        while ($data = $result->fetch(\PDO::FETCH_ASSOC)) {
            $relatedModel = new $relatedClass($pdo);
            $relatedModel->_mapper->readArray($relatedModel, $data);
            $relatedModels[] = $relatedModel;
        }
        
        return $relatedModels;
    }

    /**
     * Generate JOIN clause for one-to-many relationship
     *
     * @param string $sourceTable The source table name
     * @param string $relatedTable The related table name
     * @return string The JOIN clause
     */
    public function generateJoinClause($sourceTable, $relatedTable)
    {
        return "LEFT JOIN `{$relatedTable}` ON `{$sourceTable}`.`{$this->primaryKey}` = `{$relatedTable}`.`{$this->foreignKey}`";
    }

    /**
     * Generate foreign key constraint SQL for OneHasMany relationship
     * Creates foreign key on the related table pointing back to source table
     */
    public function generateForeignKeyConstraints($sourceTable)
    {
        $targetTable = $this->getTableNameFromModelClass($this->relatedModelClass);
        $constraintName = $this->getConstraintName($targetTable, $sourceTable);
        $onDelete = $this->constraintOptions['on_delete'];
        $onUpdate = $this->constraintOptions['on_update'];

        $sql = "ALTER TABLE `{$targetTable}`
                ADD CONSTRAINT `{$constraintName}`
                FOREIGN KEY (`{$this->foreignKey}`)
                REFERENCES `{$sourceTable}`(`{$this->primaryKey}`)
                ON DELETE {$onDelete}
                ON UPDATE {$onUpdate}";

        return [$sql];
    }

    /**
     * Load relationships for multiple source models in a single batch operation
     *
     * @param array $sourceModels Array of model instances that need relationships loaded
     * @param \PDO $pdo Database connection
     * @return array Associative array of loaded relationship data, keyed by source model identifier
     */
    public function batchLoad(array $sourceModels, \PDO $pdo): array
    {
        $batchLoader = new OneHasManyBatchLoader();
        return $batchLoader->batchLoad($sourceModels, $this->propertyName);
    }

    /**
     * Distribute batch-loaded results to their corresponding source models
     *
     * @param array $sourceModels Array of model instances to receive the loaded data
     * @param array $batchResults Results from batchLoad(), keyed by source model identifier
     * @return void
     */
    public function distributeBatchResults(array $sourceModels, array $batchResults): void
    {
        $batchLoader = new OneHasManyBatchLoader();
        $batchLoader->distributeBatchResults($sourceModels, $batchResults, $this->propertyName);
    }

    /**
     * Estimate the data size for this relationship with given parameters
     *
     * @param int $sourceCount Number of source models
     * @param array|null $fieldSelection Specific fields to load, or null for all fields
     * @return int Estimated data size in bytes
     */
    public function estimateDataSize(int $sourceCount, ?array $fieldSelection = null): int
    {
        // Estimate based on one-to-many cardinality
        $avgRelatedRecords = 5; // Conservative estimate for hasMany
        $avgRecordSize = 1024; // 1KB per record estimate

        if ($fieldSelection !== null && !empty($fieldSelection)) {
            // Estimate size for selected fields only
            $avgRecordSize = count($fieldSelection) * 50; // 50 bytes per field estimate
        }

        return (int)($sourceCount * $avgRelatedRecords * $avgRecordSize);
    }

    /**
     * Get the cardinality type of this relationship
     *
     * @return string One of: 'one-to-one', 'one-to-many', 'many-to-one', 'many-to-many'
     */
    public function getCardinality(): string
    {
        return 'one-to-many';
    }
}
