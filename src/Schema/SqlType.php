<?php

namespace Anorm\Schema;

/**
 * The PHP type a SQL column type corresponds to.
 *
 * The reverse of the guess TableMaker makes, and just as approximate — but in this
 * direction the information is genuinely there, because the column has been declared.
 * Used to document a generated model with the type its column actually holds.
 *
 * @see \Anorm\Tools\ModelMaker
 * @see \Anorm\TableMaker::columnDefinition for the direction that has to guess
 */
class SqlType
{
    /**
     * @param string $columnType A column type as SHOW COLUMNS reports it,
     *                           e.g. 'int(11) unsigned', 'tinyint(1)', 'decimal(10,2)'
     * @return string 'int', 'float', 'bool' or 'string'
     */
    public static function toPhpType($columnType)
    {
        $type = \strtolower(\trim((string) $columnType));

        // tinyint(1) is how MySQL spells a boolean, and only that width.
        if (\strpos($type, 'tinyint(1)') === 0) {
            return 'bool';
        }
        if (\preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|bit|year)\b/', $type) === 1) {
            return 'int';
        }
        // DECIMAL arrives from PDO as a string to keep its precision; float is what a
        // model does arithmetic on, and is what the column means.
        if (\preg_match('/^(decimal|numeric|float|double|real|fixed)\b/', $type) === 1) {
            return 'float';
        }
        // Dates, text, blobs, enums and JSON all reach a model as strings unless a
        // transformer says otherwise, and a transformer is not the model's declaration.
        return 'string';
    }
}
