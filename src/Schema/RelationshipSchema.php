<?php

namespace Anorm\Schema;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Relationship\Relationship;

/**
 * The tables and columns a relationship names.
 *
 * A relationship is declared in property terms — `belongsTo(ClientModel::class,
 * 'clientId')` names a property, because that is what a model author has in hand. A
 * constraint, a JOIN and an ALTER TABLE are written in column terms. Something has to
 * translate, and when each caller translated for itself the two readings drifted: the
 * constraint went on a column invented from the property name while the real column
 * went unconstrained, and the referenced table was re-derived from the class name by a
 * rule that disagreed with the one the model itself used.
 *
 * This is the one place that translates, so that the identifiers dynamic mode writes
 * and the identifiers a schema diff expects cannot drift apart: both ask this.
 *
 * @see \Anorm\Schema\ColumnIntent for the same arrangement applied to column types
 * @see \Anorm\Schema\ForeignKeyWriter for the creating side
 * @see \Anorm\Schema\SchemaDiff for the comparing side
 */
class RelationshipSchema
{
    /**
     * The last related model built for a class, with the connection it was built on.
     *
     * `Model::write()` re-creates constraints on every dynamic write, so the related
     * model would otherwise be constructed once per write per relationship. A model's
     * table and map are fixed by its constructor, so an instance is safe to keep.
     *
     * The connection is held rather than hashed: `spl_object_hash()` reuses the hash
     * of a collected object, which would hand back a model bound to a closed one.
     *
     * @var array<string, array{pdo: \PDO, model: Model|false}>
     */
    private static $models = [];

    /** @var string The table carrying the foreign key column */
    public $table;

    /** @var string The foreign key column */
    public $column;

    /** @var string The table the foreign key points at */
    public $referencedTable;

    /** @var string The column the foreign key points at */
    public $referencedColumn;

    /** @var string The constraint name */
    public $constraintName;

    /** @var array<string, mixed> The relationship's constraint options */
    public $constraintOptions;

    /**
     * @param string $table
     * @param string $column
     * @param string $referencedTable
     * @param string $referencedColumn
     * @param string $constraintName
     * @param array<string, mixed> $constraintOptions
     */
    public function __construct($table, $column, $referencedTable, $referencedColumn, $constraintName, $constraintOptions)
    {
        $this->table = $table;
        $this->column = $column;
        $this->referencedTable = $referencedTable;
        $this->referencedColumn = $referencedColumn;
        $this->constraintName = $constraintName;
        $this->constraintOptions = $constraintOptions;
    }

    /**
     * What $relationship names, in the terms SQL is written in.
     *
     * Which side carries the foreign key decides which mapper resolves which name.
     * For a `belongsTo` the key is on the source model's own table; for a `hasMany` it
     * is on the related model's table, and it is the related model's map that knows
     * how its property is spelled as a column.
     *
     * @param DataMapper $sourceMapper The mapper of the model declaring the relationship
     * @param Relationship $relationship The relationship
     * @param \PDO $pdo A connection, to reach the related model's mapper
     * @return RelationshipSchema|null null where the relationship puts no foreign key
     *                                 on a model's own table, as manyHasMany does not
     */
    public static function forRelationship(DataMapper $sourceMapper, Relationship $relationship, \PDO $pdo)
    {
        $relatedMapper = self::mapperForClass($relationship->getRelatedModelClass(), $pdo);
        $relatedTable = self::tableForClass($relationship->getRelatedModelClass(), $pdo);
        $type = $relationship->getType();

        if ($type === 'manyHasOne') {
            $table = $sourceMapper->table;
            $column = self::column($sourceMapper, $relationship->getForeignKey());
            $referencedTable = $relatedTable;
            $referencedColumn = self::column($relatedMapper, $relationship->getPrimaryKey());
        } elseif ($type === 'oneHasMany') {
            $table = $relatedTable;
            $column = self::column($relatedMapper, $relationship->getForeignKey());
            $referencedTable = $sourceMapper->table;
            $referencedColumn = self::column($sourceMapper, $relationship->getPrimaryKey());
        } else {
            return null;
        }

        return new self(
            $table,
            $column,
            $referencedTable,
            $referencedColumn,
            $relationship->getConstraintName($table, $referencedTable, $column),
            $relationship->getConstraintOptions()
        );
    }

