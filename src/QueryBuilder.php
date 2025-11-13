<?php

namespace Anorm;

use Anorm\Relationship\BatchLoadingOrchestrator;
use Anorm\Relationship\Strategy\NestedRelationshipParser;
use Anorm\Relationship\Performance\PerformanceMonitor;

class QueryBuilder
{
    public $boundData = null;

    /** @var string Method used for creating instances @phpstan-ignore-next-line */
    private $method;

    private $instance;

    private $sql;

    /** @var array Relationships to eager load */
    private $eagerLoadRelationships = [];

    /** @var BatchLoadingOrchestrator */
    private $batchOrchestrator;

    /** @var bool Enable batch loading optimization */
    private $enableBatchLoading = true;

    /** @var PerformanceMonitor|null Performance monitoring */
    private $performanceMonitor = null;


    public function __construct($creatable, \PDO $pdo = null)
    {
        if (is_string($creatable) && \class_exists($creatable)) {
            $this->method = 'new';
            $this->instance = new $creatable($pdo);
        } else {
            throw new \Exception("'\$creatable' is not a class");
        }
        $this->sql = '';
        $this->batchOrchestrator = new BatchLoadingOrchestrator();
    }

    public function select($sql)
    {
        $this->sql .= 'SELECT ' . $sql;
        return $this;
    }

    private function ensureSelect()
    {
        if (false === stripos($this->sql, 'SELECT')) {
            $this->select("*");
        }
    }

    public function from($sql)
    {
        $this->ensureSelect();
        $this->sql .= ' FROM ' . $sql;
        return $this;
    }

    private function ensureFrom()
    {
        if (false === stripos($this->sql, 'FROM')) {
            $this->from('`' . $this->instance->_mapper->table . '`');
        }
    }

    public function join($sql)
    {
        $this->ensureFrom();
        $this->sql .= ' ' . $sql; // Assume the type of join is included in $sql
        return $this;
    }

    /**
     * Specify relationships to eager load
     *
     * Supports multiple syntaxes:
     * - Simple: with(['posts', 'company'])
     * - Field selection: with(['posts:id,title', 'company:name'])
     * - Nested: with(['posts.comments', 'posts.comments.author'])
     * - Mixed: with(['posts:id,title.comments:id,content', 'company:name'])
     *
     * @param array|string $relationships Array of relationship names/specs to load
     * @return self
     */
    public function with($relationships)
    {
        if (is_string($relationships)) {
            $relationships = [$relationships];
        }

        // Validate and normalize relationship specifications
        foreach ($relationships as $spec) {
            if (!is_string($spec) || empty(trim($spec))) {
                throw new \InvalidArgumentException("Relationship specification must be a non-empty string");
            }

            // Validate nested relationship syntax
            $parser = new NestedRelationshipParser();
            $validation = $parser->validateNestedSpec($spec);

            if (!$validation['valid']) {
                throw new \InvalidArgumentException("Invalid relationship specification '{$spec}': " . implode(', ', $validation['errors']));
            }

            // Log warnings for deep nesting
            if (!empty($validation['warnings']) && $this->performanceMonitor) {
                foreach ($validation['warnings'] as $warning) {
                    error_log("QueryBuilder Warning: {$warning}");
                }
            }
        }

        $this->eagerLoadRelationships = array_merge($this->eagerLoadRelationships, $relationships);
        return $this;
    }

    /**
     * Join based on a relationship definition
     * @param string $relationshipName The name of the relationship
     * @param string $joinType The type of join (LEFT, INNER, RIGHT)
     * @return self
     */
    public function joinRelationship($relationshipName, $joinType = 'LEFT')
    {
        $relationshipManager = $this->instance->getRelationshipManager();
        $relationship = $relationshipManager->getRelationship($relationshipName);

        if (!$relationship) {
            throw new \Exception("Relationship '{$relationshipName}' not defined");
        }

        // Get the related model to determine its table name
        $relatedClass = $relationship->getRelatedModelClass();
        $relatedInstance = new $relatedClass($this->instance->_mapper->pdo);
        $relatedTable = $relatedInstance->_mapper->table;
        $sourceTable = $this->instance->_mapper->table;

        // Generate the join clause
        $joinClause = $relationship->generateJoinClause($sourceTable, $relatedTable);
        $joinClause = str_replace('LEFT JOIN', $joinType . ' JOIN', $joinClause);

        $this->join($joinClause);
        return $this;
    }

