<?php

namespace Anorm\Schema;

/**
 * The schema as the database actually has it.
 *
 * Reads columns, primary keys and foreign key constraints from information_schema,
 * and caches each table, because a diff over a set of models asks about the same
 * tables repeatedly — a model's own table, and the table every belongsTo points at.
 *
 * @see \Anorm\Schema\SchemaDiff
 */
class SchemaInspector
{
    /** @var \PDO */
    private $pdo;

    /** @var array<string, array<string, array<string, mixed>>> table => column => details */
    private $columns = [];

    /** @var array<string, array<string, array<string, string>>> table => column => constraint */
    private $foreignKeys = [];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param string $table
     * @return bool
     */
    public function tableExists($table)
    {
        return $this->columns($table) !== [];
    }

    /**
     * Every column of $table, keyed by column name. Each entry has 'type' (the column
     * type as SHOW CREATE TABLE spells it), 'nullable', 'key' and 'default'.
     *
     * @param string $table
     * @return array<string, array<string, mixed>>
     */
    public function columns($table)
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $sql = 'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$table]);
        $columns = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns[$row['COLUMN_NAME']] = [
                'type' => $row['COLUMN_TYPE'],
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'key' => $row['COLUMN_KEY'],
                'default' => $row['COLUMN_DEFAULT'],
            ];
        }
        $this->columns[$table] = $columns;
        return $columns;
    }

    /**
     * The type of one column, or null where the table has no such column.
     *
     * @param string $table
     * @param string $column
     * @return string|null
     */
    public function columnType($table, $column)
    {
        $columns = $this->columns($table);
        return isset($columns[$column]) ? $columns[$column]['type'] : null;
    }

    /**
     * Foreign key constraints on $table, keyed by the constrained column. Each entry
     * has 'constraint', 'referencedTable' and 'referencedColumn'.
     *
     * @param string $table
     * @return array<string, array<string, string>>
     */
    public function foreignKeys($table)
    {
        if (isset($this->foreignKeys[$table])) {
            return $this->foreignKeys[$table];
        }
        $sql = 'SELECT COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$table]);
        $keys = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $keys[$row['COLUMN_NAME']] = [
                'constraint' => $row['CONSTRAINT_NAME'],
                'referencedTable' => $row['REFERENCED_TABLE_NAME'],
                'referencedColumn' => $row['REFERENCED_COLUMN_NAME'],
            ];
        }
        $this->foreignKeys[$table] = $keys;
        return $keys;
    }

    /**
     * Forget what has been read, so a diff run after a schema change sees the change.
     * @return void
     */
    public function clearCache()
    {
        $this->columns = [];
        $this->foreignKeys = [];
    }
}
