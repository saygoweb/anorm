<?php

namespace Anorm\Relationship;

use Anorm\DataMapper;

/**
 * Many-to-Many relationship
 * This model has many instances of another model through a join table
 */
class ManyHasMany extends Relationship
{
    /** @var string The join table name */
    protected $joinTable;
    
    /** @var string The foreign key in the join table for this model */
    protected $joinForeignKey;
    
    /** @var string The foreign key in the join table for the related model */
    protected $joinRelatedKey;

    public function __construct($relatedModelClass, $propertyName, $joinForeignKey, $joinRelatedKey, $joinTable, $primaryKey = 'id', $options = [])
    {
        parent::__construct($relatedModelClass, $propertyName, $joinForeignKey, $primaryKey, $options);
        $this->joinTable = $joinTable;
        $this->joinForeignKey = $joinForeignKey;
        $this->joinRelatedKey = $joinRelatedKey;
    }

    /**
     * Get the relationship type
     */
    public function getType()
    {
        return 'manyHasMany';
    }

    /**
     * Get the join table name
     */
    public function getJoinTable()
    {
        return $this->joinTable;
    }

    /**
     * Get the foreign key in the join table for this model
     */
    public function getJoinForeignKey()
    {
        return $this->joinForeignKey;
    }

    /**
     * Get the foreign key in the join table for the related model
     */
    public function getJoinRelatedKey()
    {
        return $this->joinRelatedKey;
    }

    /**
     * Load the related models for a many-to-many relationship
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
        
        // Build the query to find related models through the join table
        // Use database column names directly
        $sql = "SELECT r.* FROM `{$mapper->table}` r
                INNER JOIN `{$this->joinTable}` j ON r.`{$this->primaryKey}` = j.`{$this->joinRelatedKey}`
                WHERE j.`{$this->joinForeignKey}` = ?";

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
     * Generate JOIN clause for many-to-many relationship
     *
     * @param string $sourceTable The source table name
     * @param string $relatedTable The related table name
     * @return string The JOIN clause
     */
    public function generateJoinClause($sourceTable, $relatedTable)
    {
        return "LEFT JOIN `{$this->joinTable}` ON `{$sourceTable}`.`{$this->primaryKey}` = `{$this->joinTable}`.`{$this->joinForeignKey}` " .
               "LEFT JOIN `{$relatedTable}` ON `{$this->joinTable}`.`{$this->joinRelatedKey}` = `{$relatedTable}`.`{$this->primaryKey}`";
    }

    /**
     * Generate foreign key constraint SQL for ManyHasMany relationship
     * Creates foreign keys on the join table pointing to both source and target tables
     */
    public function generateForeignKeyConstraints($sourceTable)
    {
        $targetTable = $this->getTableNameFromModelClass($this->relatedModelClass);
        $constraints = [];

        // Foreign key from join table to source table
        $sourceConstraintName = "fk_{$this->joinTable}_{$this->joinForeignKey}";
        $onDelete = $this->constraintOptions['on_delete'];
        $onUpdate = $this->constraintOptions['on_update'];

        $constraints[] = "ALTER TABLE `{$this->joinTable}`
                         ADD CONSTRAINT `{$sourceConstraintName}`
                         FOREIGN KEY (`{$this->joinForeignKey}`)
                         REFERENCES `{$sourceTable}`(`{$this->primaryKey}`)
                         ON DELETE {$onDelete}
                         ON UPDATE {$onUpdate}";

        // Foreign key from join table to target table
        $targetConstraintName = "fk_{$this->joinTable}_{$this->joinRelatedKey}";

        $constraints[] = "ALTER TABLE `{$this->joinTable}`
                         ADD CONSTRAINT `{$targetConstraintName}`
                         FOREIGN KEY (`{$this->joinRelatedKey}`)
                         REFERENCES `{$targetTable}`(`{$this->primaryKey}`)
                         ON DELETE {$onDelete}
                         ON UPDATE {$onUpdate}";

        return $constraints;
    }

    /**
     * Generate SQL to create the join table if it doesn't exist
     */
    public function generateJoinTableSQL($sourceTable)
    {
        $targetTable = $this->getTableNameFromModelClass($this->relatedModelClass);

        $sql = "CREATE TABLE IF NOT EXISTS `{$this->joinTable}` (
                    `{$this->joinForeignKey}` INT(11) NOT NULL,
                    `{$this->joinRelatedKey}` INT(11) NOT NULL,
                    PRIMARY KEY (`{$this->joinForeignKey}`, `{$this->joinRelatedKey}`),
                    INDEX `idx_{$this->joinTable}_{$this->joinForeignKey}` (`{$this->joinForeignKey}`),
                    INDEX `idx_{$this->joinTable}_{$this->joinRelatedKey}` (`{$this->joinRelatedKey}`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        return $sql;
    }
}
