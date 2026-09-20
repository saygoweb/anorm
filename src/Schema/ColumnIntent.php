<?php

namespace Anorm\Schema;

use Anorm\Anorm;
use Anorm\DataMapper;

/**
 * What a mapper and a model say a column should be, and on what grounds.
 *
 * This is the one place that decides, so that the column dynamic mode creates and the
 * column a schema diff says the model implies cannot drift apart: both ask this.
 *
 * The grounds matter as much as the answer, and they come in two tiers. A declared
 * property type or a sampled value is something Anorm *infers* — a reading of evidence,
 * which may be right and cannot be certain. A transformer is something it *knows*: a
 * transformer has already decided the storage format, so it is not evidence about the
 * column type, it is the column type. Knowing is taken above inferring, and both are
 * taken below a column pinned outright.
 *
 * Below all of them is the VARCHAR(128) fallback, which asserts nothing at all — and a
 * diff that treated it like an assertion would report every unannotated property as
 * drift.
 *
 * @see \Anorm\TableMaker for the creating side
 * @see \Anorm\Schema\SchemaDiff for the comparing side
 */
class ColumnIntent
{
    /** An explicit definition on the mapper: the caller has said so outright. */
    public const SOURCE_PIN = 'pin';

    /** A transformer that knows the format it writes. */
    public const SOURCE_TRANSFORMER = 'transformer';

    /** The type the model declares for the property. */
    public const SOURCE_DECLARATION = 'declaration';

    /** A value sampled from the model: a guess, but an informed one. */
    public const SOURCE_SAMPLE = 'sample';

    /** Nothing at all. The definition is the fallback, and asserts nothing. */
    public const SOURCE_NONE = 'none';

    /** @var string The column definition, e.g. 'INT(11) NULL' */
    public $definition;

    /** @var string One of the SOURCE_* constants */
    public $source;

    /** @var string|null The property this column maps to, where the map knows it */
    public $property;

    /**
     * @param string $definition The column definition
     * @param string $source One of the SOURCE_* constants
     * @param string|null $property The property the column maps to
     */
    public function __construct($definition, $source, $property = null)
    {
        $this->definition = $definition;
        $this->source = $source;
        $this->property = $property;
    }

    /**
     * True where the model actually says something about this column, as opposed to
     * having fallen through to the fallback with nothing to offer.
     *
     * @return bool
     */
    public function isInformed()
    {
        return $this->source !== self::SOURCE_NONE;
    }

    /**
     * What $mapper and $model say the named column should be.
     *
     * @param DataMapper $mapper The mapper the column belongs to
     * @param mixed $model The model instance, or null where there is none to sample
     * @param string $columnName The column
     * @return ColumnIntent
     */
    public static function forColumn(DataMapper $mapper, $model, $columnName)
    {
        // 1. An explicit definition beats any guess. Per mapper rather than global, so
        // pinning a column in one table does not pin the same name everywhere.
        // See Anorm::$columnFn for the global fallback.
        if (isset($mapper->columnDefinitions[$columnName])) {
            return new self($mapper->columnDefinitions[$columnName], self::SOURCE_PIN, self::property($mapper, $columnName));
        }

        // 2. A transformer has already decided how the value is stored. Transformers
        // are keyed by column name, so this is available even with no model in hand.
        if (isset($mapper->transformers[$columnName])) {
            $transformer = $mapper->transformers[$columnName];
            if ($transformer instanceof ColumnTypeHintInterface) {
                $hint = $transformer->sqlColumnType();
                if ($hint !== null) {
                    return new self($hint, self::SOURCE_TRANSFORMER, self::property($mapper, $columnName));
                }
            }
        }

        // 3 and 4. Read what the model declares the property to be, and sample its
        // value. A column the map does not mention can offer neither. An uninitialised
        // typed property has no value to sample, which is not an error — its
        // declaration is the information here.
        $property = self::property($mapper, $columnName);
        $sampleData = null;
        $declaredType = null;
        if ($property !== null && is_object($model)) {
            $sampleData = isset($model->$property) ? $model->$property : null;
            $declaredType = PropertyType::forProperty($model, $property);
        }

        // A replacement columnFn is the consumer's own decision and stays in charge of
        // the guess: it is handed the declared type and may use or ignore it, as one
        // written before this existed does — PHP discards extra arguments to a
        // user-defined callable.
        $columnFn = Anorm::$columnFn; // Redundant, but can't do this Anorm::$columnFn(...)
        $definition = $columnFn($columnName, $sampleData, $declaredType);

        if ($declaredType !== null) {
            $source = self::SOURCE_DECLARATION;
        } elseif ($sampleData !== null) {
            $source = self::SOURCE_SAMPLE;
        } else {
            $source = self::SOURCE_NONE;
        }
        return new self($definition, $source, $property);
    }

    /**
     * The property a column maps to, or null where the map does not mention it.
     *
     * @param DataMapper $mapper
     * @param string $columnName
     * @return string|null
     */
    private static function property(DataMapper $mapper, $columnName)
    {
        $invertMap = array_flip($mapper->map);
        return isset($invertMap[$columnName]) ? $invertMap[$columnName] : null;
    }
}
