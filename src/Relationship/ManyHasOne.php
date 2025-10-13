<?php

namespace Anorm\Relationship;

use Anorm\DataMapper;
use Anorm\Relationship\BatchLoader\ManyHasOneBatchLoader;

/**
 * Many-to-One relationship (belongs to)
 * This model belongs to one instance of another model
 */
class ManyHasOne extends Relationship
{
    /**
     * Get the relationship type
     */
    public function getType()
    {
        return 'manyHasOne';
    }

    /**
     * Load the related model for a many-to-one relationship
     * 
     * @param object $sourceModel The model instance that owns the relationship
     * @param \PDO $pdo The database connection
     * @return object|null The related model instance or null if not found
     */
    public function load($sourceModel, \PDO $pdo)
    {
        $relatedClass = $this->relatedModelClass;
        $foreignValue = $sourceModel->{$this->foreignKey};
        
        if ($foreignValue === null) {
            return null;
        }

        // Create an instance of the related model to get its mapper
        $relatedInstance = new $relatedClass($pdo);
        $mapper = $relatedInstance->_mapper;
        
        // Build the query to find the related model
        // The primary key should be a database column name, not a property name
        $sql = "SELECT * FROM `{$mapper->table}` WHERE `{$this->primaryKey}` = ?";

        $result = $mapper->query($sql, [$foreignValue]);
        $data = $result->fetch(\PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        $relatedModel = new $relatedClass($pdo);
        $relatedModel->_mapper->readArray($relatedModel, $data);
        
        return $relatedModel;
    }

    /**
     * Generate JOIN clause for many-to-one relationship
     *
     * @param string $sourceTable The source table name
     * @param string $relatedTable The related table name
     * @return string The JOIN clause
     */
    public function generateJoinClause($sourceTable, $relatedTable)
    {
        return "LEFT JOIN `{$relatedTable}` ON `{$sourceTable}`.`{$this->foreignKey}` = `{$relatedTable}`.`{$this->primaryKey}`";
    }

    /**
     * Generate foreign key constraint SQL for ManyHasOne relationship
     * Creates foreign key on the source table pointing to related table
     */
    public function generateForeignKeyConstraints($sourceTable)
    {
        $targetTable = $this->getTableNameFromModelClass($this->relatedModelClass);
        $constraintName = $this->getConstraintName($sourceTable, $targetTable);
        $onDelete = $this->constraintOptions['on_delete'];
        $onUpdate = $this->constraintOptions['on_update'];

        $sql = "ALTER TABLE `{$sourceTable}`
                ADD CONSTRAINT `{$constraintName}`
                FOREIGN KEY (`{$this->foreignKey}`)
                REFERENCES `{$targetTable}`(`{$this->primaryKey}`)
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
        $batchLoader = new ManyHasOneBatchLoader();
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
        $batchLoader = new ManyHasOneBatchLoader();
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
        // Estimate based on many-to-one cardinality (1 related record per source)
        $avgRecordSize = 1024; // 1KB per record estimate

        if ($fieldSelection !== null && !empty($fieldSelection)) {
            // Estimate size for selected fields only
            $avgRecordSize = count($fieldSelection) * 50; // 50 bytes per field estimate
        }

        return (int)($sourceCount * $avgRecordSize);
    }

    /**
     * Get the cardinality type of this relationship
     *
     * @return string One of: 'one-to-one', 'one-to-many', 'many-to-one', 'many-to-many'
     */
    public function getCardinality(): string
    {
        return 'many-to-one';
    }
}