    public function where($sql, $data = null)
    {
        $this->ensureFrom();

        if ($sql instanceof SqlCondition) {
            // Handle SqlCondition objects from Mango queries
            $this->sql .= ' WHERE ' . $sql->getSql();
            $this->boundData = array_merge($this->boundData ?? [], $sql->getBindings());
        } else {
            // Handle traditional string SQL
            $this->sql .= ' WHERE ' . $sql;
            $this->boundData = $data;
        }

        return $this;
    }

    public function groupBy($sql)
    {
        $this->ensureFrom();
        $this->sql .= ' GROUP BY ' . $sql;
        return $this;
    }

    public function having($sql)
    {
        $this->ensureFrom();
        $this->sql .= ' HAVING ' . $sql;
        return $this;
    }

    public function orderBy($sql)
    {
        $this->ensureFrom();
        $this->sql .= ' ORDER BY ' . $sql;
        return $this;
    }

    public function some()
    {
        if ($this->enableBatchLoading && !empty($this->eagerLoadRelationships)) {
            // Use batch loading for better performance
            yield from $this->someWithBatchLoading();
        } else {
            // Use traditional individual loading
            yield from $this->someWithIndividualLoading();
        }
    }

    /**
     * Fetch models with batch loading optimization
     */
    private function someWithBatchLoading()
    {
        $this->ensureFrom();
        /** @var DataMapper */
        $mapper = $this->instance->_mapper;
        $result = $mapper->query($this->sql, $this->boundData);

        // Collect all models first
        $models = [];
        while ($data = $result->fetch(\PDO::FETCH_ASSOC)) {
            // Create a new instance for each row
            $modelClass = get_class($this->instance);
            $model = new $modelClass($mapper->pdo);
            $model->_mapper->readArray($model, $data);
            $models[] = $model;
        }

        // Batch load relationships for all models (including nested)
        if (!empty($models) && !empty($this->eagerLoadRelationships)) {
            $operationId = 'batch_load_' . uniqid();

            // Start performance monitoring if enabled
            if ($this->performanceMonitor) {
                $this->performanceMonitor->startOperation($operationId, [
                    'model_count' => count($models),
                    'relationships' => $this->eagerLoadRelationships,
                    'model_class' => get_class($this->instance)
                ]);
            }

            // Check for nested relationships
            $hasNestedRelationships = $this->hasNestedRelationships($this->eagerLoadRelationships);

            if ($hasNestedRelationships) {
                $this->loadNestedRelationships($models, $this->eagerLoadRelationships);
            } else {
                $this->batchOrchestrator->loadRelationshipsForModels($models, $this->eagerLoadRelationships);
            }

            // End performance monitoring
            if ($this->performanceMonitor) {
                $this->performanceMonitor->endOperation($operationId, [
                    'loaded_models' => count($models)
                ]);
            }
        }

        // Yield the models
        foreach ($models as $model) {
            yield $model;
        }
    }

    /**
     * Fetch models with traditional individual loading (fallback)
     */
    private function someWithIndividualLoading()
    {
        $this->ensureFrom();
        /** @var DataMapper */
        $mapper = $this->instance->_mapper;
        $result = $mapper->query($this->sql, $this->boundData);

        while ($data = $result->fetch(\PDO::FETCH_ASSOC)) {
            // Create a new instance for each row
            $modelClass = get_class($this->instance);
            $model = new $modelClass($mapper->pdo);
            $model->_mapper->readArray($model, $data);

            // Load eager relationships if specified
            $this->loadEagerRelationships($model);

            yield $model;
        }
    }

    public function limit($n, $offset = 0)
    {
        $this->ensureFrom();
        if (false === stripos($this->sql, 'LIMIT')) {
            $this->sql .= " LIMIT $offset, $n";
        }
        return $this;
    }

