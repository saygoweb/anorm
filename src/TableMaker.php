<?php

namespace Anorm;

class TableMaker
{
    public static function fix(\Exception $exception, DataMapper $mapper, $model = null)
    {
        // TODO We could create this from an Anorm factory / container
        $maker = new TableMaker($exception, $mapper, $model);
        return $maker->_fix();
    }

    /** @var \PDOException The exception that requires the database schema to be fixed. */
    public $exception;

    /** @var DataMapper The DataMapper */
    private $mapper;

    /** @var Model An optional model instance */
    private $model;

    public function __construct(\Exception $exception, DataMapper $mapper, $model)
    {
        $this->exception = $exception;
        $this->mapper = $mapper;
        $this->model = $model;
    }

    private function _fix()
    {
        switch ($this->exception->getCode()) {
            case '42S02': // table not found
                $this->createTable();
                break;
            case '42S22': // column not found
                $this->createColumn();
                break;
            case '23000': // integrity constraint violation (foreign key)
                $this->handleForeignKeyConstraint();
                break;
            case 'HY000': // general error (can include foreign key issues)
                if (strpos($this->exception->getMessage(), 'foreign key constraint') !== false) {
                    $this->handleForeignKeyConstraint();
                } else {
                    throw $this->exception;
                }
                break;
        }
    }

    private function createTable()
    {
        // Regex the message to get the name of the table
        $matches = array();
        if (!\preg_match("/'([^\.']*)\.([^\.']*)'/", $this->exception->getMessage(), $matches)) {
            throw new \Exception('Anorm: Could not parse PDOException', 0, $this->exception);
        }
        $tableName = $matches[2];
        // Create the table with an auto increment id as primary key.
        // Review: Should we also try and create all the columns we can now,
        // or wait until possibly later when we might have better data
        // to hint the type?
        // Current design choice is to wait until later even if it means
        // a highly iterative, multiple exception approach on the common
        // first write case.
        $sql = "CREATE TABLE `$tableName`(
            id INT(11) AUTO_INCREMENT PRIMARY KEY
        )";
        $this->mapper->pdo->query($sql);

