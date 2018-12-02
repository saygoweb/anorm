<?php
namespace Anorm;

class QueryBuilder
{
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
    }

    private function ensureFrom()
    {
        if (false === stripos($this->sql, 'FROM')) {
            $this->from('`' . $this->instance->_mapper->table . '`');
        }
    }

    public function where($sql)
    {
        $this->ensureFrom();
        $this->sql .= ' WHERE ' . $sql;
        return $this;
    }

    public function some()
    {
        $this->ensureFrom();
        /** @var DataMapper */
        $mapper = $this->instance->_mapper;
        $result = $mapper->query($this->sql);
        while ($mapper->readRow($this->instance, $result)) {
            yield $this->instance;
        }
    }

    public function limit($n) //TODO Offset also
    {
        $this->ensureFrom();
        if (false === stripos($this->sql, 'LIMIT')) {
            $this->sql .= " LIMIT $n";
        }
        return $this;
    }

    public function one()
    {
        /** @var DataMapper */
        $mapper = $this->instance->_mapper;
        $this->limit(1);
        $result = $mapper->query($this->sql);
        $couldRead = $mapper->readRow($this->instance, $result);
        if ($couldRead === false) {
            return false;
        }
        return $this->instance;
    }
}