    public function one()
    {
        /** @var DataMapper */
        $mapper = $this->instance->_mapper;
        $this->limit(1);
        $result = $mapper->query($this->sql, $this->boundData);
        $couldRead = $mapper->readRow($this->instance, $result);
        if ($couldRead === false) {
            return false;
        }

        // Load eager relationships if specified
        $this->loadEagerRelationships($this->instance);

        return $this->instance;
    }

    public function oneOrThrow()
    {
        $result = $this->one();
        if ($result === false) {
            throw new \Exception(sprintf("QueryBuilder: Expected one not found from '%s'", $this->sql));
        }
        return $result;
    }

    /**
     * Apply a Mango Query to this QueryBuilder
     *
     * @param MangoQuery $query The Mango Query object
     * @return self
     */
    public function byMango(MangoQuery $query): self
    {
        $this->applyMangoQuery($query);
        return $this;
    }

    /**
     * Apply a parsed MangoQuery to this QueryBuilder
     */
    private function applyMangoQuery(MangoQuery $query): void
    {
        /** @var DataMapper */
        $mapper = $this->instance->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Apply fields (SELECT clause)
        if ($query->hasFields()) {
            $fieldsClause = $parser->parseFields($query->fields);
            $this->select($fieldsClause);
        }

        // Apply selector (WHERE clause)
        if ($query->hasConditions()) {
            $condition = $parser->parseSelector($query->selector);
            if (!$condition->isEmpty()) {
                $this->where($condition);
            }
        }

        // Apply sort (ORDER BY clause)
        if ($query->hasSort()) {
            $sortClause = $parser->parseSort($query->sort);
            if (!empty($sortClause)) {
                $this->orderBy($sortClause);
            }
        }

        // Apply pagination (LIMIT and OFFSET)
        if ($query->hasPagination()) {
            $limit = $query->limit;
            $skip = $query->skip ?? 0;

            if ($limit !== null) {
                $this->limit($limit, $skip);
            } elseif ($skip > 0) {
                // If only skip is specified, use a large limit
                $this->limit(PHP_INT_MAX, $skip);
            }
        }

        // TODO: Handle use_index hint in future versions
    }

    /**
     * Load eager relationships for a model instance
     * @param object $model The model instance to load relationships for
     */
    private function loadEagerRelationships($model)
    {
        if (empty($this->eagerLoadRelationships)) {
            return;
        }

        foreach ($this->eagerLoadRelationships as $relationshipName) {
            $model->loadRelated($relationshipName);
        }
    }

    /**
     * Enable or disable batch loading optimization
     *
     * @param bool $enabled Whether to enable batch loading
     * @return self
     */
    public function enableBatchLoading(bool $enabled = true): self
    {
        $this->enableBatchLoading = $enabled;
        return $this;
    }

    /**
     * Disable batch loading optimization
     *
     * @return self
     */
    public function disableBatchLoading(): self
    {
        $this->enableBatchLoading = false;
        return $this;
    }

    /**
     * Check if batch loading is enabled
     *
     * @return bool
     */
    public function isBatchLoadingEnabled(): bool
    {
        return $this->enableBatchLoading;
    }

    /**
     * Set batch loading configuration
     *
     * @param array $config Configuration options
     * @return self
     */
    public function setBatchLoadingConfig(array $config): self
    {
        $this->batchOrchestrator->setConfig($config);
        return $this;
    }

    /**
     * Set performance monitor
     *
     * @param PerformanceMonitor $monitor Performance monitor instance
     * @return self
     */
    public function setPerformanceMonitor(PerformanceMonitor $monitor): self
    {
        $this->performanceMonitor = $monitor;
        return $this;
    }

    /**
     * Check if any relationships are nested
     *
     * @param array $relationships Relationship specifications
     * @return bool True if nested relationships are present
     */
    private function hasNestedRelationships(array $relationships): bool
    {
        foreach ($relationships as $spec) {
            if (strpos($spec, '.') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load nested relationships for models
     *
     * @param array $models Models to load relationships for
     * @param array $relationshipSpecs Relationship specifications
     * @return void
     */
    private function loadNestedRelationships(array $models, array $relationshipSpecs): void
    {
        $parser = new NestedRelationshipParser();
        $nestedSpecs = $parser->parseNestedSpecs($relationshipSpecs);
        $parser->loadNestedRelationships($models, $nestedSpecs);
    }
}
