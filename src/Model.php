<?php

// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

namespace Anorm;

use Anorm\Relationship\RelationshipManager;

class Model
{
    /** @var DataMapper */
    public $_mapper;

    /** @var RelationshipManager */
    public $_relationshipManager;

    /** @var \PDO */
    protected $_pdo;

    /** @var array|null Fields that have been loaded (for partial loading) */
    private $_loadedFields = null;

    public function __construct(\PDO $pdo, DataMapper $mapper)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->_mapper = $mapper;
        $this->_pdo = $pdo;
        $this->_relationshipManager = new RelationshipManager($this, $pdo);
    }

    /**
     * Get the PDO connection
     * @return \PDO
     */
    public function getPdo(): \PDO
    {
        return $this->_pdo;
    }

    /**
     * Set which fields have been loaded (for partial loading)
     * @param array|null $fields Array of field names that were loaded, or null to reset
     * @return void
     */
    public function setLoadedFields(?array $fields): void
    {
        $this->_loadedFields = $fields;
    }

    /**
     * Check if a specific field has been loaded
     * @param mixed $fieldName Name of the field to check
     * @return bool True if field is loaded, false otherwise
     */
    public function isFieldLoaded($fieldName): bool
    {
        // If no partial loading is active, all fields are considered loaded
        if ($this->_loadedFields === null) {
            return true;
        }

        return in_array($fieldName, $this->_loadedFields, true);
    }

    /**
     * Get the list of loaded fields
     * @return array|null Array of loaded field names, or null if all fields are loaded
     */
    public function getLoadedFields(): ?array
    {
        return $this->_loadedFields;
    }

    /**
     * Check if this model is partially loaded
     * @return bool True if only specific fields were loaded
     */
    public function isPartiallyLoaded(): bool
    {
        return $this->_loadedFields !== null;
    }

    /**
     * @return int The primary key id of the model.
     */
    public function write()
    {
        // In dynamic mode, ensure foreign key constraints are created before writing
        if ($this->_mapper->mode === DataMapper::MODE_DYNAMIC) {
            $this->createForeignKeyConstraints();
        }

        return $this->_mapper->write($this);
    }

    /**
     * @param int $id The primary key id of the model to read.
     * @return bool Returns false if not found.
     */
    public function read($id)
    {
        return $this->_mapper->read($this, $id);
    }

    /**
     * @param int $id The primary key id of the model to read.
     * @return bool Returns true if found, throws \Exception if not found.
     * @throws \Exception if not found.
     */
    public function readOrThrow($id)
    {
        $result = $this->_mapper->read($this, $id);
        if (!$result) {
            $className = get_class($this);
            $className = str_replace('Model', '', $className);
            $tokens = explode('\\', $className);
            if ($tokens && count($tokens) > 0) {
                $className = $tokens[count($tokens) - 1];
            }
            throw new \Exception("$className id '$id' not found");
        }
        return $result;
    }

    /**
     * Define a one-to-many relationship
     * This model has many instances of another model
     *
     * @param string $relatedModelClass The class name of the related model
     * @param string $foreignKey The foreign key column in the related table
     * @param string $primaryKey The primary key column in this table (default: 'id')
     * @param string|null $propertyName The property name to store the relationship (auto-generated if null)
     * @param array $options Additional options including constraint options
     *   - constraints: array of foreign key constraint options
     *     - on_delete: 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' (default: 'RESTRICT')
     *     - on_update: 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' (default: 'CASCADE')
     *     - constraint_name: custom constraint name (auto-generated if not provided)
     */
    protected function hasMany($relatedModelClass, $foreignKey, $primaryKey = 'id', $propertyName = null, $options = [])
    {
        // Use explicit property name or generate from class name
        if ($propertyName === null) {
            $propertyName = $this->getPropertyNameFromClass($relatedModelClass);
        }

        $this->_relationshipManager->hasMany($relatedModelClass, $propertyName, $foreignKey, $primaryKey, $options);
    }

    /**
     * Define a many-to-one relationship (belongs to)
     * This model belongs to one instance of another model
     *
     * @param string $relatedModelClass The class name of the related model
     * @param string $foreignKey The foreign key column in this table
     * @param string $primaryKey The primary key column in the related table (default: 'id')
     * @param string|null $propertyName The property name to store the relationship (auto-generated if null)
     * @param array $options Additional options including constraint options
     *   - constraints: array of foreign key constraint options
     *     - on_delete: 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' (default: 'RESTRICT')
     *     - on_update: 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' (default: 'CASCADE')
     *     - constraint_name: custom constraint name (auto-generated if not provided)
     */
    protected function belongsTo($relatedModelClass, $foreignKey, $primaryKey = 'id', $propertyName = null, $options = [])
    {
        // Use explicit property name or generate from class name
        if ($propertyName === null) {
            $propertyName = $this->getPropertyNameFromClass($relatedModelClass, true);
        }
        $this->_relationshipManager->belongsTo($relatedModelClass, $propertyName, $foreignKey, $primaryKey, $options);
    }

    /**
     * Define a many-to-many relationship
     * This model has many instances of another model through a join table
     *
     * @param string $relatedModelClass The class name of the related model
     * @param string $joinForeignKey The foreign key column in the join table pointing to this table
     * @param string $joinRelatedKey The foreign key column in the join table pointing to the related table
     * @param string $joinTable The name of the join table
     * @param string $primaryKey The primary key column in this table (default: 'id')
     * @param string|null $propertyName The property name to store the relationship (auto-generated if null)
     * @param array $options Additional options including constraint options
     *   - constraints: array of foreign key constraint options
     *     - on_delete: 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' (default: 'RESTRICT')
     *     - on_update: 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION' (default: 'CASCADE')
     *     - constraint_name: custom constraint name (auto-generated if not provided)
     */
    protected function hasManyThrough($relatedModelClass, $joinForeignKey, $joinRelatedKey, $joinTable, $primaryKey = 'id', $propertyName = null, $options = [])
    {
        // Use explicit property name or generate from class name
        if ($propertyName === null) {
            $propertyName = $this->getPropertyNameFromClass($relatedModelClass);
        }
        $this->_relationshipManager->hasManyThrough($relatedModelClass, $propertyName, $joinForeignKey, $joinRelatedKey, $joinTable, $primaryKey, $options);
    }

    /**
     * Load a specific relationship
     */
    public function loadRelated($relationshipName)
    {
        return $this->_relationshipManager->loadRelated($relationshipName);
    }

    /**
     * Load all defined relationships
     */
    public function loadAllRelated()
    {
        $this->_relationshipManager->loadAllRelated();
    }

    /**
     * Get the relationship manager
     */
    public function getRelationshipManager()
    {
        return $this->_relationshipManager;
    }

    /**
     * Generate a property name from a model class name
     * @param string $className The model class name
     * @param bool $singular Whether to use singular form (for belongsTo)
     * @return string The property name
     */
    private function getPropertyNameFromClass($className, $singular = false)
    {
        // Remove namespace and 'Model' suffix
        $parts = explode('\\', $className);
        $shortName = end($parts);
        $shortName = str_replace('Model', '', $shortName);

        // Convert to camelCase
        $propertyName = lcfirst($shortName);

        // For hasMany relationships, make it plural (simple approach)
        if (!$singular) {
            $propertyName .= 's';
        }

        return $propertyName;
    }

    /**
     * Create constraint options for CASCADE delete behavior
     * Convenience method for common constraint configuration
     */
    protected function cascadeDelete()
    {
        return [
            'constraints' => [
                'on_delete' => 'CASCADE',
                'on_update' => 'CASCADE'
            ]
        ];
    }

    /**
     * Create constraint options for SET NULL delete behavior
     * Convenience method for common constraint configuration
     */
    protected function setNullDelete()
    {
        return [
            'constraints' => [
                'on_delete' => 'SET NULL',
                'on_update' => 'CASCADE'
            ]
        ];
    }

    /**
     * Create constraint options for RESTRICT delete behavior (default)
     * Convenience method for common constraint configuration
     */
    protected function restrictDelete()
    {
        return [
            'constraints' => [
                'on_delete' => 'RESTRICT',
                'on_update' => 'CASCADE'
            ]
        ];
    }

    /**
     * Create custom constraint options
     *
     * @param string $onDelete DELETE action: 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'
     * @param string $onUpdate UPDATE action: 'RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'
     * @param string|null $constraintName Custom constraint name
     */
    protected function constraintOptions($onDelete = 'RESTRICT', $onUpdate = 'CASCADE', $constraintName = null)
    {
        $options = [
            'constraints' => [
                'on_delete' => $onDelete,
                'on_update' => $onUpdate
            ]
        ];

        if ($constraintName) {
            $options['constraints']['constraint_name'] = $constraintName;
        }

        return $options;
    }

    /**
     * Create foreign key constraints for all defined relationships
     * This method can be called explicitly to ensure foreign keys are created
     * when in dynamic mode
     */
    public function createForeignKeyConstraints()
    {
        if ($this->_mapper->mode !== DataMapper::MODE_DYNAMIC) {
            return;
        }

        $relationships = $this->_relationshipManager->getRelationships();

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

        if ($type === 'manyHasOne') {
            // For belongsTo relationships, create foreign key on current table
            $this->createForeignKey(
                $this->_mapper->table,
                $relationship->getForeignKey(),
                $this->getTableNameFromModelClass($relationship->getRelatedModelClass()),
                $relationship->getPrimaryKey(),
                $relationship->getConstraintOptions()
            );
        } elseif ($type === 'oneHasMany') {
            // For hasMany relationships, create foreign key on related table
            $this->createForeignKey(
                $this->getTableNameFromModelClass($relationship->getRelatedModelClass()),
                $relationship->getForeignKey(),
                $this->_mapper->table,
                $relationship->getPrimaryKey(),
                $relationship->getConstraintOptions()
            );
        } elseif ($type === 'manyHasMany') {
            // For many-to-many relationships, create join table and foreign keys
            $this->createManyToManyConstraints($relationship);
        }
    }

    /**
     * Create join table and foreign key constraints for many-to-many relationships
     */
    private function createManyToManyConstraints($relationship)
    {
        // Get join table information
        $joinTable = $relationship->getJoinTable();
        $joinForeignKey = $relationship->getJoinForeignKey();
        $joinRelatedKey = $relationship->getJoinRelatedKey();
        $sourceTable = $this->_mapper->table;
        $targetTable = $this->getTableNameFromModelClass($relationship->getRelatedModelClass());

        // Create join table with proper columns
        $this->createJoinTable($joinTable, $joinForeignKey, $joinRelatedKey);

        // Create foreign key constraints on join table
        $this->createForeignKey(
            $joinTable,
            $joinForeignKey,
            $sourceTable,
            $relationship->getPrimaryKey(),
            $relationship->getConstraintOptions()
        );

        $this->createForeignKey(
            $joinTable,
            $joinRelatedKey,
            $targetTable,
            $relationship->getPrimaryKey(),
            $relationship->getConstraintOptions()
        );
    }

    /**
     * Create a join table for many-to-many relationships
     */
    private function createJoinTable($joinTable, $joinForeignKey, $joinRelatedKey)
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$joinTable}` (
                    `{$joinForeignKey}` INT(11) NOT NULL,
                    `{$joinRelatedKey}` INT(11) NOT NULL,
                    PRIMARY KEY (`{$joinForeignKey}`, `{$joinRelatedKey}`),
                    INDEX `idx_{$joinTable}_{$joinForeignKey}` (`{$joinForeignKey}`),
                    INDEX `idx_{$joinTable}_{$joinRelatedKey}` (`{$joinRelatedKey}`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->_pdo->query($sql);
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
            $this->_pdo->query($sql);
        } catch (\PDOException $e) {
            // If foreign key creation fails, log the error but don't throw
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

        $stmt = $this->_pdo->prepare($sql);
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
        $this->_pdo->query($sql);
    }

    /**
     * Ensure a column exists in a table, create it if it doesn't
     */
    private function ensureColumnExists($tableName, $columnName)
    {
        // First ensure the table exists
        $this->ensureTableExists($tableName);

        // Check if column exists
        $sql = "SELECT COUNT(*) as count
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?";

        $stmt = $this->_pdo->prepare($sql);
        $stmt->execute([$tableName, $columnName]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result['count'] == 0) {
            // Column doesn't exist, create it
            $columnDefinition = "INT(11) NULL"; // Foreign key columns are typically INT(11)
            $sql = "ALTER TABLE `{$tableName}` ADD `{$columnName}` {$columnDefinition}";
            $this->_pdo->query($sql);
        }
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

        // Better pluralization rules
        if (substr($tableName, -1) === 'y') {
            $tableName = substr($tableName, 0, -1) . 'ies';
        } elseif (substr($tableName, -1) !== 's') {
            $tableName .= 's';
        }

        return $tableName;
    }
}
