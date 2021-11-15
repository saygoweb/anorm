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
    
    public function where($sql, $data)
    {
        $this->ensureFrom();
        $this->sql .= ' WHERE ' . $sql;
        $this->boundData = $data;
        return $this;
    }

    public function groupBy($sql)
    {
        $this->ensureFrom();
        $this->sql .= ' GROUP BY ' . $sql;
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
}
