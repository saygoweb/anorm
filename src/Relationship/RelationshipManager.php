<?php

namespace Anorm\Relationship;

/**
 * Manages relationships for a model instance
 * Handles relationship registration, loading, and caching
 */
class RelationshipManager
{
    /** @var array Registered relationships */
    private $relationships = [];
    
    /** @var object The model instance this manager belongs to */
    private $model;
    
    /** @var \PDO The database connection */
    private $pdo;

    public function __construct($model, \PDO $pdo)
    {
        $this->model = $model;
        $this->pdo = $pdo;
    }

    /**
     * Register a one-to-many relationship
     */
    public function hasMany($relatedModelClass, $propertyName, $foreignKey, $primaryKey = 'id', $options = [])
    {
        $relationship = new OneHasMany($relatedModelClass, $propertyName, $foreignKey, $primaryKey, $options);
        $this->relationships[$propertyName] = $relationship;
    }

    /**
     * Register a many-to-one relationship (belongs to)
     */
    public function belongsTo($relatedModelClass, $propertyName, $foreignKey, $primaryKey = 'id', $options = [])
    {
        $relationship = new ManyHasOne($relatedModelClass, $propertyName, $foreignKey, $primaryKey, $options);
        $this->relationships[$propertyName] = $relationship;
    }

    /**
     * Register a many-to-many relationship
     */
    public function hasManyThrough($relatedModelClass, $propertyName, $joinForeignKey, $joinRelatedKey, $joinTable, $primaryKey = 'id', $options = [])
    {
        $relationship = new ManyHasMany($relatedModelClass, $propertyName, $joinForeignKey, $joinRelatedKey, $joinTable, $primaryKey, $options);
        $this->relationships[$propertyName] = $relationship;
    }

    /**
     * Load a specific relationship
     */
    public function loadRelated($relationshipName)
    {
        if (!isset($this->relationships[$relationshipName])) {
            throw new \Exception("Relationship '{$relationshipName}' not defined");
        }

        $relationship = $this->relationships[$relationshipName];
        $relatedData = $relationship->load($this->model, $this->pdo);
        
        // Assign the loaded data directly to the model property
        $this->model->{$relationshipName} = $relatedData;
        
        return $relatedData;
    }

    /**
     * Load all defined relationships
     */
    public function loadAllRelated()
    {
        foreach ($this->relationships as $relationshipName => $relationship) {
            $this->loadRelated($relationshipName);
        }
    }

    /**
     * Get a relationship definition
     */
    public function getRelationship($relationshipName)
    {
        return isset($this->relationships[$relationshipName]) ? $this->relationships[$relationshipName] : null;
    }

    /**
     * Get all relationship definitions
     */
    public function getAllRelationships()
    {
        return $this->relationships;
    }

    /**
     * Get all relationship objects (values only)
     */
    public function getRelationships()
    {
        return array_values($this->relationships);
    }

    /**
     * Check if a relationship is defined
     */
    public function hasRelationship($relationshipName)
    {
        return isset($this->relationships[$relationshipName]);
    }

    /**
     * Clear all loaded relationship data from the model
     */
    public function clearRelationships()
    {
        foreach ($this->relationships as $relationshipName => $relationship) {
            $this->model->{$relationshipName} = null;
        }
    }
}