        // After creating the table, try to create foreign key constraints
        // if this model has relationships defined
        $this->createForeignKeyConstraintsFromModel();
    }

    private function createColumn()
    {
        // Regex the message to get the name of the table
        $matches = array();
        if (!\preg_match("/column '([^\.']*)'/", $this->exception->getMessage(), $matches)) {
            throw new \Exception('Anorm: Could not parse PDOException', 0, $this->exception);
        }
        $columnName = $matches[1];
        // Add the column.
        // TODO Have a go at figuring out the type if the model is available.
        $sampleData = null;
        if ($this->model) {
            // See if we can reverse map the
            $invertMap = array_flip($this->mapper->map);
            $property = $invertMap[$columnName];
            $sampleData = $this->model->$property;
        }
        $columnFn = Anorm::$columnFn; // Redundant, but can't do this Anorm::$columnFn(...)
        $columnDefinition = $columnFn($columnName, $sampleData);
        $sql = "ALTER TABLE `" . $this->mapper->table . "` ADD $columnName $columnDefinition";
        $this->mapper->pdo->query($sql);
    }

    /**
     * Handle foreign key constraint violations by creating missing foreign keys
     */
    private function handleForeignKeyConstraint()
    {
        // Check if this is a missing foreign key constraint
        if (strpos($this->exception->getMessage(), 'Cannot add or update a child row') !== false) {
            $this->createMissingForeignKeyConstraints();
        } else {
            // For other foreign key issues, try to create the constraint
            $this->createForeignKeyConstraintsFromModel();
        }
    }

    /**
     * Create missing foreign key constraints based on relationship definitions
     */
    private function createMissingForeignKeyConstraints()
    {
        if (!$this->model || !property_exists($this->model, '_relationshipManager')) {
            return;
        }

        $relationshipManager = $this->model->_relationshipManager;
        if (!$relationshipManager) {
            return;
        }

        // Get all relationships defined in the model
        $relationships = $relationshipManager->getRelationships();

        foreach ($relationships as $relationship) {
            $this->createForeignKeyFromRelationship($relationship);
        }
    }

    /**
     * Create foreign key constraints from model relationship definitions
     */
    private function createForeignKeyConstraintsFromModel()
    {
        if (!$this->model || !property_exists($this->model, '_relationshipManager')) {
            return;
        }

        $relationshipManager = $this->model->_relationshipManager;
        if (!$relationshipManager) {
            return;
        }

        // Get all relationships and create foreign keys for them
        $relationships = $relationshipManager->getRelationships();

        foreach ($relationships as $relationship) {
            $this->createForeignKeyFromRelationship($relationship);
        }
    }

    /**
     * Create a foreign key constraint from a relationship definition
     */
    private function createForeignKeyFromRelationship($relationship)
    {
        $type = $relationship->getType();

        if ($type === 'ManyHasOne') {
            // For belongsTo relationships, create foreign key on current table
            $this->createForeignKey(
                $this->mapper->table,
                $relationship->getForeignKey(),
                $this->getTableNameFromModelClass($relationship->getRelatedModelClass()),
                $relationship->getPrimaryKey(),
                $relationship->getConstraintOptions()
            );
        } elseif ($type === 'OneHasMany') {
            // For hasMany relationships, create foreign key on related table
            $this->createForeignKey(
                $this->getTableNameFromModelClass($relationship->getRelatedModelClass()),
                $relationship->getForeignKey(),
                $this->mapper->table,
                $relationship->getPrimaryKey(),
                $relationship->getConstraintOptions()
            );
        }
        // ManyHasMany relationships don't need foreign keys on main tables
        // They use join tables which should be handled separately
    }

    /**
     * Create a foreign key constraint
     */
    private function createForeignKey($table, $column, $referencedTable, $referencedColumn, $options = [])
    {
        $constraintName = $options['constraint_name'] ?? "fk_{$table}_{$column}";
        $onDelete = $options['on_delete'] ?? 'RESTRICT';
        $onUpdate = $options['on_update'] ?? 'CASCADE';

        // Check if foreign key already exists
        if ($this->foreignKeyExists($table, $constraintName)) {
            return;
        }

        // Ensure the referenced table exists
        $this->ensureTableExists($referencedTable);

        // Ensure the column exists in the source table
        $this->ensureColumnExists($table, $column);

        // Ensure the referenced column exists in the target table
        $this->ensureColumnExists($referencedTable, $referencedColumn);

        $sql = "ALTER TABLE `{$table}`
                ADD CONSTRAINT `{$constraintName}`
                FOREIGN KEY (`{$column}`)
                REFERENCES `{$referencedTable}`(`{$referencedColumn}`)
                ON DELETE {$onDelete}
                ON UPDATE {$onUpdate}";

        try {
            $this->mapper->pdo->query($sql);
        } catch (\PDOException $e) {
            // If foreign key creation fails, it might be because the constraint already exists
            // or there are data integrity issues. Log and continue.
            error_log("Anorm: Failed to create foreign key constraint: " . $e->getMessage());
        }
    }

    /**
     * Check if a foreign key constraint exists
     */
    private function foreignKeyExists($table, $constraintName)
    {
        $sql = "SELECT COUNT(*) as count
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ?
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'";

        $stmt = $this->mapper->pdo->prepare($sql);
        $stmt->execute([$table, $constraintName]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result['count'] > 0;
    }

    /**
     * Ensure a table exists, create it if it doesn't
     */
    private function ensureTableExists($tableName)
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY
        )";
        $this->mapper->pdo->query($sql);
    }

    /**
     * Ensure a column exists in a table, create it if it doesn't
     */
    private function ensureColumnExists($tableName, $columnName)
    {
        // Check if column exists
        $sql = "SELECT COUNT(*) as count
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?";

        $stmt = $this->mapper->pdo->prepare($sql);
        $stmt->execute([$tableName, $columnName]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result['count'] == 0) {
            // Column doesn't exist, create it
            $columnDefinition = $this->getColumnDefinitionForForeignKey($columnName);
            $sql = "ALTER TABLE `{$tableName}` ADD `{$columnName}` {$columnDefinition}";
            $this->mapper->pdo->query($sql);
        }
    }

    /**
     * Get appropriate column definition for foreign key columns
     */
    private function getColumnDefinitionForForeignKey($columnName)
    {
        // Foreign key columns are typically INT(11) to match primary keys
        return "INT(11) NULL";
    }

    /**
     * Get table name from model class name
     */
    private function getTableNameFromModelClass($modelClass)
    {
        // Remove namespace and 'Model' suffix, convert to snake_case
        $className = basename(str_replace('\\', '/', $modelClass));
        $className = str_replace('Model', '', $className);

        // Convert CamelCase to snake_case and pluralize
        $tableName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));

        // Simple pluralization (add 's' if doesn't end with 's')
        if (!str_ends_with($tableName, 's')) {
            $tableName .= 's';
        }

        return $tableName;
    }

    public static function columnDefinition($columnName, $sampleData)
    {
        if ($sampleData) {
            if (\is_numeric($sampleData)) {
                if (\is_integer($sampleData)) {
                    return "INT(11) NULL";
                }
                if (\is_float($sampleData)) {
                    return "DOUBLE NULL";
                }
            }
            if (is_object($sampleData) && get_class($sampleData) == 'Moment\Moment') {
                return "DATETIME NULL";
            }
            if (is_string($sampleData)) {
                if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $sampleData) === 1) {
                    return "DATETIME NULL";
                }
                if (strlen($sampleData) > 256) {
                    return "TEXT";
                }
                if (strlen($sampleData) > 128) {
                    return "VARCHAR(256)";
                }
            }
        }
        return 'VARCHAR(128)';
    }
}
