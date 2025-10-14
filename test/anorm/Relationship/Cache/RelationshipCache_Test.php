<?php

namespace Anorm\Test;

use Anorm\Relationship\Cache\RelationshipCache;
use PHPUnit\Framework\TestCase;

class RelationshipCache_Test extends TestCase
{
    private $cache;

    protected function setUp(): void
    {
        $this->cache = new RelationshipCache(5); // Small cache for testing
    }

    public function testBasicCacheOperations()
    {
        $model = (object) ['id' => 1, 'name' => 'Test Model'];
        $cacheKey = 'test_key';

        // Test set and get
        $this->cache->set($cacheKey, $model);
        $this->assertTrue($this->cache->has($cacheKey));
        $this->assertSame($model, $this->cache->get($cacheKey));

        // Test remove
        $this->cache->remove($cacheKey);
        $this->assertFalse($this->cache->has($cacheKey));
        $this->assertNull($this->cache->get($cacheKey));
    }

    public function testLRUEviction()
    {
        // Fill cache to capacity
        for ($i = 1; $i <= 5; $i++) {
            $model = (object) ['id' => $i, 'name' => "Model {$i}"];
            $this->cache->set("key_{$i}", $model);
        }

        // Access key_2 to make it recently used
        $this->cache->get('key_2');

        // Add one more item to trigger eviction
        $newModel = (object) ['id' => 6, 'name' => 'Model 6'];
        $this->cache->set('key_6', $newModel);

        // key_1 should be evicted (least recently used)
        $this->assertFalse($this->cache->has('key_1'));
        // key_2 should still be there (recently accessed)
        $this->assertTrue($this->cache->has('key_2'));
        // key_6 should be there (just added)
        $this->assertTrue($this->cache->has('key_6'));
    }

    public function testCacheStatistics()
    {
        $model = (object) ['id' => 1, 'name' => 'Test Model'];

        // Generate some cache activity
        $this->cache->set('key_1', $model);
        $this->cache->get('key_1'); // Hit
        $this->cache->get('key_2'); // Miss
        $this->cache->set('key_2', $model);

        $stats = $this->cache->getStats();

        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(2, $stats['sets']);
        $this->assertEquals(2, $stats['size']);
        $this->assertEquals(5, $stats['max_size']);
        $this->assertEquals(50.0, $stats['hit_rate']); // 1 hit out of 2 total requests
    }

    public function testGenerateCacheKey()
    {
        $key1 = $this->cache->generateCacheKey('oneHasMany', 'PostModel', 123);
        $key2 = $this->cache->generateCacheKey('oneHasMany', 'PostModel', 123, ['id', 'title']);
        $key3 = $this->cache->generateCacheKey('oneHasMany', 'PostModel', 123, ['title', 'id']); // Different order

        $this->assertIsString($key1);
        $this->assertIsString($key2);
        $this->assertNotEquals($key1, $key2); // Different due to field selection
        $this->assertEquals($key2, $key3); // Same due to field normalization
    }

    public function testInvalidateByModelClass()
    {
        // Add models of different classes
        $this->cache->set('oneHasMany:PostModel:1', (object) ['id' => 1]);
        $this->cache->set('oneHasMany:PostModel:2', (object) ['id' => 2]);
        $this->cache->set('manyHasOne:UserModel:1', (object) ['id' => 1]);
        $this->cache->set('oneHasMany:CommentModel:1', (object) ['id' => 1]);

        // Invalidate PostModel entries
        $removed = $this->cache->invalidateByModelClass('PostModel');

        $this->assertEquals(2, $removed);
        $this->assertFalse($this->cache->has('oneHasMany:PostModel:1'));
        $this->assertFalse($this->cache->has('oneHasMany:PostModel:2'));
        $this->assertTrue($this->cache->has('manyHasOne:UserModel:1'));
        $this->assertTrue($this->cache->has('oneHasMany:CommentModel:1'));
    }

