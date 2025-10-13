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

    public function __construct($relatedModelClass, $propertyName, $foreignKey, $primaryKey = 'id', $options = [])
    {
        $this->relatedModelClass = $relatedModelClass;
        $this->propertyName = $propertyName;
        $this->foreignKey = $foreignKey;
        $this->primaryKey = $primaryKey;
        $this->options = $options;
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
     * Get the relationship type (implemented by subclasses)
     */
    abstract public function getType();

    /**
     * Execute the relationship query and return the results
     * This method must be implemented by each relationship type
     */
    abstract public function load($sourceModel, \PDO $pdo);

    /**
     * Generate the appropriate JOIN clause for this relationship
     * Used by QueryBuilder for relationship-based queries
     */
    abstract public function generateJoinClause($sourceTable, $relatedTable);
}
