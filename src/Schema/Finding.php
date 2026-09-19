<?php

namespace Anorm\Schema;

/**
 * One difference between the live schema and what a model implies.
 *
 * A finding is a line on a checklist for a human doing the dump-and-correct step, not
 * a verdict. Its severity says how confident that reading is: an error is a column
 * that cannot hold what the model puts in it, a warning is a difference that may well
 * be deliberate, and info is context.
 */
class Finding
{
    /** The live column cannot hold what the model implies. */
    public const ERROR = 'error';

    /** A difference that may be deliberate, and is worth a human's eye. */
    public const WARNING = 'warning';

    /** Context: nothing is wrong, but the diff can see it. */
    public const INFO = 'info';

    /** @var string One of ERROR, WARNING, INFO */
    public $severity;

    /** @var string A kind, e.g. 'type_mismatch', 'missing_column', 'missing_foreign_key' */
    public $kind;

    /** @var string The table the finding is about */
    public $table;

    /** @var string|null The column, where the finding is about one */
    public $column;

    /** @var string What is wrong, in a sentence a human can act on */
    public $message;

    /** @var string|null The live column type, where there is one */
    public $live;

    /** @var string|null The definition the model implies, where there is one */
    public $implied;

    /**
     * @param string $severity One of ERROR, WARNING, INFO
     * @param string $kind A kind
     * @param string $table The table
     * @param string|null $column The column, if any
     * @param string $message What is wrong
     * @param string|null $live The live column type
     * @param string|null $implied The implied definition
     */
    public function __construct($severity, $kind, $table, $column, $message, $live = null, $implied = null)
    {
        $this->severity = $severity;
        $this->kind = $kind;
        $this->table = $table;
        $this->column = $column;
        $this->message = $message;
        $this->live = $live;
        $this->implied = $implied;
    }

    /**
     * @return bool
     */
    public function isError()
    {
        return $this->severity === self::ERROR;
    }

    /**
     * The finding as one line, the way the CLI prints it.
     * @return string
     */
    public function describe()
    {
        $where = $this->column === null ? $this->table : $this->table . '.' . $this->column;
        return $where . ' — ' . $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'severity' => $this->severity,
            'kind' => $this->kind,
            'table' => $this->table,
            'column' => $this->column,
            'message' => $this->message,
            'live' => $this->live,
            'implied' => $this->implied,
        ];
    }
}
