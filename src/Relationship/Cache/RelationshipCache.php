<?php

namespace Anorm\Relationship\Cache;

/**
 * LRU (Least Recently Used) cache for relationship data
 *
 * This cache stores loaded relationship models to avoid redundant database queries
 * when the same related models are needed across different source models.
 */
class RelationshipCache
{
    /** @var array Cache storage */
    private $cache = [];

    /** @var array Access order tracking for LRU eviction */
    private $accessOrder = [];

    /** @var int Maximum number of items to cache */
    private $maxSize;

    /** @var array Cache statistics */
    private $stats = [
        'hits' => 0,
        'misses' => 0,
        'evictions' => 0,
        'sets' => 0
    ];

    public function __construct(int $maxSize = 1000)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * Get a cached relationship model
     *
     * @param string $cacheKey Unique cache key for the relationship
     * @return mixed|null Cached model or null if not found
     */
    public function get(string $cacheKey)
    {
        if (isset($this->cache[$cacheKey])) {
            // Update access order for LRU
            $this->updateAccessOrder($cacheKey);
            $this->stats['hits']++;
            return $this->cache[$cacheKey];
        }

        $this->stats['misses']++;
        return null;
    }

    /**
     * Store a relationship model in cache
     *
     * @param string $cacheKey Unique cache key
     * @param mixed $model Model to cache
     * @return void
     */
    public function set(string $cacheKey, $model): void
    {
        // If cache is full, evict least recently used item
        if (count($this->cache) >= $this->maxSize && !isset($this->cache[$cacheKey])) {
            $this->evictLeastRecentlyUsed();
        }

        $this->cache[$cacheKey] = $model;
        $this->updateAccessOrder($cacheKey);
        $this->stats['sets']++;
    }

    /**
     * Check if a key exists in cache
     *
     * @param string $cacheKey Cache key to check
     * @return bool True if key exists
     */
    public function has(string $cacheKey): bool
    {
        return array_key_exists($cacheKey, $this->cache);
    }

    /**
     * Remove an item from cache
     *
     * @param string $cacheKey Cache key to remove
     * @return void
     */
    public function remove(string $cacheKey): void
    {
        unset($this->cache[$cacheKey]);
        $this->removeFromAccessOrder($cacheKey);
    }

    /**
     * Clear all cached items
     *
     * @return void
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->accessOrder = [];
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'evictions' => 0,
            'sets' => 0
        ];
    }

    /**
     * Get cache statistics
     *
     * @return array Cache performance metrics
     */
    public function getStats(): array
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $total > 0 ? ($this->stats['hits'] / $total) * 100 : 0;

        return array_merge($this->stats, [
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'hit_rate' => round($hitRate, 2)
        ]);
    }

    /**
     * Generate cache key for a relationship
     *
     * @param string $relationshipType Type of relationship (oneHasMany, manyHasOne, etc.)
     * @param string $relatedModelClass Class name of related model
     * @param mixed $relatedModelId Primary key of related model
     * @param array|null $fieldSelection Fields that were selected, or null for all
     * @return string Unique cache key
     */
    public function generateCacheKey(string $relationshipType, string $relatedModelClass, $relatedModelId, ?array $fieldSelection = null): string
    {
        $parts = [
            $relationshipType,
            $relatedModelClass,
            (string)$relatedModelId
        ];

        if ($fieldSelection !== null) {
            sort($fieldSelection); // Normalize field order
            $parts[] = implode(',', $fieldSelection);
        }

        return implode(':', $parts);
    }

    /**
     * Invalidate cache entries for a specific model class
     *
     * @param string $modelClass Model class to invalidate
     * @return int Number of entries removed
     */
    public function invalidateByModelClass(string $modelClass): int
    {
        $removed = 0;
        $keysToRemove = [];

        foreach (array_keys($this->cache) as $key) {
            if (strpos($key, $modelClass) !== false) {
                $keysToRemove[] = $key;
            }
        }

        foreach ($keysToRemove as $key) {
            $this->remove($key);
            $removed++;
        }

        return $removed;
    }

    /**
     * Warm cache with frequently accessed relationships
     *
     * @param array $relationships Array of relationship data to pre-load
     * @return void
     */
    public function warmCache(array $relationships): void
    {
        foreach ($relationships as $relationship) {
            if (isset($relationship['key'], $relationship['model'])) {
                $this->set($relationship['key'], $relationship['model']);
            }
        }
    }

    /**
     * Update access order for LRU tracking
     *
     * @param string $cacheKey Cache key that was accessed
     * @return void
     */
    private function updateAccessOrder(string $cacheKey): void
    {
        // Remove from current position
        $this->removeFromAccessOrder($cacheKey);

        // Add to end (most recently used)
        $this->accessOrder[] = $cacheKey;
    }

    /**
     * Remove key from access order tracking
     *
     * @param string $cacheKey Cache key to remove
     * @return void
     */
    private function removeFromAccessOrder(string $cacheKey): void
    {
        $index = array_search($cacheKey, $this->accessOrder, true);
        if ($index !== false) {
            array_splice($this->accessOrder, $index, 1);
        }
    }

    /**
     * Evict the least recently used item
     *
     * @return void
     */
    private function evictLeastRecentlyUsed(): void
    {
        if (!empty($this->accessOrder)) {
            $lruKey = array_shift($this->accessOrder);
            unset($this->cache[$lruKey]);
            $this->stats['evictions']++;
        }
    }

    /**
     * Get cache efficiency metrics
     *
     * @return array Performance analysis
     */
    public function getEfficiencyMetrics(): array
    {
        $stats = $this->getStats();

        return [
            'hit_rate' => $stats['hit_rate'],
            'miss_rate' => 100 - $stats['hit_rate'],
            'cache_utilization' => ($stats['size'] / $stats['max_size']) * 100,
            'eviction_rate' => $stats['sets'] > 0 ? ($stats['evictions'] / $stats['sets']) * 100 : 0,
            'total_requests' => $stats['hits'] + $stats['misses']
        ];
    }
}
