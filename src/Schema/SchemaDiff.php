<?php

namespace Anorm\Schema;

use Anorm\Model;

/**
 * Compares the live schema against what a model implies, and reports the differences.
 *
 * This exists because of how Anorm is meant to be used: develop with MODE_DYNAMIC,
 * dump the database, correct the dump by hand, commit it, and switch to MODE_STATIC.
 * The dump inherits whatever inference produced, so the correcting step decides what
 * the authoritative schema ends up being — and until now that step was a human reading
 * SHOW CREATE TABLE and noticing. This gives them a checklist instead.
 *
 * It is a checklist, not an oracle. A SQL type cannot be inferred correctly from a PHP
 * value, so a difference between the live column and the one a model implies is
 * frequently the *correction* rather than the drift. Severity says how confident the
 * reading is, and anything the model does not actually assert is not reported at all.
 *
 * @see https://github.com/saygoweb/anorm/issues/61
 * @see \Anorm\Schema\ColumnIntent for what "the model implies" means
 */
class SchemaDiff
{
    /** @var SchemaInspector */
    private $inspector;

    /** @var array<string, Model> Model class name => model instance, for foreign key targets */
    private $modelsByClass = [];

    /**
     * Family pairs that are a difference rather than an error, keyed "implied:live".
     * Everything else that differs is an error: the column cannot hold what the model
     * puts in it, or the model cannot hold what the column returns.
     *
     * @var array<string, string>
     */
    private const TOLERATED = [
        'int:bool' => 'a TINYINT(1) holds small numbers, but only just',
        'bool:int' => 'a flag in a wider integer column',
        'int:float' => 'a fractional column holding whole numbers',
        'string:bool' => 'a flag the model treats as text',
        'float:bool' => 'a flag the model treats as a number',
        'bool:float' => 'a flag in a fractional column',
        'datetime:string' => 'dates stored as text',
    ];

    /**
     * @param SchemaInspector $inspector Reads the live schema
     * @param array<string, Model> $modelsByClass Model class name => instance, used to
     *        resolve the table and key a relationship points at. Anything missing is
     *        reported as unchecked rather than guessed at.
     */
    public function __construct(SchemaInspector $inspector, array $modelsByClass = [])
    {
        $this->inspector = $inspector;
        $this->modelsByClass = $modelsByClass;
    }

