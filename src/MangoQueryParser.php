<?php

namespace Anorm;

/**
 * Parses Mango Query objects into SQL components
 * Handles conversion of Mango operators to SQL with proper parameter binding
 */
class MangoQueryParser
{
    private DataMapper $mapper;
    private int $paramCounter = 0;

    public function __construct(DataMapper $mapper)
    {
        $this->mapper = $mapper;
    }

    /**
     * Parse a selector object into a SqlCondition
     */
    public function parseSelector(array $selector): SqlCondition
    {
        if (empty($selector)) {
            return SqlCondition::empty();
        }

        return $this->parseConditions($selector);
    }

    /**
     * Parse fields array into SELECT clause
     */
    public function parseFields(array $fields): string
    {
        if (empty($fields)) {
            return '*';
        }

        $mappedFields = [];
        foreach ($fields as $field) {
            $mappedFields[] = $this->mapFieldToColumn($field);
        }

        return implode(', ', $mappedFields);
    }

    /**
     * Parse sort array into ORDER BY clause
     */
    public function parseSort(array $sort): string
    {
        if (empty($sort)) {
            return '';
        }

        $orderClauses = [];
        foreach ($sort as $sortItem) {
            if (is_string($sortItem)) {
                // Simple field name, default to ASC
                $orderClauses[] = $this->mapFieldToColumn($sortItem) . ' ASC';
            } elseif (is_array($sortItem)) {
                // Object format: {"field": "direction"}
                foreach ($sortItem as $field => $direction) {
                    $direction = strtoupper($direction);
                    if (!in_array($direction, ['ASC', 'DESC'])) {
                        $direction = 'ASC';
                    }
                    $orderClauses[] = $this->mapFieldToColumn($field) . ' ' . $direction;
                }
            }
        }

        return implode(', ', $orderClauses);
    }

    /**
     * Parse conditions recursively
     */
    private function parseConditions(array $conditions): SqlCondition
    {
        $sqlConditions = [];

        foreach ($conditions as $key => $value) {
            if ($this->isOperator($key)) {
                // This is an operator
                $sqlConditions[] = $this->parseOperator($key, $value);
            } else {
                // This is a field condition
                $sqlConditions[] = $this->parseFieldCondition($key, $value);
            }
        }

        // Combine all conditions with AND
        return $this->combineConditions($sqlConditions, 'AND');
    }

    /**
     * Check if a key is an operator (starts with $ or is a known operator without $)
     */
    private function isOperator(string $key): bool
    {
        // Check for $ prefix
        if (strpos($key, '$') === 0) {
            return true;
        }

        // Check for operators without $ prefix (for GraphQL compatibility)
        // Use lowercase for case-insensitive comparison
        $operatorsWithoutDollar = [
            'and', 'or', 'not', 'nor', 'eq', 'ne', 'gt', 'gte', 'lt', 'lte',
            'in', 'nin', 'exists', 'type', 'regex', 'beginswith', 'all',
            'elemmatch', 'allmatch', 'size'
        ];

        return in_array(strtolower($key), $operatorsWithoutDollar);
    }

    /**
     * Normalize operator name (remove $ if present and convert to lowercase)
     */
    private function normalizeOperator(string $operator): string
    {
        return strtolower(ltrim($operator, '$'));
    }

    /**
     * Parse an operator
     */
    private function parseOperator(string $operator, $value): SqlCondition
    {
        $normalizedOp = $this->normalizeOperator($operator);

        switch ($normalizedOp) {
            case 'and':
                return $this->parseAndOperator($value);
            case 'or':
                return $this->parseOrOperator($value);
            case 'not':
                return $this->parseNotOperator($value);
            case 'nor':
                return $this->parseNorOperator($value);
            default:
                throw new \InvalidArgumentException("Unsupported operator: {$operator}");
        }
    }

    /**
     * Parse a field condition
     */
    private function parseFieldCondition(string $field, $value): SqlCondition
    {
        $columnName = $this->mapFieldToColumn($field);

        if (is_array($value)) {
            // Value contains operators
            return $this->parseFieldOperators($columnName, $value);
        } else {
            // Simple equality
            return $this->createEqualityCondition($columnName, $value);
        }
    }

    /**
     * Parse operators within a field condition
     */
    private function parseFieldOperators(string $columnName, array $operators): SqlCondition
    {
        $conditions = [];

        foreach ($operators as $operator => $value) {
            if (!$this->isOperator($operator)) {
                throw new \InvalidArgumentException("Invalid operator in field condition: {$operator}");
            }

            $normalizedOp = $this->normalizeOperator($operator);
            $conditions[] = $this->parseFieldOperator($columnName, $normalizedOp, $value);
        }

        return $this->combineConditions($conditions, 'AND');
    }

