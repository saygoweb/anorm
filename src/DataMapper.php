<?php

// phpcs:disable Generic.Commenting.Todo.TaskFound

namespace Anorm;

class DataMapper
{
    public const MODE_DYNAMIC = 'dynamic';
    public const MODE_STATIC  = 'static';

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

    /** @var TransformInterface[] */
    public $transformers = [];

    /**
     * Explicit column definitions, keyed by column name, consulted before the type is
     * guessed from a sampled value in dynamic mode. Per mapper, so a column pinned in
     * one table is not pinned in every table that happens to share the name.
     * e.g. ['traffic_bytes' => 'BIGINT(20) NULL']
     * @var array<string, string>
     */
    public $columnDefinitions = [];

    /**
     * Property names that should not appear in diff output.
     * Default empty — Anorm has no opinion on which properties are "infrastructure."
     * Consumers set this per DataMapper (e.g. ['dtc','dtu','uc','uu']).
     * @var array<int, string>
     */
    public $infrastructureProperties = [];

    /** @var \Anorm\Lifecycle\ChangeListenerInterface|null */
    private static $changeListener = null;

    /** @var \Anorm\Lifecycle\DeleteListenerInterface|null */
    private static $deleteListener = null;

    /** @var bool true while a listener's onWrite or onDelete is executing (re-entrancy guard) */
    private static $insideListener = false;

    public static function create(\PDO $pdo, $table, $map)
    {
        $mapper = new DataMapper($pdo, $table, $map);
        return $mapper;
    }

    public static function createByClass(\PDO $pdo, $c, $tablePrefix = '')
    {
        return self::create($pdo, $tablePrefix . self::autoTable($c), self::autoMap($c));
    }

    public static function setChangeListener(?\Anorm\Lifecycle\ChangeListenerInterface $listener): void
    {
        self::$changeListener = $listener;
        if ($listener === null) {
            self::$insideListener = false;
        }
    }

    public static function getChangeListener(): ?\Anorm\Lifecycle\ChangeListenerInterface
    {
        return self::$changeListener;
    }

    /**
     * Register the listener notified of deletes. Deliberately a separate slot from
     * setChangeListener: widening that method's parameter and return types to accept
     * a delete-only listener would change a published signature, and requiring a
     * delete listener to also implement onWrite would mean writing a no-op. A class
     * that implements both interfaces is registered by calling both setters with it.
     */
    public static function setDeleteListener(?\Anorm\Lifecycle\DeleteListenerInterface $listener): void
    {
        self::$deleteListener = $listener;
        if ($listener === null) {
            self::$insideListener = false;
        }
    }

    public static function getDeleteListener(): ?\Anorm\Lifecycle\DeleteListenerInterface
    {
        return self::$deleteListener;
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
        if ($partCount > 1) {
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
        $matches = [];
        $matchCount = preg_match_all('/[A-Z][a-z0-9]*/', $s, $matches);
        if ($matchCount > 0) {
            return $matches[0];
        }
        return [];
    }

    public static function propertyName($s)
    {
        $matches = [];
        $matchCount = preg_match_all('/^([a-z0-9]+)((?:[A-Z][a-z0-9]*)*)/', $s, $matches);
        $propertyName = '';
        if ($matchCount == 1) {
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
            // Framework properties (_mapper, _lastSnapshot, etc.) are never columns.
            // get_object_vars() has already populated $properties with these keys
            // before the loop, so the unset is required — `continue` alone would
            // leave the key in place with its original value.
            if ($key[0] === '_') {
                unset($properties[$key]);
                continue;
            }
            $properties[$key] = self::propertyName($key);
        }
        // get_object_vars() reports values, and a PHP 7.4 typed property with no
        // default has no value until something assigns one — `public int $hitCount;`
        // is absent from it entirely. Such a property is a declaration of a column
        // as much as `public $hitCount = 0;` is, so the declared ones are added here.
        // Anything already mapped keeps its place and its spelling.
        foreach (self::declaredProperties($c) as $key) {
            if (!array_key_exists($key, $properties)) {
                $properties[$key] = self::propertyName($key);
            }
        }
        return $properties;
    }

    /**
     * The public, non-static, non-infrastructure properties $c declares, whether or
     * not they currently hold a value.
     *
     * @param object $c A model instance; autoMap() has already required one
     * @return array<int, string> Property names
     */
    private static function declaredProperties($c)
    {
        $names = [];
        $reflection = new \ReflectionClass($c);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $name = $property->getName();
            if ($name[0] === '_') {
                continue;
            }
            $names[] = $name;
        }
        return $names;
    }

