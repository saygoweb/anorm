<?php

namespace Anorm;

class QueryBuilder
{
    public $boundData = null;

    private $method;

    private $instance;

    private $sql;


    public function __construct($creatable, \PDO $pdo = null)
    {
        if (is_string($creatable) && \class_exists($creatable)) {
            $this->method = 'new';
            $this->instance = new $creatable($pdo);
        } else {
            throw new \Exception("'\$creatable' is not a class");
        }
        $this->sql = '';
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
        $this->ensureFrom();
        /** @var DataMapper */
        $mapper = $this->instance->_mapper;
        $result = $mapper->query($this->sql, $this->boundData);
        while ($mapper->readRow($this->instance, $result)) {
            yield $this->instance;
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
     * @param array $mangoQuery The Mango Query object
     * @return self
     */
    public function fromMango(array $mangoQuery): self
    {
        $query = new MangoQuery($mangoQuery);
        $this->applyMangoQuery($query);
        return $this;
    }

    /**
     * Alias for fromMango()
     */
    public function mango(array $mangoQuery): self
    {
        return $this->fromMango($mangoQuery);
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
            $fieldsClause = $parser->parseFields($query->getFields());
            $this->select($fieldsClause);
        }

        // Apply selector (WHERE clause)
        if ($query->hasConditions()) {
            $condition = $parser->parseSelector($query->getSelector());
            if (!$condition->isEmpty()) {
                $this->where($condition);
            }
        }

        // Apply sort (ORDER BY clause)
        if ($query->hasSort()) {
            $sortClause = $parser->parseSort($query->getSort());
            if (!empty($sortClause)) {
                $this->orderBy($sortClause);
            }
        }

        // Apply pagination (LIMIT and OFFSET)
        if ($query->hasPagination()) {
            $limit = $query->getLimit();
            $skip = $query->getSkip() ?? 0;

            if ($limit !== null) {
                $this->limit($limit, $skip);
            } elseif ($skip > 0) {
                // If only skip is specified, use a large limit
                $this->limit(PHP_INT_MAX, $skip);
            }
        }

        // TODO: Handle use_index hint in future versions
    }
}
