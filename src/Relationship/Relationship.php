<?php

namespace Anorm\Relationship;

/**
 * Abstract base class for all relationship types
 * Stores relationship metadata and provides common functionality
 */
abstract class Relationship
{
    /** @var string The class name of the related model */
    protected $relatedModelClass;

    /** @var string The property name where the relationship will be stored */
    protected $propertyName;

    /** @var string The foreign key column name */
    protected $foreignKey;

    /** @var string The primary key column name */
    protected $primaryKey;

    /** @var array Additional options for the relationship */
    protected $options;

    /** @var array Foreign key constraint options */
    protected $constraintOptions;

    public function __construct($relatedModelClass, $propertyName, $foreignKey, $primaryKey = 'id', $options = [])
    {
        $this->relatedModelClass = $relatedModelClass;
        $this->propertyName = $propertyName;
        $this->foreignKey = $foreignKey;
        $this->primaryKey = $primaryKey;
        $this->options = $options;

        // Extract constraint options from general options
        $this->constraintOptions = $options['constraints'] ?? [];

        // Set default constraint options
        $this->constraintOptions = array_merge([
            'on_delete' => 'RESTRICT',
            'on_update' => 'CASCADE',
            'constraint_name' => null // Will be auto-generated if not provided
        ], $this->constraintOptions);
    }

    /**
     * Get the related model class name
     */
    public function getRelatedModelClass()
    {
        return $this->relatedModelClass;
    }

    /**
     * Get the property name where the relationship will be stored
     */
    public function getPropertyName()
    {
        return $this->propertyName;
    }

    /**
     * Get the foreign key column name
     */
    public function getForeignKey()
    {
        return $this->foreignKey;
    }

    /**
     * Get the primary key column name
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    /**
     * Get relationship options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Get a specific option value
     */
    public function getOption($key, $default = null)
    {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }

    /**
     * Get foreign key constraint options
     */
    public function getConstraintOptions()
    {
        return $this->constraintOptions;
    }

    /**
     * Get the relationship type (implemented by subclasses)
     */
    abstract public function getType();

    /**
     * Execute the relationship query and return the results
     * This method must be implemented by each relationship type
     */
    abstract public function load($sourceModel, \PDO $pdo);

    /**
     * Load relationships for multiple source models in a single batch operation
     * This method must be implemented by each relationship type for optimization
     *
     * @param array $sourceModels Array of model instances that need relationships loaded
     * @param \PDO $pdo Database connection
     * @param array|null $fieldSelection Optional field selection for optimization
     * @return array Associative array of loaded relationship data, keyed by source model identifier
     */
    abstract public function batchLoad(array $sourceModels, \PDO $pdo, ?array $fieldSelection = null): array;

    /**
     * Distribute batch-loaded results to their corresponding source models
     * This method must be implemented by each relationship type
     *
     * @param array $sourceModels Array of model instances to receive the loaded data
     * @param array $batchResults Results from batchLoad(), keyed by source model identifier
     * @return void
     */
    abstract public function distributeBatchResults(array $sourceModels, array $batchResults): void;

    /**
     * Estimate the data size for this relationship with given parameters
     * Used by strategy selection to choose optimal loading approach
     *
     * @param int $sourceCount Number of source models
     * @param array|null $fieldSelection Specific fields to load, or null for all fields
     * @return int Estimated data size in bytes
     */
    abstract public function estimateDataSize(int $sourceCount, ?array $fieldSelection = null): int;

    /**
     * Get the cardinality type of this relationship
     * Used by strategy selection for optimization decisions
     *
     * @return string One of: 'one-to-one', 'one-to-many', 'many-to-one', 'many-to-many'
     */
    abstract public function getCardinality(): string;

    /**
     * Generate the appropriate JOIN clause for this relationship
     * Used by QueryBuilder for relationship-based queries
     */
    abstract public function generateJoinClause($sourceTable, $relatedTable);

    /**
     * Generate foreign key constraint SQL for this relationship
     * Returns array of SQL statements to create necessary foreign keys
     */
    abstract public function generateForeignKeyConstraints($sourceTable);

    /**
     * Get the constraint name for this relationship
     */
    public function getConstraintName($sourceTable, $targetTable = null)
    {
        if ($this->constraintOptions['constraint_name']) {
            return $this->constraintOptions['constraint_name'];
        }

        // Auto-generate constraint name
        $targetTable = $targetTable ?: $this->getTableNameFromModelClass($this->relatedModelClass);
        return "fk_{$sourceTable}_{$this->foreignKey}";
    }

    /**
     * Get table name from model class name (helper method)
     */
    protected function getTableNameFromModelClass($modelClass)
    {
        // Remove namespace and 'Model' suffix, convert to snake_case
        $className = basename(str_replace('\\', '/', $modelClass));
        $className = str_replace('Model', '', $className);

        // Convert CamelCase to snake_case and pluralize
        $tableName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));

        // Simple pluralization (add 's' if doesn't end with 's')
        if (substr($tableName, -1) !== 's') {
            $tableName .= 's';
        }

        return $tableName;
    }
}
