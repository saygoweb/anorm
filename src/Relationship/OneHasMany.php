<?php

namespace Anorm\Relationship;

use Anorm\DataMapper;

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
}
