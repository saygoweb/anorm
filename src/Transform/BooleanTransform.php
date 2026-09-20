<?php

namespace Anorm\Transform;

use Anorm\Schema\ColumnTypeHintInterface;
use Anorm\TransformInterface;

/**
 * A PHP boolean, stored as MySQL stores one.
 *
 * SQL has no boolean. MySQL spells it `TINYINT(1)`, holding 0 or 1, and PDO hands it
 * back as the string `'0'` or `'1'` — so a model that writes `true` and reads the
 * property back does not get `true`. Saying which column holds a flag, and what the
 * two values mean at each end, is a transformer's job, the same as a date's format or
 * an array's encoding:
 *
 * ```php
 * $mapper->transformers = ['email_verified' => new BooleanTransform()];
 * ```
 *
 * Declaring the property `?bool` gets most of the way there on its own — PHP coerces
 * `'0'` to `false` on assignment to a typed property, and dynamic mode types the
 * column from the declaration. This is for when the declaration is not available or
 * not enough: a docblock-typed property, a legacy model, or a schema where the storage
 * format should be stated rather than inferred.
 *
 * @see \Anorm\Schema\ColumnIntent for where sqlColumnType() is consulted
 */
class BooleanTransform implements TransformInterface, ColumnTypeHintInterface
{
    /**
     * @param mixed $value The column value, as PDO returns it
     * @return bool|null
     */
    public function txDatabaseToModel($value)
    {
        // NULL is a third state — "not answered" — and collapsing it to false loses it.
        if ($value === null) {
            return null;
        }
        return (bool) (int) $value;
    }

    /**
     * @param bool|null $value The property value
     * @return int|null
     */
    public function txModelToDatabase($value)
    {
        if ($value === null) {
            return null;
        }
        return $value ? 1 : 0;
    }

    /**
     * A flag needs the column MySQL spells a boolean with, whatever value was sampled
     * first — and `TINYINT(1)` is the only width MySQL reads back as one.
     *
     * @return string|null
     */
    public function sqlColumnType()
    {
        return 'TINYINT(1) NULL';
    }
}
