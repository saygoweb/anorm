<?php

namespace Anorm\Test;

use Anorm\Relationship\Strategy\DataSizeEstimator;
use PHPUnit\Framework\TestCase;

class DataSizeEstimator_Test extends TestCase
{
    private $estimator;
    private $mockRelationship;

    protected function setUp(): void
    {
        $this->estimator = new DataSizeEstimator();
        $this->mockRelationship = $this->createMockRelationship();
    }

    private function createMockRelationship($cardinality = 'one-to-many', $relatedClass = 'TestModel')
    {
        $relationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
        $relationship->method('getCardinality')->willReturn($cardinality);
        $relationship->method('getRelatedModelClass')->willReturn($relatedClass);
        return $relationship;
    }

    public function testEstimateInClauseDataSize()
    {
        $size = $this->estimator->estimateInClauseDataSize($this->mockRelationship, 10);
        
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
        
        // Size should scale with source count
        $largerSize = $this->estimator->estimateInClauseDataSize($this->mockRelationship, 20);
        $this->assertGreaterThan($size, $largerSize);
    }

    public function testEstimateJoinDataSizeWithoutFieldSelection()
    {
        $size = $this->estimator->estimateJoinDataSize($this->mockRelationship, 10, null);
        
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
    }

    public function testEstimateJoinDataSizeWithFieldSelection()
    {
        $fieldSelection = ['id', 'title', 'content'];
        $size = $this->estimator->estimateJoinDataSize($this->mockRelationship, 10, $fieldSelection);
        
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
        
        // Size with field selection should be different from without
        $sizeWithoutSelection = $this->estimator->estimateJoinDataSize($this->mockRelationship, 10, null);
        $this->assertNotEquals($size, $sizeWithoutSelection);
    }

    public function testEstimateJoinDataSizeWithDifferentCardinalities()
    {
        $oneToManyRelationship = $this->createMockRelationship('one-to-many');
        $manyToOneRelationship = $this->createMockRelationship('many-to-one');
        $oneToOneRelationship = $this->createMockRelationship('one-to-one');
        $manyToManyRelationship = $this->createMockRelationship('many-to-many');
        
        $oneToManySize = $this->estimator->estimateJoinDataSize($oneToManyRelationship, 10, null);
        $manyToOneSize = $this->estimator->estimateJoinDataSize($manyToOneRelationship, 10, null);
        $oneToOneSize = $this->estimator->estimateJoinDataSize($oneToOneRelationship, 10, null);
        $manyToManySize = $this->estimator->estimateJoinDataSize($manyToManyRelationship, 10, null);
        
        $this->assertIsInt($oneToManySize);
        $this->assertIsInt($manyToOneSize);
        $this->assertIsInt($oneToOneSize);
        $this->assertIsInt($manyToManySize);
        
        // One-to-many should generally be larger than many-to-one due to duplication
        $this->assertGreaterThan($manyToOneSize, $oneToManySize);
    }

    public function testGetAverageRelatedRecords()
    {
        // Clear cache to ensure clean test
        DataSizeEstimator::clearCache();

        $oneToManyRelationship = $this->createMockRelationship('one-to-many', 'OneToManyModel');
        $manyToOneRelationship = $this->createMockRelationship('many-to-one', 'ManyToOneModel');
        $oneToOneRelationship = $this->createMockRelationship('one-to-one', 'OneToOneModel');
        $manyToManyRelationship = $this->createMockRelationship('many-to-many', 'ManyToManyModel');

        $oneToManyAvg = $this->estimator->getAverageRelatedRecords($oneToManyRelationship);
        $manyToOneAvg = $this->estimator->getAverageRelatedRecords($manyToOneRelationship);
        $oneToOneAvg = $this->estimator->getAverageRelatedRecords($oneToOneRelationship);
        $manyToManyAvg = $this->estimator->getAverageRelatedRecords($manyToManyRelationship);

        // Debug output to understand what's happening
        // echo "oneToManyAvg: $oneToManyAvg, manyToOneAvg: $manyToOneAvg\n";

        $this->assertEquals(5.0, $oneToManyAvg);  // Hardcoded estimate for one-to-many relationships
        $this->assertEquals(1.0, $manyToOneAvg);  // Hardcoded estimate for many-to-one relationships
        $this->assertEquals(1.0, $oneToOneAvg);
        $this->assertEquals(3.0, $manyToManyAvg);
        
        // Test caching - second call should return same value
        $cachedAvg = $this->estimator->getAverageRelatedRecords($oneToManyRelationship);
        $this->assertEquals($oneToManyAvg, $cachedAvg);
    }

