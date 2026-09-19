<?php

// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

namespace Anorm;

use Anorm\Relationship\RelationshipManager;
use Anorm\Schema\ForeignKeyWriter;

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

    /** @var array<string, mixed>|null Snapshot of values as last seen in the database. */
    public $_lastSnapshot = null;

    public function __construct(\PDO $pdo, DataMapper $mapper)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->_mapper = $mapper;
        $this->_pdo = $pdo;
        $this->_relationshipManager = new RelationshipManager($this, $pdo);
    }

    /**
     * The DataMapper that owns this model's table, column map and transformers.
     * Prefer this to the public $_mapper property, which stays for backward
     * compatibility but is, by the underscore convention, infrastructure.
     * @return DataMapper
     */
    public function mapper(): DataMapper
    {
        return $this->_mapper;
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
     * @param int|string $id The primary key id of the model to read.
     * @return bool Returns false if not found.
     */
    public function read($id)
    {
        return $this->_mapper->read($this, $id);
    }

    /**
     * @param int|string $id The primary key id of the model to read.
     * @return bool Returns true if found, throws \Exception if not found.
     * @throws \Exception if not found.
     */
    public function readOrThrow($id)
    {
        $result = $this->_mapper->read($this, $id);
        if (!$result) {
            throw new \Exception($this->modelLabel() . " id '$id' not found");
        }
        return $result;
    }

    /**
     * Delete the row this model identifies.
     * The primary key property must be set — either read from the database or
     * assigned directly. Passing the model to the mapper is what lets a
     * DeleteListenerInterface see which model went.
     * @return bool True when a row was deleted, false when no row matched.
     * @throws \Exception if the primary key property is not set.
     */
    public function delete()
    {
        $key = $this->_mapper->modelPrimaryKey;
        $id = isset($this->$key) ? $this->$key : null;
        if ($id === null || $id === '') {
            throw new \Exception($this->modelLabel() . " cannot be deleted: primary key '$key' is not set");
        }
        return $this->_mapper->delete($id, $this);
    }

    /**
     * Delete the row this model identifies, or throw if there was no such row.
     * The counterpart to readOrThrow.
     * @return bool Always true.
     * @throws \Exception if the primary key is not set, or no row was deleted.
     */
    public function deleteOrThrow()
    {
        $key = $this->_mapper->modelPrimaryKey;
        $id = isset($this->$key) ? $this->$key : null;
        $result = $this->delete();
        if (!$result) {
            throw new \Exception($this->modelLabel() . " id '$id' not deleted");
        }
        return $result;
    }

    /**
     * Short name for exception messages: 'Model' removed and the namespace
     * stripped, e.g. Anorm\Test\SomeTableModel -> SomeTable.
     * @return string
     */
    private function modelLabel(): string
    {
        $className = str_replace('Model', '', get_class($this));
        $tokens = explode('\\', $className);
        return end($tokens); // The last element: the class name without its namespace.
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
    protected function hasManyThrough(
        $relatedModelClass,
        $joinForeignKey,
        $joinRelatedKey,
        $joinTable,
        $primaryKey = 'id',
        $propertyName = null,
        $options = []
    ) {
        // Use explicit property name or generate from class name
        if ($propertyName === null) {
            $propertyName = $this->getPropertyNameFromClass($relatedModelClass);
        }
        $this->_relationshipManager->hasManyThrough(
            $relatedModelClass,
            $propertyName,
            $joinForeignKey,
            $joinRelatedKey,
            $joinTable,
            $primaryKey,
            $options
        );
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
     *
     * @param \Anorm\Relationship\Relationship $relationship
     * @return void
     */
    private function createForeignKeyFromRelationship($relationship)
    {
        $this->foreignKeyWriter()->createFromRelationship($this->_mapper, $relationship);
    }

    /**
     * @return ForeignKeyWriter The one implementation of constraint creation, shared
     *                          with TableMaker so the two cannot disagree again.
     */
    private function foreignKeyWriter()
    {
        return new ForeignKeyWriter($this->_pdo);
    }
}
