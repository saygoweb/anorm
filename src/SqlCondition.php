<?php

namespace Anorm;

/**
 * Represents a SQL condition with its bindings
 * Used by Mango Query parser to build WHERE clauses safely
 */
class SqlCondition
{
    private string $sql;
    private array $bindings;

    public function __construct(string $sql, array $bindings = [])
    {
        $this->sql = $sql;
        $this->bindings = $bindings;
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Combine this condition with another using the specified operator
     */
    public function combine(SqlCondition $other, string $operator = 'AND'): SqlCondition
    {
        $combinedSql = "({$this->sql}) {$operator} ({$other->getSql()})";
        $combinedBindings = array_merge($this->bindings, $other->getBindings());

        return new SqlCondition($combinedSql, $combinedBindings);
    }

    /**
     * Wrap this condition in parentheses
     */
    public function wrap(): SqlCondition
    {
        return new SqlCondition("({$this->sql})", $this->bindings);
    }

    /**
     * Create an empty condition (always true)
     */
    public static function empty(): SqlCondition
    {
        return new SqlCondition('1=1', []);
    }

    /**
     * Create a condition that's always false
     */
    public static function never(): SqlCondition
    {
        return new SqlCondition('1=0', []);
    }

    /**
     * Check if this condition is empty (always true)
     */
    public function isEmpty(): bool
    {
        return $this->sql === '1=1' && empty($this->bindings);
    }
}
