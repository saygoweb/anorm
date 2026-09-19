<?php

namespace Anorm\Relationship;

use Anorm\Schema\RelationshipSchema;

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
     *
     * A relationship's keys are declared as property names, and a JOIN is written in
     * column names. A caller that has the mappers in hand resolves them and says so;
     * one that does not gets the names as declared, which is the same thing for a
     * model that named its property after its column.
     *
     * @param string $sourceTable
     * @param string $relatedTable
     * @param string|null $foreignKeyColumn The foreign key as a column, where known
     * @param string|null $primaryKeyColumn The primary key as a column, where known
     * @return string
     */
    abstract public function generateJoinClause($sourceTable, $relatedTable, $foreignKeyColumn = null, $primaryKeyColumn = null);

    /**
     * Generate foreign key constraint SQL for this relationship
     * Returns array of SQL statements to create necessary foreign keys
     *
     * As with `generateJoinClause()`, a caller that has resolved the relationship's
     * names to tables and columns passes them; one that has not gets the names as
     * declared. `ForeignKeyWriter` is the resolved path Anorm itself takes.
     *
     * @param string $sourceTable The table of the model declaring the relationship
     * @param string|null $targetTable The related model's table, where known
     * @param string|null $foreignKeyColumn The foreign key as a column, where known
     * @param string|null $primaryKeyColumn The primary key as a column, where known
     * @return array<int, string>
     */
    abstract public function generateForeignKeyConstraints(
        $sourceTable,
        $targetTable = null,
        $foreignKeyColumn = null,
        $primaryKeyColumn = null
    );

    /**
     * Get the constraint name for this relationship
     *
     * The name carries the foreign key column, so a caller that knows how the
     * relationship's key is spelled as a column says so. A caller that does not gets
     * the name as declared, which is the same thing for a model that named its
     * property after its column.
     *
     * @param string $sourceTable The table carrying the foreign key
     * @param string|null $targetTable Unused; kept because callers pass it
     * @param string|null $foreignKeyColumn The foreign key as a column, where known
     * @return string
     */
    public function getConstraintName($sourceTable, $targetTable = null, $foreignKeyColumn = null)
    {
        if ($this->constraintOptions['constraint_name']) {
            return $this->constraintOptions['constraint_name'];
        }

        $column = $foreignKeyColumn === null ? $this->foreignKey : $foreignKeyColumn;
        return "fk_{$sourceTable}_{$column}";
    }

    /**
     * Get table name from model class name (helper method)
     *
     * This is the guess for when the related model itself cannot be reached. Where a
     * connection is in hand, `RelationshipSchema::tableForClass()` asks the model what
     * its table is instead, which is the only answer that is not a guess.
     *
     * @param string $modelClass
     * @return string
     */
    protected function getTableNameFromModelClass($modelClass)
    {
        return RelationshipSchema::deriveTableName($modelClass);
    }
}