    /**
     * Parse a specific field operator
     */
    private function parseFieldOperator(string $columnName, string $operator, $value): SqlCondition
    {
        switch ($operator) {
            case 'eq':
                return $this->createEqualityCondition($columnName, $value);
            case 'ne':
                return $this->createComparisonCondition($columnName, '!=', $value);
            case 'gt':
                return $this->createComparisonCondition($columnName, '>', $value);
            case 'gte':
                return $this->createComparisonCondition($columnName, '>=', $value);
            case 'lt':
                return $this->createComparisonCondition($columnName, '<', $value);
            case 'lte':
                return $this->createComparisonCondition($columnName, '<=', $value);
            case 'in':
                return $this->createInCondition($columnName, $value);
            case 'nin':
                return $this->createNotInCondition($columnName, $value);
            case 'exists':
                return $this->createExistsCondition($columnName, $value);
            case 'regex':
                return $this->createRegexCondition($columnName, $value);
            case 'beginswith':
                return $this->createBeginsWithCondition($columnName, $value);
            case 'all':
                return $this->createAllCondition($columnName, $value);
            case 'elemmatch':
                return $this->createElemMatchCondition($columnName, $value);
            case 'size':
                return $this->createSizeCondition($columnName, $value);
            default:
                throw new \InvalidArgumentException("Unsupported field operator: {$operator}");
        }
    }

    /**
     * Map model property name to database column name
     */
    private function mapFieldToColumn(string $field): string
    {
        // Use the DataMapper to convert property names to column names
        if (isset($this->mapper->map[$field])) {
            return '`' . $this->mapper->map[$field] . '`';
        }

        // If not found in map, assume it's already a column name
        return '`' . $field . '`';
    }

    /**
     * Generate a unique parameter name
     */
    private function generateParamName(): string
    {
        return ':mango_param_' . (++$this->paramCounter);
    }

    /**
     * Create an equality condition
     */
    private function createEqualityCondition(string $columnName, $value): SqlCondition
    {
        if ($value === null) {
            return new SqlCondition("{$columnName} IS NULL");
        }

        $paramName = $this->generateParamName();
        return new SqlCondition("{$columnName} = {$paramName}", [$paramName => $value]);
    }

    /**
     * Create a comparison condition
     */
    private function createComparisonCondition(string $columnName, string $operator, $value): SqlCondition
    {
        if ($value === null) {
            $nullOperator = $operator === '!=' ? 'IS NOT NULL' : 'IS NULL';
            return new SqlCondition("{$columnName} {$nullOperator}");
        }

        $paramName = $this->generateParamName();
        return new SqlCondition("{$columnName} {$operator} {$paramName}", [$paramName => $value]);
    }

    /**
     * Create an IN condition
     */
    private function createInCondition(string $columnName, $values): SqlCondition
    {
        if (!is_array($values) || empty($values)) {
            return SqlCondition::never();
        }

        $paramNames = [];
        $bindings = [];
        foreach ($values as $value) {
            $paramName = $this->generateParamName();
            $paramNames[] = $paramName;
            $bindings[$paramName] = $value;
        }

        $paramList = implode(', ', $paramNames);
        return new SqlCondition("{$columnName} IN ({$paramList})", $bindings);
    }

    /**
     * Create a NOT IN condition
     */
    private function createNotInCondition(string $columnName, $values): SqlCondition
    {
        if (!is_array($values) || empty($values)) {
            return SqlCondition::empty();
        }

        $paramNames = [];
        $bindings = [];
        foreach ($values as $value) {
            $paramName = $this->generateParamName();
            $paramNames[] = $paramName;
            $bindings[$paramName] = $value;
        }

        $paramList = implode(', ', $paramNames);
        return new SqlCondition("{$columnName} NOT IN ({$paramList})", $bindings);
    }

    /**
     * Create an EXISTS condition
     */
    private function createExistsCondition(string $columnName, bool $exists): SqlCondition
    {
        if ($exists) {
            return new SqlCondition("{$columnName} IS NOT NULL");
        } else {
            return new SqlCondition("{$columnName} IS NULL");
        }
    }

