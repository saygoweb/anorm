<?php
namespace Anorm;

class DataMapper
{

    const MODE_DYNAMIC = 'dynamic';
    const MODE_STATIC  = 'static';

    public $mode = self::MODE_STATIC;

    /** @var \PDO  */
    public $pdo;
    
    /** @var array<string, string> Map of property names to column names */
    public $map;

    /** @var string The property in the model that is used as the primary key */
    public $modelPrimaryKey = 'id';

    /** @var bool If true REPLACE is used rather than INSERT and UPDATE */
    public $useReplace = false;

    /** @var string Name of the table */
    public $table;

    public static function create(\PDO $pdo, $table, $map)
    {
        $mapper = new DataMapper($pdo, $table, $map);
        return $mapper;
    }
    
    public static function createByClass(\PDO $pdo, $c, $tablePrefix = '')
    {
        return self::create($pdo, $tablePrefix . self::autoTable($c), self::autoMap($c));
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
        $matchCount = preg_match_all('/[A-Z][a-z0-9]*/', $s, $matches);
        if ($matchCount > 0) {
            return $matches[0];
        }
        return array();
    }
    
    public static function propertyName($s)
    {
        $matches = array();
        $matchCount = preg_match_all('/^([a-z0-9]+)((?:[A-Z][a-z0-9]*)*)/', $s, $matches);
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
    
    public function write(&$c)
    {
        $key = $this->modelPrimaryKey;
        if ($this->useReplace) {
            if (!$c->$key) {
                throw new \Exception("Key '$key' must be set when using replace mode");
            }
            $fields = '';
            $values = '';
            foreach ($this->map as $property => $field) {
                if ($property[0] == '_') {
                    continue;
                }
                if ($fields) {
                    $fields .= ', ';
                }
                if ($values) {
                    $values .= ', ';
                }
                $fields .= $field;
                if ($c->$property === null) {
                    $value = 'NULL';
                } else {
                    $value = $this->pdo->quote($c->$property);
                }
                $values .= $value;
            }
            $keyField = $this->map[$key];
            $id = $c->$key;
            // CP Maybe REPLACE isn't the best to use? It requires a unique key in the db
            // An alternative would be to detect based on SELECT query WHERE key and if found ...
            $sql = 'REPLACE INTO`' . $this->table . '` (' . $fields . ') VALUES (' . $values . ')';
            $this->dynamicWrapper(function () use ($sql) {
                $this->pdo->query($sql);
            }, $c);
        } else {
            $set = '';
            foreach ($this->map as $property => $field) {
                if ($property == $key || $property[0] == '_') {
                    continue;
                }
                if ($set) {
                    $set .= ', ';
                }
                if ($c->$property === null) {
                    $value = 'NULL';
                } else {
                    $value = $this->pdo->quote($c->$property);
                }
                // TODO Move this to bound value CP 2020-06
                $set .= "$field=$value";
            }
            if ($c->$key === null) {
                $sql = 'INSERT INTO `' . $this->table . '` SET ' . $set;
                $this->dynamicWrapper(function () use ($sql, $c, $key) {
                    $result = $this->pdo->query($sql);
                    $c->$key = $this->pdo->lastInsertId();
                }, $c);
            } else {
                $keyField = $this->map[$key];
                $id = $c->$key;
                $sql = 'UPDATE `' . $this->table . '` SET ' . $set . ' WHERE ' . $keyField . "='" . $id . "'";
                $this->dynamicWrapper(function () use ($sql) {
                    $this->pdo->query($sql);
                }, $c);
            }
        }
        return $c->$key;
    }
    
    public function read(&$c, $id)
    {
        $databasePrimaryKey = $this->map[$this->modelPrimaryKey];
        // TODO Could make the '*' explicit from the map
        $sql = 'SELECT * FROM `' . $this->table . '` WHERE ' . $databasePrimaryKey . "='" . $id . "'";
        $result = $this->dynamicWrapper(function () use ($sql) {
            return $this->pdo->query($sql);
        }, $c);
        return $this->readRow($c, $result);
    }
    
    private function dynamicWrapper(callable $fn, $model = null)
    {
        $lastException = '';
        for ($strike = 0; $strike < 10; ++$strike) {
            try {
                return $fn();
            } catch (\PDOException $e) {
                if ($this->mode !== self::MODE_DYNAMIC) {
                    throw $e;
                }
                TableMaker::fix($e, $this, $model);
                if ($e->getMessage() == $lastException) {
                    // Same exception twice in a row so throw.
                    throw $e; // @codeCoverageIgnore
                }
                $lastException = $e->getMessage();
            }
        }
        throw new \Exception("$strike strikes in DataMapper."); // @codeCoverageIgnore
    }

    /**
     * @var string $sql string SQL query
     * @var array $data array of values for bound parameters
     * @return \PDOStatement
     */
    public function query($sql, $data = null)
    {
        return $this->dynamicWrapper(function () use ($sql, $data) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($data);
            return $statement;
        });
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

    public function delete($id)
    {
        $keyField = $this->map[$this->modelPrimaryKey];
        $sql = 'DELETE FROM `' . $this->table . '` WHERE ' . $keyField . "='" . $id . "'";
        $result = $this->query($sql);
        // This allows for imprecise deletes which may not be the best idea. CP 25 Nov 2018
        return $result->rowCount() >= 1;
    }

    public static function find($creatable, $pdo)
    {
        return new QueryBuilder($creatable, $pdo);
    }

}
