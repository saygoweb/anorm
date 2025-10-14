<?php

namespace Anorm\Relationship\Strategy;

/**
 * Estimates data transfer sizes for different relationship loading strategies
 *
 * This class provides methods to estimate the amount of data that would be
 * transferred for different loading strategies, helping to choose the most
 * efficient approach based on network and memory constraints.
 */
class DataSizeEstimator
{
    /** @var array Cache for average record counts and sizes */
    private static $cache = [];

    /**
     * Estimate data size for IN clause batch loading strategy
     *
     * @param object $relationship The relationship to analyze
     * @param int $sourceCount Number of source models
     * @return int Estimated data size in bytes
     */
    public function estimateInClauseDataSize($relationship, int $sourceCount): int
    {
        $avgRelatedRecords = $this->getAverageRelatedRecords($relationship);
        $avgRecordSize = $this->getAverageRecordSize($relationship->getRelatedModelClass());

        // IN clause loads full records for all related models
        $totalRelatedRecords = $sourceCount * $avgRelatedRecords;
        return (int)($totalRelatedRecords * $avgRecordSize);
    }

    /**
     * Estimate data size for JOIN with field selection strategy
     *
     * @param object $relationship The relationship to analyze
     * @param int $sourceCount Number of source models
     * @param array|null $fieldSelection Specific fields to load
     * @return int Estimated data size in bytes
     */
    public function estimateJoinDataSize($relationship, int $sourceCount, ?array $fieldSelection = null): int
    {
        $avgRelatedRecords = $this->getAverageRelatedRecords($relationship);

        if ($fieldSelection === null) {
            // No field selection - estimate full record size
            $avgRecordSize = $this->getAverageRecordSize($relationship->getRelatedModelClass());
        } else {
            // Calculate size for selected fields only
            $avgRecordSize = $this->calculateSelectedFieldSize($fieldSelection);
        }

        // JOIN creates denormalized result set
        $cardinality = $relationship->getCardinality();
        if ($cardinality === 'one-to-many' || $cardinality === 'many-to-many') {
            // Each source record is duplicated for each related record
            $totalRows = $sourceCount * $avgRelatedRecords;
            $sourceRecordSize = $this->getAverageRecordSize($this->getSourceModelClass($relationship));
            $totalSize = $totalRows * ($sourceRecordSize + $avgRecordSize);
        } else {
            // one-to-one or many-to-one: no duplication
            $sourceRecordSize = $this->getAverageRecordSize($this->getSourceModelClass($relationship));
            $totalSize = $sourceCount * ($sourceRecordSize + $avgRecordSize);
        }

        return (int)$totalSize;
    }

    /**
     * Get average number of related records for a relationship
     *
     * @param object $relationship The relationship to analyze
     * @return float Average number of related records per source model
     */
    public function getAverageRelatedRecords($relationship): float
    {
        $cacheKey = 'avg_related_' . get_class($relationship) . '_' . $relationship->getRelatedModelClass();

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // Default estimates based on relationship type
        $cardinality = $relationship->getCardinality();
        switch ($cardinality) {
            case 'one-to-one':
            case 'many-to-one':
                $average = 1.0;
                break;
            case 'one-to-many':
                $average = 5.0; // Conservative estimate
                break;
            case 'many-to-many':
                $average = 3.0; // Conservative estimate
                break;
            default:
                $average = 2.0;
        }

        self::$cache[$cacheKey] = $average;
        return $average;
    }

    /**
     * Get average record size for a model class
     *
     * @param string $modelClass The model class to analyze
     * @return int Average record size in bytes
     */
    public function getAverageRecordSize(string $modelClass): int
    {
        $cacheKey = 'avg_size_' . $modelClass;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // Default estimate based on typical model sizes
        // This could be enhanced to analyze actual table schemas
        $estimatedSize = 1024; // 1KB default estimate

        self::$cache[$cacheKey] = $estimatedSize;
        return $estimatedSize;
    }

    /**
     * Calculate data size for selected fields
     *
     * @param array $fieldSelection Array of field names to include
     * @return int Estimated size in bytes for selected fields
     */
    public function calculateSelectedFieldSize(array $fieldSelection): int
    {
        $totalSize = 0;

        foreach ($fieldSelection as $field) {
            // Estimate field sizes based on common patterns
            $fieldSize = $this->estimateFieldSize($field);
            $totalSize += $fieldSize;
        }

        return $totalSize;
    }

    /**
     * Estimate size of a single field based on its name and common patterns
     *
     * @param string $fieldName Name of the field
     * @return int Estimated size in bytes
     */
    private function estimateFieldSize(string $fieldName): int
    {
        // Common field size estimates
        $fieldName = strtolower($fieldName);

        if ($fieldName === 'id' || str_ends_with($fieldName, '_id')) {
            return 8; // Integer primary/foreign keys
        }

        if (str_contains($fieldName, 'name') || str_contains($fieldName, 'title')) {
            return 100; // Short text fields
        }

        if (str_contains($fieldName, 'description') || str_contains($fieldName, 'content')) {
            return 500; // Longer text fields
        }

        if (str_contains($fieldName, 'email')) {
            return 50; // Email addresses
        }

        if (str_contains($fieldName, 'date') || str_contains($fieldName, 'time')) {
            return 20; // Datetime fields
        }

        // Default field size
        return 50;
    }

    /**
     * Get the source model class from a relationship
     *
     * @param object $relationship The relationship instance
     * @return string Source model class name
     */
    private function getSourceModelClass($relationship): string
    {
        // This would need to be implemented based on how relationships
        // store their source model information
        // For now, return a default estimate
        return 'DefaultModel';
    }

    /**
     * Clear the estimation cache
     * Useful for testing or when model characteristics change
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Set a cached value for testing or manual optimization
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     */
    public static function setCacheValue(string $key, $value): void
    {
        self::$cache[$key] = $value;
    }
}
