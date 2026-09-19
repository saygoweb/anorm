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

    /**
     * The family a column type belongs to, for comparing two column types without
     * demanding they be spelled the same.
     *
     * Finer than toPhpType() in one place: a date reaches a model as a string, but a
     * DATETIME column and a VARCHAR column are not the same decision, and a schema
     * diff has to be able to say so.
     *
     * @param string $columnType A column type, e.g. 'int(11) unsigned' or 'DATETIME NULL'
     * @return string 'int', 'float', 'bool', 'datetime' or 'string'
     */
    public static function family($columnType)
    {
        $type = \strtolower(\trim((string) $columnType));
        if (\preg_match('/^(date|datetime|timestamp|time)\b/', $type) === 1) {
            return 'datetime';
        }
        return self::toPhpType($type);
    }

    /**
     * How wide an integer column is, as a rank rather than a number of digits, since
     * the display width in `int(11)` says nothing about the range.
     *
     * @param string $columnType A column type
     * @return int 0 where the type is not an integer type
     */
    public static function integerRank($columnType)
    {
        $type = \strtolower(\trim((string) $columnType));
        $ranks = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'bigint' => 5];
        foreach ($ranks as $name => $rank) {
            if (\preg_match('/^' . $name . '\b/', $type) === 1) {
                return $rank;
            }
        }
        return 0;
    }

    /**
     * How many characters a string column holds, with TEXT and its larger relatives
     * reported as their real capacity so they compare with a VARCHAR.
     *
     * @param string $columnType A column type
     * @return int|null null where the type has no length
     */
    public static function stringLength($columnType)
    {
        $type = \strtolower(\trim((string) $columnType));
        if (\preg_match('/^(tinytext|tinyblob)\b/', $type) === 1) {
            return 255;
        }
        if (\preg_match('/^(mediumtext|mediumblob)\b/', $type) === 1) {
            return 16777215;
        }
        if (\preg_match('/^(longtext|longblob)\b/', $type) === 1) {
            return 4294967295;
        }
        if (\preg_match('/^(text|blob)\b/', $type) === 1) {
            return 65535;
        }
        if (\preg_match('/^(var)?(char|binary)\((\d+)\)/', $type, $matches) === 1) {
            return (int) $matches[3];
        }
        return null;
    }
}
