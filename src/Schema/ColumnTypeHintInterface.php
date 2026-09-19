<?php

namespace Anorm\Schema;

/**
 * Implemented by a transformer that knows what it writes.
 *
 * A transformer is the one part of a mapper that has already decided the storage
 * format — a date formatted `Y-m-d H:i:s`, an array encoded as JSON — so in dynamic
 * mode it can say what column that format needs instead of leaving the type to be
 * guessed from a sampled value.
 *
 * Separate from TransformInterface so that implementing it stays optional: a
 * transformer that does not implement it is unaffected, and so is every transformer
 * written before this existed.
 *
 * @see \Anorm\TableMaker
 */
interface ColumnTypeHintInterface
{
    /**
     * The column definition this transformer's output needs, e.g. 'DATETIME NULL',
     * or null where the transformer's output has no fixed type.
     *
     * @return string|null
     */
    public function sqlColumnType();
}