    public function testWarmCache()
    {
        $relationships = [
            ['key' => 'key_1', 'model' => (object) ['id' => 1]],
            ['key' => 'key_2', 'model' => (object) ['id' => 2]],
            ['key' => 'key_3'], // Missing model - should be skipped
        ];

        $this->cache->warmCache($relationships);

        $this->assertTrue($this->cache->has('key_1'));
        $this->assertTrue($this->cache->has('key_2'));
        $this->assertFalse($this->cache->has('key_3'));

        $stats = $this->cache->getStats();
        $this->assertEquals(2, $stats['size']);
    }

    public function testClearCache()
    {
        // Add some items
        $this->cache->set('key_1', (object) ['id' => 1]);
        $this->cache->set('key_2', (object) ['id' => 2]);
        $this->cache->get('key_1'); // Generate some stats

        $this->assertEquals(2, $this->cache->getStats()['size']);

        // Clear cache
        $this->cache->clear();

        $stats = $this->cache->getStats();
        $this->assertEquals(0, $stats['size']);
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(0, $stats['misses']);
        $this->assertEquals(0, $stats['sets']);
    }

    public function testGetEfficiencyMetrics()
    {
        // Generate some cache activity
        for ($i = 1; $i <= 3; $i++) {
            $this->cache->set("key_{$i}", (object) ['id' => $i]);
        }

        // Generate hits and misses
        $this->cache->get('key_1'); // Hit
        $this->cache->get('key_2'); // Hit
        $this->cache->get('key_4'); // Miss

        // Trigger eviction by filling cache
        for ($i = 4; $i <= 7; $i++) {
            $this->cache->set("key_{$i}", (object) ['id' => $i]);
        }

        $metrics = $this->cache->getEfficiencyMetrics();

        $this->assertArrayHasKey('hit_rate', $metrics);
        $this->assertArrayHasKey('miss_rate', $metrics);
        $this->assertArrayHasKey('cache_utilization', $metrics);
        $this->assertArrayHasKey('eviction_rate', $metrics);
        $this->assertArrayHasKey('total_requests', $metrics);

        $this->assertEquals(100 - $metrics['hit_rate'], $metrics['miss_rate']);
        $this->assertEquals(3, $metrics['total_requests']); // 2 hits + 1 miss
        $this->assertGreaterThan(0, $metrics['eviction_rate']); // Should have evictions
    }

    public function testCacheWithNullValues()
    {
        // Test caching null values
        $this->cache->set('null_key', null);

        $this->assertTrue($this->cache->has('null_key'));
        $this->assertNull($this->cache->get('null_key'));
    }

    public function testCacheKeyGeneration()
    {
        // Test various cache key scenarios
        $key1 = $this->cache->generateCacheKey('oneHasMany', 'PostModel', 1);
        $key2 = $this->cache->generateCacheKey('oneHasMany', 'PostModel', '1'); // String ID
        $key3 = $this->cache->generateCacheKey('manyHasOne', 'PostModel', 1);

        $this->assertEquals($key1, $key2); // Should handle string/int conversion
        $this->assertNotEquals($key1, $key3); // Different relationship types

        // Test with field selection
        $keyWithFields = $this->cache->generateCacheKey('oneHasMany', 'PostModel', 1, ['title', 'content']);
        $this->assertNotEquals($key1, $keyWithFields);

        // Test field order normalization
        $keyFields1 = $this->cache->generateCacheKey('oneHasMany', 'PostModel', 1, ['title', 'content']);
        $keyFields2 = $this->cache->generateCacheKey('oneHasMany', 'PostModel', 1, ['content', 'title']);
        $this->assertEquals($keyFields1, $keyFields2);
    }

    public function testCacheOverflow()
    {
        // Test behavior when cache exceeds capacity
        $initialStats = $this->cache->getStats();

        // Add more items than cache capacity
        for ($i = 1; $i <= 10; $i++) {
            $this->cache->set("overflow_key_{$i}", (object) ['id' => $i]);
        }

        $stats = $this->cache->getStats();

        // Cache size should not exceed max size
        $this->assertEquals(5, $stats['size']);
        $this->assertGreaterThan(0, $stats['evictions']);

        // Only the most recent items should remain
        $this->assertTrue($this->cache->has('overflow_key_10'));
        $this->assertTrue($this->cache->has('overflow_key_9'));
        $this->assertFalse($this->cache->has('overflow_key_1'));
    }
}