    /**
     * Parse $and operator
     */
    private function parseAndOperator($conditions): SqlCondition
    {
        if (!is_array($conditions)) {
            throw new \InvalidArgumentException('$and operator requires an array of conditions');
        }

        $sqlConditions = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                throw new \InvalidArgumentException('Each condition in $and must be an array');
            }
            $sqlConditions[] = $this->parseConditions($condition);
        }

        return $this->combineConditions($sqlConditions, 'AND');
    }

    /**
     * Parse $or operator
     */
    private function parseOrOperator($conditions): SqlCondition
    {
        if (!is_array($conditions)) {
            throw new \InvalidArgumentException('$or operator requires an array of conditions');
        }

        $sqlConditions = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                throw new \InvalidArgumentException('Each condition in $or must be an array');
            }
            $sqlConditions[] = $this->parseConditions($condition);
        }

        return $this->combineConditions($sqlConditions, 'OR');
    }

    /**
     * Parse $not operator
     */
    private function parseNotOperator($condition): SqlCondition
    {
        if (!is_array($condition)) {
            throw new \InvalidArgumentException('$not operator requires an array condition');
        }

        $sqlCondition = $this->parseConditions($condition);
        return new SqlCondition("NOT ({$sqlCondition->getSql()})", $sqlCondition->getBindings());
    }

    /**
     * Parse $nor operator
     */
    private function parseNorOperator($conditions): SqlCondition
    {
        if (!is_array($conditions)) {
            throw new \InvalidArgumentException('$nor operator requires an array of conditions');
        }

        $sqlConditions = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                throw new \InvalidArgumentException('Each condition in $nor must be an array');
            }
            $sqlConditions[] = $this->parseConditions($condition);
        }

        $orCondition = $this->combineConditions($sqlConditions, 'OR');
        return new SqlCondition("NOT ({$orCondition->getSql()})", $orCondition->getBindings());
    }

    /**
     * Combine multiple SqlConditions with the specified operator
     */
    private function combineConditions(array $conditions, string $operator): SqlCondition
    {
        // Filter out empty conditions
        $conditions = array_filter($conditions, function($condition) {
            return !$condition->isEmpty();
        });

        if (empty($conditions)) {
            return SqlCondition::empty();
        }

        if (count($conditions) === 1) {
            return $conditions[0];
        }

        $result = array_shift($conditions);
        foreach ($conditions as $condition) {
            $result = $result->combine($condition, $operator);
        }

        return $result;
    }

    /**
     * Create a regex condition
     */
    private function createRegexCondition(string $columnName, $value): SqlCondition
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('$regex operator requires a string value');
        }

        $paramName = $this->generateParamName();
        return new SqlCondition("{$columnName} REGEXP {$paramName}", [$paramName => $value]);
    }

    /**
     * Create a begins with condition
     */
    private function createBeginsWithCondition(string $columnName, $value): SqlCondition
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('$beginsWith operator requires a string value');
        }

        $paramName = $this->generateParamName();
        return new SqlCondition("{$columnName} LIKE {$paramName}", [$paramName => $value . '%']);
    }

    /**
     * Create an all condition (for JSON arrays)
     */
    private function createAllCondition(string $columnName, $value): SqlCondition
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('$all operator requires an array value');
        }

        $conditions = [];
        foreach ($value as $item) {
            $paramName = $this->generateParamName();
            $conditions[] = new SqlCondition("JSON_CONTAINS({$columnName}, {$paramName})", [$paramName => json_encode($item)]);
        }

        return $this->combineConditions($conditions, 'AND');
    }

    /**
     * Create an elemMatch condition (for JSON arrays)
     */
    private function createElemMatchCondition(string $columnName, $value): SqlCondition
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('$elemMatch operator requires an array/object value');
        }

        // For elemMatch, we need to check if any element in the array matches the condition
        // This is complex in SQL, so we'll use a simplified approach with JSON_EXTRACT
        $paramName = $this->generateParamName();

        // Convert the condition to a JSON path query
        // This is a simplified implementation - a full implementation would need more complex JSON path handling
        if (count($value) === 1) {
            $key = array_keys($value)[0];
            $val = array_values($value)[0];
            $jsonPath = "$.*.{$key}";

            $valueParam = $this->generateParamName();
            return new SqlCondition("JSON_EXTRACT({$columnName}, {$paramName}) = {$valueParam}", [
                $paramName => $jsonPath,
                $valueParam => $val
            ]);
        }

        // For more complex conditions, fall back to JSON_CONTAINS
        return new SqlCondition("JSON_CONTAINS({$columnName}, {$paramName})", [$paramName => json_encode($value)]);
    }

    /**
     * Create a size condition (for JSON arrays)
     */
    private function createSizeCondition(string $columnName, $value): SqlCondition
    {
        if (!is_int($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException('$size operator requires a numeric value');
        }

        $paramName = $this->generateParamName();
        return new SqlCondition("JSON_LENGTH({$columnName}) = {$paramName}", [$paramName => (int)$value]);
    }
}
