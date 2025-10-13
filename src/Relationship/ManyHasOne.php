<?php

namespace Anorm\Relationship;

use Anorm\DataMapper;

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
}