    public function testGetAverageRecordSize()
    {
        $size = $this->estimator->getAverageRecordSize('TestModel');
        
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
        $this->assertEquals(1024, $size); // Default estimate
        
        // Test caching - second call should return same value
        $cachedSize = $this->estimator->getAverageRecordSize('TestModel');
        $this->assertEquals($size, $cachedSize);
    }

    public function testCalculateSelectedFieldSize()
    {
        $fields = ['id', 'name', 'email', 'description', 'created_at'];
        $size = $this->estimator->calculateSelectedFieldSize($fields);
        
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
        
        // More fields should result in larger size
        $moreFields = ['id', 'name', 'email', 'description', 'created_at', 'updated_at', 'content'];
        $largerSize = $this->estimator->calculateSelectedFieldSize($moreFields);
        $this->assertGreaterThan($size, $largerSize);
    }

    public function testEstimateFieldSizeWithDifferentFieldTypes()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->estimator);
        $method = $reflection->getMethod('estimateFieldSize');
        $method->setAccessible(true);
        
        // Test different field types
        $this->assertEquals(8, $method->invoke($this->estimator, 'id'));
        $this->assertEquals(8, $method->invoke($this->estimator, 'user_id'));
        $this->assertEquals(100, $method->invoke($this->estimator, 'name'));
        $this->assertEquals(100, $method->invoke($this->estimator, 'title'));
        $this->assertEquals(500, $method->invoke($this->estimator, 'description'));
        $this->assertEquals(500, $method->invoke($this->estimator, 'content'));
        $this->assertEquals(50, $method->invoke($this->estimator, 'email'));
        $this->assertEquals(50, $method->invoke($this->estimator, 'created_at')); // Default size (doesn't contain 'date' or 'time')
        $this->assertEquals(20, $method->invoke($this->estimator, 'updated_at')); // Contains 'date' in 'updated_at'
        $this->assertEquals(20, $method->invoke($this->estimator, 'date_field')); // Contains 'date'
        $this->assertEquals(20, $method->invoke($this->estimator, 'time_field')); // Contains 'time'
        $this->assertEquals(20, $method->invoke($this->estimator, 'datetime_field')); // Contains 'date'
        $this->assertEquals(50, $method->invoke($this->estimator, 'unknown_field'));
    }

    public function testGetSourceModelClass()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->estimator);
        $method = $reflection->getMethod('getSourceModelClass');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->estimator, $this->mockRelationship);
        $this->assertEquals('DefaultModel', $result);
    }

    public function testClearCache()
    {
        // First, populate cache
        $this->estimator->getAverageRelatedRecords($this->mockRelationship);
        $this->estimator->getAverageRecordSize('TestModel');
        
        // Clear cache
        DataSizeEstimator::clearCache();
        
        // Values should still be returned (recalculated)
        $avg = $this->estimator->getAverageRelatedRecords($this->mockRelationship);
        $size = $this->estimator->getAverageRecordSize('TestModel');
        
        $this->assertIsFloat($avg);
        $this->assertIsInt($size);
    }

    public function testSetCacheValue()
    {
        $testKey = 'test_cache_key';
        $testValue = 12345;
        
        DataSizeEstimator::setCacheValue($testKey, $testValue);
        
        // Use reflection to access cache
        $reflection = new \ReflectionClass(DataSizeEstimator::class);
        $property = $reflection->getProperty('cache');
        $property->setAccessible(true);
        $cache = $property->getValue();
        
        $this->assertEquals($testValue, $cache[$testKey]);
    }

    public function testEstimateJoinDataSizeWithUnknownCardinality()
    {
        $unknownRelationship = $this->createMockRelationship('unknown-cardinality');
        
        $size = $this->estimator->estimateJoinDataSize($unknownRelationship, 10, null);
        
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);
    }

    public function testCalculateSelectedFieldSizeWithEmptyFields()
    {
        $size = $this->estimator->calculateSelectedFieldSize([]);
        $this->assertEquals(0, $size);
    }

    public function testEstimateFieldSizeEdgeCases()
    {
        // Test field size estimation with various field names using reflection
        $reflection = new \ReflectionClass($this->estimator);
        $method = $reflection->getMethod('estimateFieldSize');
        $method->setAccessible(true);

        // Test edge cases
        $this->assertEquals(50, $method->invoke($this->estimator, '')); // Empty field name
        $this->assertEquals(8, $method->invoke($this->estimator, 'ID')); // Uppercase
        $this->assertEquals(50, $method->invoke($this->estimator, 'created_at')); // Regular field (doesn't contain exact 'date')
        $this->assertEquals(20, $method->invoke($this->estimator, 'timestamp')); // Contains 'time'
        $this->assertEquals(500, $method->invoke($this->estimator, 'description')); // Description field (longer text)
    }
}
