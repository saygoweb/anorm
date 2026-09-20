<?php

// phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
// phpcs:disable Generic.Commenting.Todo.TaskFound

namespace Anorm;

use Anorm\Schema\ColumnIntent;
use Anorm\Schema\ForeignKeyWriter;

class TableMaker
{
    public static function fix(\Exception $exception, DataMapper $mapper, $model = null)
    {
        // TODO We could create this from an Anorm factory / container
        $maker = new TableMaker($exception, $mapper, $model);
        return $maker->_fix();
    }

    /** @var \Exception The exception that requires the database schema to be fixed. */
    public $exception;

    /** @var DataMapper The DataMapper */
    private $mapper;

    /** @var Model|null An optional model instance */
    private $model;

    public function __construct(\Exception $exception, DataMapper $mapper, $model)
    {
        $this->exception = $exception;
        $this->mapper = $mapper;
        $this->model = $model;
    }

    private function _fix()
    {
        switch ($this->exception->getCode()) {
            case '42S02': // table not found
                $this->createTable();
                break;
            case '42S22': // column not found
                $this->createColumn();
                break;
            case '23000': // integrity constraint violation (foreign key)
                $this->handleForeignKeyConstraint();
                break;
            case 'HY000': // general error (can include foreign key issues)
                if (strpos($this->exception->getMessage(), 'foreign key constraint') !== false) {
                    $this->handleForeignKeyConstraint();
                } else {
                    throw $this->exception;
                }
                break;
        }
    }

    private function createTable()
    {
        // Regex the message to get the name of the table
        $matches = [];
        if (!\preg_match("/'([^\.']*)\.([^\.']*)'/", $this->exception->getMessage(), $matches)) {
            throw new \Exception('Anorm: Could not parse PDOException', 0, $this->exception);
        }
        $tableName = $matches[2];
        // Create the table with an auto increment id as primary key.
        // Review: Should we also try and create all the columns we can now,
        // or wait until possibly later when we might have better data
        // to hint the type?
        // Current design choice is to wait until later even if it means
        // a highly iterative, multiple exception approach on the common
        // first write case.
        $sql = "CREATE TABLE `$tableName`(
            id INT(11) AUTO_INCREMENT PRIMARY KEY
        )";
        $this->mapper->pdo->query($sql);