    /**
     * The column a name means, where the map knows it.
     *
     * A name the map does not mention is already a column — that is the spelling a
     * model that named its property after the column has always used, and the map
     * maps such a property to itself anyway.
     *
     * @param DataMapper|null $mapper The mapper whose map applies, or null where none is known
     * @param string $name A property name, or a column name
     * @return string
     */
    public static function column($mapper, $name)
    {
        if ($mapper !== null && isset($mapper->map[$name])) {
            return $mapper->map[$name];
        }
        return $name;
    }

    /**
     * The table a model class maps to.
     *
     * The model knows its own table, whether it set `$mapper->table` outright or let
     * `DataMapper::autoTable()` derive it. Asking it beats re-deriving the name from
     * the class, which no name-mangling rule can be relied on to get right.
     *
     * @param string $modelClass The related model's class
     * @param \PDO|null $pdo A connection, to construct the model with
     * @return string
     */
    public static function tableForClass($modelClass, $pdo = null)
    {
        $mapper = $pdo === null ? null : self::mapperForClass($modelClass, $pdo);
        if ($mapper !== null && $mapper->table) {
            return $mapper->table;
        }
        return self::deriveTableName($modelClass);
    }

    /**
     * The table name a model class implies, for when the model cannot be reached.
     *
     * This is a guess and the only one available when a class cannot be constructed.
     * It is kept in one place so that the three callers that used to each hold a copy
     * cannot disagree about what a class is called again.
     *
     * @param string $modelClass The model's class
     * @return string
     */
    public static function deriveTableName($modelClass)
    {
        $className = basename(str_replace('\\', '/', $modelClass));
        $className = str_replace('Model', '', $className);

        // The same CamelCase-to-snake_case conversion a property gets, so that a class
        // name and a property name are never split by two different rules.
        $tableName = DataMapper::propertyName($className);

        if (substr($tableName, -1) === 'y') {
            $tableName = substr($tableName, 0, -1) . 'ies';
        } elseif ($tableName !== '' && substr($tableName, -1) !== 's') {
            $tableName .= 's';
        }

        return $tableName;
    }

    /**
     * The mapper of a related model, or null where the model cannot be constructed.
     *
     * A model that cannot be built is not an error here: the caller falls back to
     * deriving the name, which is what it did before there was anything better.
     *
     * @param string $modelClass The model's class
     * @param \PDO $pdo A connection to construct the model with
     * @return DataMapper|null
     */
    private static function mapperForClass($modelClass, \PDO $pdo)
    {
        $model = self::modelForClass($modelClass, $pdo);
        return $model === null ? null : $model->_mapper;
    }

    /**
     * @param string $modelClass The model's class
     * @param \PDO $pdo A connection to construct the model with
     * @return Model|null
     */
    private static function modelForClass($modelClass, \PDO $pdo)
    {
        $class = ltrim($modelClass, '\\');
        if (!isset(self::$models[$class]) || self::$models[$class]['pdo'] !== $pdo) {
            self::$models[$class] = ['pdo' => $pdo, 'model' => self::construct($class, $pdo)];
        }
        $model = self::$models[$class]['model'];
        return $model === false ? null : $model;
    }

    /**
     * @param string $class The model's class
     * @param \PDO $pdo A connection to construct the model with
     * @return Model|false false where the class is not a model this can build
     */
    private static function construct($class, \PDO $pdo)
    {
        if (!class_exists($class) || !is_a($class, Model::class, true)) {
            return false;
        }
        try {
            return new $class($pdo);
        } catch (\Throwable $e) {
            // A model with a constructor this cannot satisfy is not a failure to
            // report: the derived name is still available, and it is what the caller
            // would have used anyway.
            error_log("Anorm: could not construct `$class` to read its table: " . $e->getMessage());
            return false;
        }
    }
}