    /**
     * The value of $property on $model, with an uninitialised typed property read as
     * null rather than as an Error.
     *
     * PHP 7.4 leaves `public int $hitCount;` uninitialised until something assigns to
     * it, and reading it in that state throws. A model is routinely written before
     * every declared property has been given a value, so that state is ordinary here
     * and means the same thing as null: nothing to store.
     *
     * @param mixed $model The model to read from
     * @param string $property Property name
     * @return mixed
     */
    private static function propertyValue($model, $property)
    {
        return isset($model->$property) ? $model->$property : null;
    }

    public function write(&$c)
    {
        $hasListener  = (self::$changeListener !== null);
        $shouldNotify = $hasListener && !self::$insideListener;
        $isInsert     = $hasListener && ($c->_lastSnapshot === null);
        $snapshot     = $hasListener ? $c->_lastSnapshot : null;

        $key = $this->modelPrimaryKey;
        if ($this->useReplace) {
            if (!self::propertyValue($c, $key)) {
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
                $propertyValue = self::propertyValue($c, $property);
                if ($propertyValue === null) {
                    $value = 'NULL';
                } else {
                    if (array_key_exists($field, $this->transformers)) {
                        $transformedValue = $this->transformers[$field]->txModelToDatabase($propertyValue);
                        $value = $transformedValue === null ? 'NULL' : $this->pdo->quote($transformedValue);
                    } else {
                        $value = $this->pdo->quote($propertyValue);
                    }
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
                $propertyValue = self::propertyValue($c, $property);
                if ($propertyValue === null) {
                    $value = 'NULL';
                } else {
                    if (array_key_exists($field, $this->transformers)) {
                        $transformedValue = $this->transformers[$field]->txModelToDatabase($propertyValue);
                        $value = $transformedValue === null ? 'NULL' : $this->pdo->quote($transformedValue);
                    } else {
                        $value = $this->pdo->quote($propertyValue);
                    }
                }
                // TODO Move this to bound value CP 2020-06
                $set .= "$field=$value";
            }
            $keyValue = self::propertyValue($c, $key);
            if ($keyValue === null || $keyValue === '') {
                $sql = 'INSERT INTO `' . $this->table . '` SET ' . $set;
                $this->dynamicWrapper(function () use ($sql, $c, $key) {
                    $result = $this->pdo->query($sql);
                    $c->$key = $this->pdo->lastInsertId();
                }, $c);
            } else {
                $keyField = $this->map[$key];
                $id = $keyValue;
                $sql = 'UPDATE `' . $this->table . '` SET ' . $set . ' WHERE ' . $keyField . "='" . $id . "'";
                $this->dynamicWrapper(function () use ($sql) {
                    $this->pdo->query($sql);
                }, $c);
            }
        }

        if ($shouldNotify) {
            $diff = $isInsert ? [] : $this->diff($snapshot, $c);
            self::$insideListener = true;
            try {
                self::$changeListener->onWrite($c, $diff, $isInsert);
            } catch (\Throwable $e) {
                error_log('Anorm change listener threw: ' . $e->getMessage());
            } finally {
                self::$insideListener = false;
            }
        }

        if ($hasListener) {
            $c->_lastSnapshot = $this->captureSnapshot($c);
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
        for ($strike = 0; $strike < 100; ++$strike) {
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
     * @param string $sql SQL query
     * @param array|null $data array of values for bound parameters
     * @param mixed $model Optional model to sample column types from in dynamic mode.
     *                     It must be the model this mapper maps: TableMaker reverse-maps
     *                     the column through $this->map to reach the property.
     * @return \PDOStatement
     */
    public function query($sql, $data = null, $model = null)
    {
        return $this->dynamicWrapper(function () use ($sql, $data) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($data);
            return $statement;
        }, $model);
    }

    /**
     * @param mixed $c A reference to the model
     * @param \PDOStatement $result The result returned from a prior call to query
     * @see query
     */
    public function readRow(&$c, \PDOStatement $result)
    {
        $data = $result->fetch(\PDO::FETCH_ASSOC);
        return $this->readArray($c, $data);
    }

    public function readArray(&$c, $data, $exclude = [])
    {
        if (!$data) {
            return false;
        }
        $property = '';
        $field = '';
        try {
            foreach ($this->map as $property => $field) {
                if ($property[0] == '_') {
                    continue;
                }
                if (!in_array($property, $exclude) && array_key_exists($field, $data)) {
                    if (array_key_exists($field, $this->transformers)) {
                        $c->$property = $this->transformers[$field]->txDatabaseToModel($data[$field]);
                    } else {
                        $c->$property = $data[$field];
                    }
                }
            }
        } catch (\TypeError $e) {
            // A typed property that cannot hold what the column holds — most often a
            // non-nullable property against a nullable column. PHP's own message names
            // neither the column nor the table, and this is the one place that knows both.
            throw new \TypeError(
                'Anorm: cannot read `' . $this->table . '`.`' . $field . '` into '
                . get_class($c) . '::$' . $property . ' — ' . $e->getMessage()
                . '. Declare the property nullable, or make the column NOT NULL.',
                0,
                $e
            );
        }
        if (self::$changeListener !== null) {
            $c->_lastSnapshot = $this->captureSnapshot($c);
        }
        return true;
    }

    private function captureSnapshot(Model $c): array
    {
        $out = [];
        foreach ($this->map as $property => $field) {
            if ($property[0] === '_') {
                continue;
            }
            $v = self::propertyValue($c, $property);
            $out[$property] = is_object($v) ? clone $v : $v;
        }
        return $out;
    }

    /**
     * Compute the per-property delta between a snapshot and the current model state.
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(array $snapshot, Model $current): array
    {
        $loaded = $current->getLoadedFields();
        $out = [];
        foreach ($this->map as $property => $field) {
            if ($property[0] === '_') {
                continue;
            }
            if ($property === $this->modelPrimaryKey) {
                continue;
            }
            if (in_array($property, $this->infrastructureProperties, true)) {
                continue;
            }
            if ($loaded !== null && !in_array($property, $loaded, true)) {
                continue;
            }
            $from = array_key_exists($property, $snapshot) ? $snapshot[$property] : null;
            $to   = self::propertyValue($current, $property);
            if (!$this->valuesEqual($from, $to)) {
                $out[$property] = ['from' => $from, 'to' => $to];
            }
        }
        return $out;
    }

    private function valuesEqual($a, $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        if (is_object($a) && is_object($b)) {
            if (get_class($a) !== get_class($b)) {
                return false;
            }
            if (method_exists($a, 'equals')) {
                return (bool) $a->equals($b);
            }
            if (method_exists($a, 'isSame')) {
                return (bool) $a->isSame($b);
            }
            return $a == $b; // PHP property-by-property equality
        }
        if (is_array($a) && is_array($b)) {
            return $a === $b;
        }
        return $a === $b;
    }

    /**
     * @param int|string $id    The primary key value to delete.
     * @param Model|null $model The model being deleted, when there is one. Passing it
     *                          lets a DeleteListenerInterface see which model went, and
     *                          clears that model's snapshot so a later write() is
     *                          correctly treated as an INSERT.
     * @return bool True when a row was deleted.
     */
    public function delete($id, ?Model $model = null)
    {
        $keyField = $this->map[$this->modelPrimaryKey];
        $sql = 'DELETE FROM `' . $this->table . '` WHERE `' . $keyField . '` = ?';
        $result = $this->query($sql, [$id], $model);
        // A bound primary key matches at most one row, so this is 0 or 1.
        $deleted = $result->rowCount() >= 1;
        if ($deleted) {
            $this->notifyDelete($id, $model);
        }
        return $deleted;
    }

    /**
     * Fire onDelete (when the listener opted in and we are not already inside one),
     * then drop the model's snapshot. Ordering matters: the listener runs first so it
     * can still read $model->_lastSnapshot for the pre-delete values.
     *
     * @param int|string $id
     * @param Model|null $model
     */
    private function notifyDelete($id, ?Model $model): void
    {
        $listener = self::$deleteListener;
        if ($listener !== null && !self::$insideListener) {
            self::$insideListener = true;
            try {
                $listener->onDelete($this->table, $id, $model);
            } catch (\Throwable $e) {
                error_log('Anorm delete listener threw: ' . $e->getMessage());
            } finally {
                self::$insideListener = false;
            }
        }
        if ($model !== null) {
            $model->_lastSnapshot = null;
        }
    }

    public static function find($creatable, $pdo)
    {
        return new QueryBuilder($creatable, $pdo);
    }
}
