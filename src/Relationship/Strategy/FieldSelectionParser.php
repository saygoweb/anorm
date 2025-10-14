<?php

namespace Anorm\Relationship\Strategy;

/**
 * Parses field selection syntax for relationship optimization
 *
 * This class handles parsing of field selection specifications like:
 * - 'posts:id,title,created_at'
 * - 'company:name,address'
 * - 'users:*' (all fields)
 */
class FieldSelectionParser
{
    /**
     * Parse field selection specification for a relationship
     *
     * Supports syntax like:
     * - 'posts:id,title,created_at' - specific fields
     * - 'posts:*' - all fields (equivalent to no selection)
     * - 'posts' - all fields (no colon means all fields)
     *
     * @param string $relationshipSpec The relationship specification string
     * @return array Parsed field selection with relationship name and fields
     */
    public function parseFieldSelection(string $relationshipSpec): array
    {
        $relationshipSpec = trim($relationshipSpec);

        // Check if field selection is specified
        if (!str_contains($relationshipSpec, ':')) {
            // No field selection - return relationship name with all fields
            return [
                'relationship' => $relationshipSpec,
                'fields' => null, // null means all fields
                'all_fields' => true
            ];
        }

        // Split relationship name and field specification
        $parts = explode(':', $relationshipSpec, 2);
        $relationshipName = trim($parts[0]);
        $fieldSpec = trim($parts[1]);

        // Handle wildcard for all fields
        if ($fieldSpec === '*') {
            return [
                'relationship' => $relationshipName,
                'fields' => null, // null means all fields
                'all_fields' => true
            ];
        }

        // Parse comma-separated field list
        $fields = array_map('trim', explode(',', $fieldSpec));
        $fields = array_filter($fields); // Remove empty fields

        return [
            'relationship' => $relationshipName,
            'fields' => $fields,
            'all_fields' => false
        ];
    }

    /**
     * Parse multiple relationship specifications
     *
     * @param array $relationshipSpecs Array of relationship specification strings
     * @return array Array of parsed field selections, keyed by relationship name
     */
    public function parseMultipleSelections(array $relationshipSpecs): array
    {
        $parsed = [];

        foreach ($relationshipSpecs as $spec) {
            $selection = $this->parseFieldSelection($spec);
            $parsed[$selection['relationship']] = $selection;
        }

        return $parsed;
    }

    /**
     * Validate field names against a model class
     *
     * This method would check if the specified fields exist in the target model.
     * For now, it returns a basic validation result.
     *
     * @param array $fields Array of field names to validate
     * @param string $modelClass The model class to validate against
     * @return array Validation result with valid/invalid fields
     */
    public function validateFields(array $fields, string $modelClass): array
    {
        // TODO: Implement actual field validation against model properties
        // This would require introspection of the model class or its mapper

        $result = [
            'valid' => [],
            'invalid' => [],
            'warnings' => []
        ];

        foreach ($fields as $field) {
            // Basic validation - check for obviously invalid field names
            if (empty($field) || !is_string($field)) {
                $result['invalid'][] = $field;
                continue;
            }

            // Check for potentially problematic field names
            if (str_starts_with($field, '_')) {
                $result['warnings'][] = "Field '{$field}' starts with underscore - may be internal";
            }

            // For now, assume all non-empty string fields are valid
            $result['valid'][] = $field;
        }

        return $result;
    }

    /**
     * Generate SQL SELECT clause for field selection
     *
     * @param array|null $fields Array of field names, or null for all fields
     * @param string $tableAlias Table alias to use in the SELECT clause
     * @param string $prefix Optional prefix for field aliases
     * @return string SQL SELECT clause
     */
    public function generateSelectClause(?array $fields, string $tableAlias, string $prefix = ''): string
    {
        if ($fields === null || empty($fields)) {
            return "`{$tableAlias}`.*";
        }

        $selectParts = [];
        foreach ($fields as $field) {
            $fieldAlias = $prefix ? "{$prefix}_{$field}" : $field;
            $selectParts[] = "`{$tableAlias}`.`{$field}` AS `{$fieldAlias}`";
        }

        return implode(', ', $selectParts);
    }

    /**
     * Extract field values from result row based on prefix
     *
     * @param array $row Database result row
     * @param string $prefix Prefix used in field aliases
     * @return array Extracted field values without prefix
     */
    public function extractPrefixedFields(array $row, string $prefix): array
    {
        $extracted = [];
        $prefixLength = strlen($prefix) + 1; // +1 for underscore

        foreach ($row as $key => $value) {
            if (str_starts_with($key, $prefix . '_')) {
                $fieldName = substr($key, $prefixLength);
                $extracted[$fieldName] = $value;
            }
        }

        return $extracted;
    }

    /**
     * Check if field selection is effectively "all fields"
     *
     * @param array|null $fields Field selection to check
     * @return bool True if this represents all fields
     */
    public function isAllFields(?array $fields): bool
    {
        return $fields === null || empty($fields);
    }

    /**
     * Normalize relationship specification
     *
     * Ensures consistent format for relationship specifications
     *
     * @param string $spec Raw relationship specification
     * @return string Normalized specification
     */
    public function normalizeSpec(string $spec): string
    {
        $spec = trim($spec);

        // Remove extra whitespace around colons and commas
        $spec = preg_replace('/\s*:\s*/', ':', $spec);
        $spec = preg_replace('/\s*,\s*/', ',', $spec);

        return $spec;
    }
}
