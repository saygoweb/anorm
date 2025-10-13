<?php

namespace Anorm;

use Anorm\Relationship\RelationshipManager;

class Model
{
    /** @var DataMapper */
    public $_mapper;

    /** @var RelationshipManager */
    protected $_relationshipManager;

    /** @var \PDO */
    protected $_pdo;

    public function __construct(\PDO $pdo, DataMapper $mapper)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->_mapper = $mapper;
        $this->_pdo = $pdo;
        $this->_relationshipManager = new RelationshipManager($this, $pdo);
    }

    /**
     * @return int The primary key id of the model.
     */
    public function write()
    {
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
}