    /**
     * Every difference between $model's table and what $model implies it should be.
     *
     * @param Model $model A model instance
     * @return Finding[]
     */
    public function forModel(Model $model)
    {
        $mapper = $model->_mapper;
        $table = $mapper->table;

        if (!$this->inspector->tableExists($table)) {
            return [new Finding(
                Finding::ERROR,
                'missing_table',
                $table,
                null,
                'table does not exist; the model maps ' . count($mapper->map) . ' columns to it'
            )];
        }

        $findings = [];
        $liveColumns = $this->inspector->columns($table);
        $mappedColumns = [];

        foreach ($mapper->map as $property => $column) {
            $mappedColumns[$column] = true;
            if ($property[0] === '_' || in_array($property, $mapper->infrastructureProperties, true)) {
                continue;
            }
            // The primary key is Anorm's own: dynamic mode creates it with the table
            // rather than inferring it, and a model rarely declares anything about it.
            // Comparing it would report every model's `public $id` as uninformative.
            if ($property === $mapper->modelPrimaryKey) {
                continue;
            }
            $intent = ColumnIntent::forColumn($mapper, $model, $column);
            if (!isset($liveColumns[$column])) {
                $findings[] = new Finding(
                    Finding::ERROR,
                    'missing_column',
                    $table,
                    $column,
                    'is not in the database; the model implies ' . $intent->definition,
                    null,
                    $intent->definition
                );
                continue;
            }
            $finding = $this->compareColumn($table, $column, $liveColumns[$column]['type'], $intent);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        foreach ($liveColumns as $column => $details) {
            if (!isset($mappedColumns[$column])) {
                $findings[] = new Finding(
                    Finding::INFO,
                    'extra_column',
                    $table,
                    $column,
                    'is in the database but not in the model',
                    $details['type']
                );
            }
        }

        return array_merge($findings, $this->foreignKeyFindings($model, $table));
    }

    /**
     * @param string $table
     * @param string $column
     * @param string $liveType The column type the database reports
     * @param ColumnIntent $intent What the model says the column should be
     * @return Finding|null
     */
    private function compareColumn($table, $column, $liveType, ColumnIntent $intent)
    {
        $temporal = $this->temporalTextFinding($table, $column, $liveType, $intent);
        if ($temporal !== null) {
            return $temporal;
        }

        if (!$intent->isInformed()) {
            // The model declares no type, holds no value and pins nothing. There is
            // nothing to compare against, and saying so is more useful than silence:
            // this is the column a dump cannot be checked for.
            return new Finding(
                Finding::INFO,
                'no_information',
                $table,
                $column,
                'the model says nothing about this column — no declared type, no value, no pin',
                $liveType,
                null
            );
        }

        $implied = $intent->definition;
        $impliedFamily = SqlType::family($implied);
        $liveFamily = SqlType::family($liveType);

        if ($impliedFamily !== $liveFamily) {
            return $this->familyFinding($table, $column, $liveType, $implied, $impliedFamily, $liveFamily, $intent);
        }

        // Widths are only compared where the model actually asserts one. A declared
        // `int` does not ask for INT over SMALLINT, and a declared `string` does not
        // ask for 128 characters — those are the guess's defaults, not intent.
        if ($intent->source === ColumnIntent::SOURCE_DECLARATION) {
            return null;
        }
        if ($impliedFamily === 'int' && SqlType::integerRank($implied) > SqlType::integerRank($liveType)) {
            return new Finding(
                Finding::ERROR,
                'type_mismatch',
                $table,
                $column,
                'is ' . $this->spell($liveType) . ', model implies ' . $this->spell($implied)
                . ' — the column is too narrow for what the model writes',
                $liveType,
                $implied
            );
        }
        if ($impliedFamily === 'string') {
            $impliedLength = SqlType::stringLength($implied);
            $liveLength = SqlType::stringLength($liveType);
            if ($impliedLength !== null && $liveLength !== null && $impliedLength > $liveLength) {
                return new Finding(
                    Finding::WARNING,
                    'type_mismatch',
                    $table,
                    $column,
                    'is ' . $this->spell($liveType) . ', model implies ' . $this->spell($implied)
                    . ' — a value the model has written would not fit',
                    $liveType,
                    $implied
                );
            }
        }
        return null;
    }

    /**
     * A column named as though it held a date, in a column type that holds text.
     *
     * This is the one drift the rest of this class cannot see. A property declared
     * `/** @var string *``/` that holds an ISO datetime implies `VARCHAR`, the live
     * column is `VARCHAR`, and the two agree — so comparing types reports nothing,
     * however wrong the column is.
     *
     * It is worth saying anyway because the consequence is silent. `VARCHAR` accepts
     * every value `DATETIME` would, so writes and reads keep working and the
     * application behaves correctly until something sorts or ranges on the column, at
     * which point it sorts as text: `'2026-9-1'` comes after `'2026-10-01'` and
     * nothing errors.
     *
     * A name is weaker evidence than a type, so this is only ever INFO, and it defers
     * to anything the model states outright — including a transformer, which settles
     * the question properly and silences this. That is the point of it: a schema is
     * audited before its models have been fixed, and this is the finding that says
     * which columns are worth fixing them for.
     *
     * @param string $table
     * @param string $column
     * @param string $liveType
     * @param ColumnIntent $intent
     * @return Finding|null
     * @see https://github.com/saygoweb/anorm/issues/67
     */
    private function temporalTextFinding($table, $column, $liveType, ColumnIntent $intent)
    {
        if (SqlType::family($liveType) !== 'string') {
            return null;
        }
        // A pin is the author saying what the column is. A guess from its name does
        // not get to argue with that, or the pin is worth less than nothing.
        if ($intent->source === ColumnIntent::SOURCE_PIN) {
            return null;
        }
        // Where the model implies something other than text, comparing the types
        // already has a stronger and better-founded thing to say.
        if ($intent->isInformed() && SqlType::family($intent->definition) !== 'string') {
            return null;
        }
        if (!self::readsAsTemporal($column)) {
            return null;
        }
        $message = ' and is named as though it held a date — text sorts lexicographically, so ORDER BY and BETWEEN are wrong.';
        $remedy = ' A date transformer settles the column and the value together.';
        return new Finding(
            Finding::INFO,
            'temporal_column_as_text',
            $table,
            $column,
            'is ' . $this->spell($liveType) . $message . $remedy,
            $liveType,
            null
        );
    }

    /**
     * Whether a column name reads as a date or a time.
     *
     * Deliberately a short list. A heuristic that fires often is one a reader learns
     * to skip, and this one is guessing from a name.
     *
     * @param string $column
     * @return bool
     */
    private static function readsAsTemporal($column)
    {
        $name = strtolower($column);
        // `dtc` and `dtu` are the created/updated timestamps conventional in Anorm
        // consumers, which no suffix rule would catch.
        if (in_array($name, ['dtc', 'dtu', 'date', 'datetime', 'timestamp'], true)) {
            return true;
        }
        foreach (['_at', '_date', '_time', '_datetime', '_timestamp'] as $suffix) {
            if (substr($name, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * The live column and the implied one are different kinds of thing.
     *
     * @param string $table
     * @param string $column
     * @param string $liveType
     * @param string $implied
     * @param string $impliedFamily
     * @param string $liveFamily
     * @param ColumnIntent $intent
     * @return Finding|null
     */
    private function familyFinding($table, $column, $liveType, $implied, $impliedFamily, $liveFamily, ColumnIntent $intent)
    {
        $pair = $impliedFamily . ':' . $liveFamily;

        // A model that declares `string` says nothing about whether the column is a
        // date: everything PDO returns is a string, and a generated model documents a
        // DATETIME column as `?string`. A *sampled* string is different — that is a
        // value the model has actually written into a date column.
        if ($pair === 'string:datetime') {
            if ($intent->source !== ColumnIntent::SOURCE_SAMPLE) {
                return null;
            }
            return new Finding(
                Finding::WARNING,
                'type_mismatch',
                $table,
                $column,
                'is ' . $this->spell($liveType)
                . ' while the model writes text into it — any value that is not a date will be rejected',
                $liveType,
                $implied
            );
        }

        if (isset(self::TOLERATED[$pair])) {
            return new Finding(
                Finding::WARNING,
                'type_mismatch',
                $table,
                $column,
                'is ' . $this->spell($liveType) . ', model implies ' . $this->spell($implied)
                . ' — ' . self::TOLERATED[$pair],
                $liveType,
                $implied
            );
        }

        return new Finding(
            Finding::ERROR,
            'type_mismatch',
            $table,
            $column,
            'is ' . $this->spell($liveType) . ', model implies ' . $this->spell($implied),
            $liveType,
            $implied
        );
    }

    /**
     * Foreign keys the model's relationships declare, against the constraints the
     * database has.
     *
     * @param Model $model
     * @param string $table
     * @return Finding[]
     */
    private function foreignKeyFindings(Model $model, $table)
    {
        $mapper = $model->_mapper;
        $findings = [];
        $liveKeys = $this->inspector->foreignKeys($table);

        foreach ($model->_relationshipManager->getRelationships() as $relationship) {
            // Only belongsTo puts a foreign key on this model's own table.
            if ($relationship->getType() !== 'manyHasOne') {
                continue;
            }
            $column = RelationshipSchema::column($mapper, $relationship->getForeignKey());
            if (isset($liveKeys[$column])) {
                continue;
            }

            $relatedModel = $this->relatedModel($relationship->getRelatedModelClass());
            if ($relatedModel === null) {
                $findings[] = new Finding(
                    Finding::INFO,
                    'foreign_key_unchecked',
                    $table,
                    $column,
                    'has no foreign key constraint, and ' . $relationship->getRelatedModelClass()
                    . ' was not available to check what it should point at'
                );
                continue;
            }

            $relatedMapper = $relatedModel->_mapper;
            $referencedColumn = RelationshipSchema::column($relatedMapper, $relationship->getPrimaryKey());
            $liveType = $this->inspector->columnType($table, $column);
            $referencedType = $this->inspector->columnType($relatedMapper->table, $referencedColumn);
            $reference = '`' . $relatedMapper->table . '`.`' . $referencedColumn . '`';

            if ($liveType !== null && $referencedType !== null && !$this->keyTypesMatch($liveType, $referencedType)) {
                $findings[] = new Finding(
                    Finding::ERROR,
                    'foreign_key_type_mismatch',
                    $table,
                    $column,
                    'is ' . $this->spell($liveType) . ' and cannot be constrained against '
                    . $reference . ', which is ' . $this->spell($referencedType),
                    $liveType,
                    $referencedType
                );
                continue;
            }

            $findings[] = new Finding(
                Finding::WARNING,
                'missing_foreign_key',
                $table,
                $column,
                'has no foreign key constraint for the declared `' . $relationship->getPropertyName()
                . '` relationship to ' . $reference
                . ' (expected `' . $relationship->getConstraintName($table, $relatedMapper->table, $column) . '`)',
                $liveType,
                $referencedType
            );
        }
        return $findings;
    }

    /**
     * Whether two columns can be joined by a foreign key constraint at all. MySQL
     * requires the types to match, not merely to be compatible.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private function keyTypesMatch($a, $b)
    {
        if (SqlType::family($a) !== SqlType::family($b)) {
            return false;
        }
        return SqlType::integerRank($a) === SqlType::integerRank($b);
    }

    /**
     * @param string $class Model class name
     * @return Model|null The model instance, where one was supplied
     */
    private function relatedModel($class)
    {
        $class = ltrim($class, '\\');
        return isset($this->modelsByClass[$class]) ? $this->modelsByClass[$class] : null;
    }

    /**
     * Column types read better upper case in a sentence, the way a schema writes them.
     *
     * @param string $type
     * @return string
     */
    private function spell($type)
    {
        return strtoupper(trim($type));
    }
}