        // After creating the table, try to create foreign key constraints
        // if this model has relationships defined
        $this->createForeignKeyConstraintsFromModel();
    }

    private function createColumn()
    {
        // Regex the message to get the name of the table
        $matches = [];
        if (!\preg_match("/column '([^\.']*)'/", $this->exception->getMessage(), $matches)) {
            throw new \Exception('Anorm: Could not parse PDOException', 0, $this->exception);
        }
        $columnName = $matches[1];
        $columnDefinition = $this->columnDefinitionFor($columnName);
        $sql = "ALTER TABLE `" . $this->mapper->table . "` ADD $columnName $columnDefinition";
        $this->mapper->pdo->query($sql);
    }

    /**
     * Decide the definition of a column that does not exist yet.
     *
     * The decision itself lives in ColumnIntent, because a schema diff has to be able
     * to ask the same question without creating anything, and the two answers must not
     * be allowed to drift apart.
     *
     * @param string $columnName Name of the column being created
     * @return string A column definition for ALTER TABLE ... ADD
     */
    private function columnDefinitionFor($columnName)
    {
        return ColumnIntent::forColumn($this->mapper, $this->model, $columnName)->definition;
    }

    /**
     * Handle foreign key constraint violations by creating missing foreign keys
     */
    private function handleForeignKeyConstraint()
    {
        // Check if this is a missing foreign key constraint
        // Whether the row was rejected by a constraint that exists or by one that was
        // never created, the answer is the same: create whatever the model declares
        // and is missing.
        $this->createForeignKeyConstraintsFromModel();
    }

    /**
     * Create foreign key constraints from model relationship definitions
     *
     * @return void
     */
    private function createForeignKeyConstraintsFromModel()
    {
        if (!$this->model || !property_exists($this->model, '_relationshipManager')) {
            return;
        }

        $writer = new ForeignKeyWriter($this->mapper->pdo);
        $writer->createFromRelationships($this->mapper, $this->model->_relationshipManager->getRelationships());
    }

    /**
     * True when a failed ADD CONSTRAINT means the constraint is already there.
     *
     * Re-running dynamic schema creation has to stay harmless, so a duplicate
     * constraint name is tolerated. Everything else is not: errno 150 in
     * particular means the column types do not match, and swallowing it leaves a
     * declared relationship with no constraint and nothing to notice it by.
     *
     * MariaDB reports both cases as 1005 / SQLSTATE HY000 and separates them only
     * by the InnoDB errno in the message text (121 duplicate, 150 incorrectly
     * formed), so the message is part of the signal here, not just the code.
     * MySQL 8 reports the duplicate as 1826 instead.
     *
     * @param \PDOException $e The exception raised by ADD CONSTRAINT
     * @return bool
     */
    public static function isDuplicateConstraintError(\PDOException $e)
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        // 1826 duplicate foreign key constraint name, 1061 duplicate key name.
        if ($driverCode === 1826 || $driverCode === 1061) {
            return true;
        }
        $message = isset($e->errorInfo[2]) ? $e->errorInfo[2] : $e->getMessage();
        if ($driverCode === 1005 && \preg_match('/errno:\s*121\b/', $message) === 1) {
            return true;
        }
        return \strpos($message, 'Duplicate foreign key constraint name') !== false;
    }

    /**
     * Guess a column definition from one sampled value.
     *
     * A SQL type cannot be inferred correctly from a PHP value — an int does not say
     * INT or BIGINT, a string does not say VARCHAR(n), TEXT or DATETIME — so this is
     * deliberately a best guess for development. Production is expected to run
     * MODE_STATIC against a schema that has been dumped and corrected by hand.
     * See docs/_docs/schema-modes.md.
     *
     * A type the model declares is not a guess, so it is preferred where there is one;
     * the sample then refines what the declaration leaves open, such as an int's width.
     *
     * @param string $columnName Name of the column being created
     * @param mixed $sampleData The value the column is being guessed from
     * @param string|null $declaredType The type the model declares, as PropertyType returns it
     * @return string A column definition for ALTER TABLE ... ADD
     */
    public static function columnDefinition($columnName, $sampleData, $declaredType = null)
    {
        if ($declaredType !== null) {
            $declared = self::definitionFromDeclaredType($declaredType, $sampleData);
            if ($declared !== null) {
                return $declared;
            }
        }
        // Only null is an absence of information. 0, 0.0, '' and false are information,
        // and a truthiness test used to discard them along with it.
        if ($sampleData !== null) {
            if (\is_bool($sampleData)) {
                return "TINYINT(1) NULL";
            }
            if (\is_integer($sampleData)) {
                return self::integerDefinition($sampleData);
            }
            if (\is_float($sampleData)) {
                return "DOUBLE NULL";
            }
            // Legacy, and a mistake we are keeping for now rather than defending.
            // `Moment\Moment` is in neither `require` nor `require-dev`, so this
            // string-matches a class the core does not depend on, cannot autoload and
            // has no test for — to guess at something a transformer would simply know.
            // The idiomatic spelling is a consumer-defined MomentTransform declaring
            // `DATETIME NULL` through ColumnTypeHintInterface, which outranks this, so
            // for anyone following that convention this branch never fires. It stays
            // because removing it would take a Moment property with no transformer
            // from DATETIME to VARCHAR(128): a deprecation, not a cleanup.
            // @see docs/_docs/transformers.md
            if (is_object($sampleData) && get_class($sampleData) == 'Moment\Moment') {
                return "DATETIME NULL";
            }
            if (is_string($sampleData)) {
                return self::stringDefinition($sampleData);
            }
        }
        return 'VARCHAR(128)';
    }

    /**
     * A column definition from a type the model declares, refined by the sample where
     * the declaration leaves a choice: an `int` does not say INT or BIGINT, and a
     * `string` does not say how wide.
     *
     * @param string $declaredType A type as PropertyType returns it
     * @param mixed $sampleData The value sampled from the model, which may be null
     * @return string|null null where the declaration says nothing a column can use
     */
    private static function definitionFromDeclaredType($declaredType, $sampleData)
    {
        switch ($declaredType) {
            case 'bool':
                return "TINYINT(1) NULL";
            case 'int':
                return self::integerDefinition(self::magnitude($sampleData));
            case 'float':
                return "DOUBLE NULL";
            case 'array':
                // An array reaches the database encoded, and encoded is not a VARCHAR.
                return "TEXT";
            case 'string':
                return self::stringDefinition($sampleData);
        }
        // `DateTimeInterface` is PHP's own, and reading a date column off it is fair
        // inference. The `Moment\Moment` half is the same regretted special case as in
        // columnDefinition() above, on the same terms: a MomentTransform is the good
        // way, and this is kept only so that removing it is a deprecation rather than
        // a silent retyping of anyone's column.
        if ($declaredType === 'Moment\Moment' || \is_a($declaredType, \DateTimeInterface::class, true)) {
            return "DATETIME NULL";
        }
        // Any other class: what it stores is its transformer's business, not the
        // type's — which is the rule the Moment case above breaks, and why it is the
        // only third-party class name written into this file.
        return null;
    }

    /**
     * @param int|null $value The magnitude to hold, or null where none is known
     * @return string
     */
    private static function integerDefinition($value)
    {
        // INT(11) stops at 2147483647, so a byte count or any other large
        // magnitude has to widen or it is rejected — or silently clamped.
        return ($value !== null && ($value > 2147483647 || $value < -2147483648))
            ? "BIGINT(20) NULL"
            : "INT(11) NULL";
    }

    /**
     * The magnitude a sample implies, for a column already known to be an integer.
     *
     * @param mixed $sampleData
     * @return int|null
     */
    private static function magnitude($sampleData)
    {
        if (\is_int($sampleData)) {
            return $sampleData;
        }
        // Everything PDO returns is a string, so a read path samples '10737418240'.
        if (\is_string($sampleData) && \preg_match('/^-?\d+$/', $sampleData) === 1) {
            return (int) $sampleData;
        }
        return null;
    }

    /**
     * @param mixed $sampleData The value sampled from the model, which may be null
     * @return string
     */
    private static function stringDefinition($sampleData)
    {
        if (is_string($sampleData)) {
            // The whole value has to be a date. A string that merely contains one —
            // an error message, a note, a URL — is not a DATETIME, and typing it as
            // one destroys every later write to that column.
            if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $sampleData) === 1) {
                return "DATETIME NULL";
            }
            if (strlen($sampleData) > 256) {
                return "TEXT";
            }
            if (strlen($sampleData) > 128) {
                return "VARCHAR(256)";
            }
        }
        return 'VARCHAR(128)';
    }
}
