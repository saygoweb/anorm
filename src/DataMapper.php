<?php
namespace Anorm;

class DataMapper
{
    /** @var \PDO  */
    public $pdo;
    
    /** @var array<string, string> Map of property names to column names */
    public $map;
    
    /** @var string Name of the table */
    public $table;

    public static function create(\PDO $pdo, $table, $map)
    {
        $mapper = new DataMapper($pdo, $table, $map);
        return $mapper;
    }
    
    public static function createByClass(\PDO $pdo, $c, $tablePrefix = '')
    {
        return self::create(
            $pdo,
            $tablePrefix . self::autoTable($c),
            self::autoMap($c)
        );
    }
    
    /**
     * Private constructor, use the public create methods instead.
     * @see createByClass
     * @see create
     */
    private function __construct(\PDO $pdo, $table, $map)
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->map = $map;
    }

    public static function autoTable($c)
    {
        $className = get_class($c);
        $parts = explode('\\', $className);
        $partCount = count($parts);
        if ($partCount > 0) {
            $className = $parts[$partCount - 1];
        }
        $parts = self::splitUpper($className);
        $tableName = '';
        if ($parts) {
            $tableName .= strtolower($parts[0]);
            $partCount = count($parts);
            for ($i = 1; $i < $partCount; $i++) {
                if ($parts[$i] == 'Model') {
                    continue;
                }
                $tableName .= '_' . strtolower($parts[$i]);
            }
        }
        return $tableName;
    }

    public static function splitUpper($s)
    {
        $matches = array();
        $matchCount = preg_match_all('/[A-Z][a-z]*/', $s, $matches);
        if ($matchCount > 0) {
            return $matches[0];
        }
        return array();
    }
    
    public static function propertyName($s)
    {
        $matches = array();
        $matchCount = preg_match_all('/^([a-z]+)((?:[A-Z][a-z]*)*)/', $s, $matches);
        $propertyName = '';
        if ($matchCount == 1 && count($matches) == 3) {
            $propertyName .= strtolower($matches[1][0]);
            $parts = self::splitUpper($matches[2][0]);
            foreach ($parts as $part) {
                $propertyName .= '_' . strtolower($part);
            }
        }
        return $propertyName;
    }
    
    public static function autoMap($c)
    {
        $properties = get_object_vars($c);
        foreach ($properties as $key => $value) {
            $properties[$key] = self::propertyName($key);
        }
        return $properties;
    }
    
    public function write(&$c, $key = 'id')
    {
        $set = '';
        foreach ($this->map as $property => $field) {
            if ($property == $key || $property[0] == '_') {
                continue;
            }
            if ($set) {
                $set .= ', ';
            }
            $value = $c->$property;
            $set .= "$field='$value'";
        }
        if ($c->$key === null) {
            $sql = 'INSERT INTO `' . $this->table . '` SET ' . $set;
            $result = $this->pdo->query($sql);
            if ($result === false)
            {
                throw new SqlException($sql);
            }
            $c->$key = $this->pdo->lastInsertId();
        } else {
            $keyField = $this->map[$key];
            $id = $c->$key;
            $sql = 'UPDATE `' . $this->table . '` SET ' . $set . ' WHERE ' . $keyField . "='" . $id . "'";
            $this->pdo->query($sql); // TODO check result?
        }
    }
    
    public function writeArray(&$c, &$data, $exclude = array())
    {
        if ($data) {
            foreach ($this->map as $property => $field) {
                if ($property[0] == '_') {
                    continue;
                }
                if (!in_array($property, $exclude)) {
                    $data[$field] = $c->$property;
                }
            }
            return true;
        }
        return false;
    }
    
    public function read(&$c, $id, $key = 'id')
    {
        $keyField = $this->map[$key];
        // TODO Could make the '*' explicit from the map
        $sql = 'SELECT * FROM `' . $this->table . '` WHERE ' . $keyField . "='" . $id . "'";
        $result = $this->query($sql);
        return $this->readRow($c, $result);
    }
    
    /**
     * @var $sql string SQL query
     * @return \PDOStatement
     */
    public function query($sql)
    {
        return $this->pdo->query($sql);
    }
    
    /**
     * @var $c A reference to the model
     * @var $result The result returned from a prior call to query
     * @see query
     */
    public function readRow(&$c, \PDOStatement $result)
    {
        $data = $result->fetch(\PDO::FETCH_ASSOC);
        return $this->readArray($c, $data);
    }
    
    public function readArray(&$c, $data, $exclude = array())
    {
        if (!$data) {
            return false;
        }
        foreach ($this->map as $property => $field) {
            if ($property[0] == '_') {
                continue;
            }
            if (!in_array($property, $exclude) && array_key_exists($field, $data)) {
                $c->$property = $data[$field];
            }
        }
        return true;
    }
    
    public function delete($id, $key = 'id')
    {
        $keyField = $this->map[$key];
        $sql = 'DELETE FROM `' . $this->table . '` WHERE ' . $keyField . "='" . $id . "'";
        $result = $this->query($sql);
        if ($result === false)
        {
            throw new SqlException($sql);
        }
        // This allows for imprecise deletes which may not be the best idea. CP 25 Nov 2018
        return $result->rowCount() >= 1;
    }
}
