<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\Strategy\JoinWithSelectionLoader;
use Anorm\Relationship\Strategy\NestedRelationshipParser;
use Anorm\Relationship\Cache\RelationshipCache;
use Anorm\Relationship\Performance\PerformanceMonitor;
use PHPUnit\Framework\TestCase;

class CoveragePush_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testJoinWithSelectionLoaderTableNameGeneration()
    {
        $loader = new JoinWithSelectionLoader();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('getTableName');
        $method->setAccessible(true);
        
        $mockRelationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
        $mockRelationship->method('getRelatedModelClass')->willReturn('PostModel');
        
        $sourceTable = $method->invoke($loader, $mockRelationship, 'source');
        $relatedTable = $method->invoke($loader, $mockRelationship, 'related');
        
        $this->assertEquals('users', $sourceTable);
        $this->assertEquals('posts', $relatedTable);
    }

    public function testJoinWithSelectionLoaderIsEmptyRelatedRow()
    {
        $loader = new JoinWithSelectionLoader();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('isEmptyRelatedRow');
        $method->setAccessible(true);
        
        // Test with all null values
        $emptyRow = ['id' => null, 'title' => null, 'content' => null];
        $this->assertTrue($method->invoke($loader, $emptyRow));
        
        // Test with some non-null values
        $nonEmptyRow = ['id' => 1, 'title' => null, 'content' => null];
        $this->assertFalse($method->invoke($loader, $nonEmptyRow));
        
        // Test with empty array
        $this->assertTrue($method->invoke($loader, []));
    }

    public function testJoinWithSelectionLoaderBuildWhereClause()
    {
        $loader = new JoinWithSelectionLoader();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('buildWhereClause');
        $method->setAccessible(true);
        
        // Test with primary keys
        $whereClause = $method->invoke($loader, 'users', 'id', [1, 2, 3]);
        $this->assertStringContainsString('s.`id` IN', $whereClause);
        $this->assertStringContainsString('?,?,?', $whereClause);
        
        // Test with empty primary keys
        $emptyWhereClause = $method->invoke($loader, 'users', 'id', []);
        $this->assertEquals('1=0', $emptyWhereClause);
    }

    public function testNestedRelationshipParserGenerateCircularKey()
    {
        $parser = new NestedRelationshipParser();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($parser);
        $method = $reflection->getMethod('generateCircularKey');
        $method->setAccessible(true);
        
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $key = $method->invoke($parser, $userArray, 'posts');
            $this->assertStringContainsString('UserModel.posts', $key);
        }
        
        // Test with empty models
        $emptyKey = $method->invoke($parser, [], 'posts');
        $this->assertEquals('posts', $emptyKey);
    }

    public function testRelationshipCacheUpdateAccessOrder()
    {
        $cache = new RelationshipCache(3);
        
        // Use reflection to test private methods
        $reflection = new \ReflectionClass($cache);
        $updateMethod = $reflection->getMethod('updateAccessOrder');
        $updateMethod->setAccessible(true);
        
        $accessOrderProperty = $reflection->getProperty('accessOrder');
        $accessOrderProperty->setAccessible(true);
        
        // Test updating access order
        $updateMethod->invoke($cache, 'key1');
        $updateMethod->invoke($cache, 'key2');
        $updateMethod->invoke($cache, 'key1'); // Should move to end
        
        $accessOrder = $accessOrderProperty->getValue($cache);
        $this->assertEquals(['key2', 'key1'], $accessOrder);
    }

    public function testRelationshipCacheRemoveFromAccessOrder()
    {
        $cache = new RelationshipCache(3);
        
        // Use reflection to test private methods
        $reflection = new \ReflectionClass($cache);
        $updateMethod = $reflection->getMethod('updateAccessOrder');
        $updateMethod->setAccessible(true);
        $removeMethod = $reflection->getMethod('removeFromAccessOrder');
        $removeMethod->setAccessible(true);
        
        $accessOrderProperty = $reflection->getProperty('accessOrder');
        $accessOrderProperty->setAccessible(true);
        
        // Add some keys
        $updateMethod->invoke($cache, 'key1');
        $updateMethod->invoke($cache, 'key2');
        $updateMethod->invoke($cache, 'key3');
        
        // Remove middle key
        $removeMethod->invoke($cache, 'key2');
        
        $accessOrder = $accessOrderProperty->getValue($cache);
        $this->assertEquals(['key1', 'key3'], $accessOrder);
    }

    public function testPerformanceMonitorAnalyzeQueryOptimization()
    {
        $monitor = new PerformanceMonitor();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($monitor);
        $method = $reflection->getMethod('analyzeQueryOptimization');
        $method->setAccessible(true);
        
        // Test with no data
        $analysis = $method->invoke($monitor);
        $this->assertArrayHasKey('no_data', $analysis);
        $this->assertTrue($analysis['no_data']);
        
        // Add some query reduction data
        $monitor->recordQueryReduction(100, 10, 'in_clause_batch');
        $monitor->recordQueryReduction(50, 5, 'join_with_selection');
        
        $analysis = $method->invoke($monitor);
        $this->assertArrayHasKey('total_queries_saved', $analysis);
        $this->assertEquals(135, $analysis['total_queries_saved']); // 90 + 45
    }

    public function testPerformanceMonitorAnalyzeStrategyEffectiveness()
    {
        $monitor = new PerformanceMonitor();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($monitor);
        $method = $reflection->getMethod('analyzeStrategyEffectiveness');
        $method->setAccessible(true);
        
        // Test with no data
        $analysis = $method->invoke($monitor);
        $this->assertArrayHasKey('no_data', $analysis);
        
        // Add strategy selections
        $monitor->recordStrategySelection('oneHasMany', 'in_clause_batch', []);
        $monitor->recordStrategySelection('manyHasOne', 'join_with_selection', []);
        $monitor->recordStrategySelection('oneHasMany', 'in_clause_batch', []);
        
        $analysis = $method->invoke($monitor);
        $this->assertArrayHasKey('strategy_usage', $analysis);
        $this->assertEquals(2, $analysis['strategy_usage']['in_clause_batch']);
        $this->assertEquals(1, $analysis['strategy_usage']['join_with_selection']);
        $this->assertEquals('in_clause_batch', $analysis['most_used_strategy']);
    }

    public function testPerformanceMonitorAnalyzeDataTransfer()
    {
        $monitor = new PerformanceMonitor();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($monitor);
        $method = $reflection->getMethod('analyzeDataTransfer');
        $method->setAccessible(true);
        
        // Test with no data
        $analysis = $method->invoke($monitor);
        $this->assertArrayHasKey('no_data', $analysis);
        
        // Add data transfer records
        $monitor->recordDataTransfer(1024, 'in_clause_batch');
        $monitor->recordDataTransfer(512, 'join_with_selection');
        
        $analysis = $method->invoke($monitor);
        $this->assertEquals(1536, $analysis['total_bytes_transferred']);
        $this->assertEquals(768, $analysis['average_bytes_per_operation']);
        $this->assertEquals(1024, $analysis['largest_transfer']);
        $this->assertEquals(512, $analysis['smallest_transfer']);
    }

    public function testPerformanceMonitorAnalyzeResponseTimes()
    {
        $monitor = new PerformanceMonitor();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($monitor);
        $method = $reflection->getMethod('analyzeResponseTimes');
        $method->setAccessible(true);
        
        // Test with no data
        $analysis = $method->invoke($monitor);
        $this->assertArrayHasKey('no_data', $analysis);
        
        // Add some operations with known durations
        $monitor->startOperation('op1');
        usleep(1000); // 1ms
        $monitor->endOperation('op1');
        
        $monitor->startOperation('op2');
        usleep(2000); // 2ms
        $monitor->endOperation('op2');
        
        $analysis = $method->invoke($monitor);
        $this->assertArrayHasKey('average_response_time', $analysis);
        $this->assertArrayHasKey('median_response_time', $analysis);
        $this->assertArrayHasKey('fastest_response', $analysis);
        $this->assertArrayHasKey('slowest_response', $analysis);
        $this->assertArrayHasKey('p95_response_time', $analysis);
        
        $this->assertGreaterThan(0, $analysis['average_response_time']);
    }

    public function testPerformanceMonitorGenerateRecommendations()
    {
        $monitor = new PerformanceMonitor();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($monitor);
        $method = $reflection->getMethod('generateRecommendations');
        $method->setAccessible(true);
        
        // Create conditions for recommendations
        $monitor->recordQueryReduction(10, 9, 'in_clause_batch'); // Low reduction
        $monitor->recordStrategySelection('oneHasMany', 'individual_loading', []);
        $monitor->recordStrategySelection('oneHasMany', 'individual_loading', []);
        $monitor->recordStrategySelection('oneHasMany', 'in_clause_batch', []);
        
        $monitor->startOperation('slow_op');
        usleep(150000); // 150ms
        $monitor->endOperation('slow_op');
        
        $recommendations = $method->invoke($monitor);
        
        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);
    }

    public function testJoinWithSelectionLoaderExtractPrimaryKeys()
    {
        $loader = new JoinWithSelectionLoader();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('extractPrimaryKeys');
        $method->setAccessible(true);
        
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $mockRelationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
            $mockRelationship->method('getPrimaryKey')->willReturn('id');
            
            $primaryKeys = $method->invoke($loader, $userArray, $mockRelationship);
            
            $this->assertIsArray($primaryKeys);
            $this->assertNotEmpty($primaryKeys);
            
            // Should contain unique primary key values
            $this->assertEquals(array_unique($primaryKeys), $primaryKeys);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }
}
