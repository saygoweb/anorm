<?php

namespace Anorm\Test;

use Anorm\Relationship\Strategy\QueryStrategySelector;
use Anorm\Relationship\Strategy\QueryStrategyInterface;
use Anorm\Relationship\Strategy\DataSizeEstimator;
use PHPUnit\Framework\TestCase;

class QueryStrategySelector_Test extends TestCase
{
    private $selector;
    private $mockRelationship;

    protected function setUp(): void
    {
        $this->selector = new QueryStrategySelector();
        $this->mockRelationship = $this->createMockRelationship();
    }

    private function createMockRelationship($cardinality = 'one-to-many')
    {
        $relationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
        $relationship->method('getCardinality')->willReturn($cardinality);
        $relationship->method('getRelatedModelClass')->willReturn('TestModel');
        return $relationship;
    }

    public function testSelectStrategyWithSmallDataset()
    {
        $strategy = $this->selector->selectStrategy($this->mockRelationship, 3, null);
        $this->assertEquals(QueryStrategyInterface::STRATEGY_INDIVIDUAL_LOADING, $strategy);
    }

    public function testSelectStrategyWithLargeDataset()
    {
        $strategy = $this->selector->selectStrategy($this->mockRelationship, 20, null);
        $this->assertEquals(QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH, $strategy);
    }

    public function testSelectStrategyWithFieldSelection()
    {
        $fieldSelection = ['id', 'title'];
        $strategy = $this->selector->selectStrategy($this->mockRelationship, 20, $fieldSelection);
        
        // Should consider JOIN strategy with field selection
        $this->assertContains($strategy, [
            QueryStrategyInterface::STRATEGY_JOIN_WITH_SELECTION,
            QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH
        ]);
    }

    public function testSelectStrategyWithManyToManyRelationship()
    {
        $manyToManyRelationship = $this->createMockRelationship('many-to-many');
        $fieldSelection = ['id', 'name'];
        
        $strategy = $this->selector->selectStrategy($manyToManyRelationship, 20, $fieldSelection);
        
        // Many-to-many should not use JOIN strategy due to data explosion risk
        $this->assertEquals(QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH, $strategy);
    }

    public function testSelectStrategyWithOneToOneRelationship()
    {
        $oneToOneRelationship = $this->createMockRelationship('one-to-one');
        $fieldSelection = ['id', 'name'];
        
        $strategy = $this->selector->selectStrategy($oneToOneRelationship, 20, $fieldSelection);
        
        // One-to-one should be able to use JOIN strategy
        $this->assertContains($strategy, [
            QueryStrategyInterface::STRATEGY_JOIN_WITH_SELECTION,
            QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH
        ]);
    }

    public function testGetStrategyMetadata()
    {
        $strategy = QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH;
        $metadata = $this->selector->getStrategyMetadata($strategy, $this->mockRelationship, 20, ['id', 'title']);
        
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('strategy', $metadata);
        $this->assertArrayHasKey('source_count', $metadata);
        $this->assertArrayHasKey('field_selection', $metadata);
        $this->assertArrayHasKey('cardinality', $metadata);
        $this->assertArrayHasKey('estimated_queries', $metadata);
        $this->assertArrayHasKey('decision_factors', $metadata);
        
        $this->assertEquals($strategy, $metadata['strategy']);
        $this->assertEquals(20, $metadata['source_count']);
        $this->assertEquals(['id', 'title'], $metadata['field_selection']);
        $this->assertEquals('one-to-many', $metadata['cardinality']);
        $this->assertIsArray($metadata['decision_factors']);
    }

    public function testIsStrategySupported()
    {
        $this->assertTrue($this->selector->isStrategySupported(
            QueryStrategyInterface::STRATEGY_INDIVIDUAL_LOADING, 
            'oneHasMany'
        ));
        
        $this->assertTrue($this->selector->isStrategySupported(
            QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH, 
            'manyHasOne'
        ));
        
        $this->assertTrue($this->selector->isStrategySupported(
            QueryStrategyInterface::STRATEGY_JOIN_WITH_SELECTION, 
            'manyHasMany'
        ));
        
        $this->assertFalse($this->selector->isStrategySupported(
            'invalid_strategy', 
            'oneHasMany'
        ));
    }

    public function testConfigurationManagement()
    {
        $config = [
            'individual_loading_threshold' => 5,
            'join_strategy_threshold' => 0.3,
            'debug_mode' => true
        ];
        
        $this->selector->setConfig($config);
        $retrievedConfig = $this->selector->getConfig();
        
        $this->assertEquals(5, $retrievedConfig['individual_loading_threshold']);
        $this->assertEquals(0.3, $retrievedConfig['join_strategy_threshold']);
        $this->assertTrue($retrievedConfig['debug_mode']);
    }

    public function testCustomDataSizeEstimator()
    {
        $customEstimator = new DataSizeEstimator();
        $selector = new QueryStrategySelector($customEstimator);
        
        $strategy = $selector->selectStrategy($this->mockRelationship, 20, null);
        $this->assertIsString($strategy);
    }

    public function testShouldUseJoinStrategyWithNoFieldSelection()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->selector);
        $method = $reflection->getMethod('shouldUseJoinStrategy');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->selector, $this->mockRelationship, 20, null);
        $this->assertFalse($result); // Should not use JOIN without field selection
    }

    public function testShouldUseJoinStrategyWithFieldSelection()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->selector);
        $method = $reflection->getMethod('shouldUseJoinStrategy');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->selector, $this->mockRelationship, 20, ['id', 'title']);
        $this->assertIsBool($result);
    }

    public function testIsJoinOptimalForCardinality()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->selector);
        $method = $reflection->getMethod('isJoinOptimalForCardinality');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($this->selector, 'one-to-one'));
        $this->assertTrue($method->invoke($this->selector, 'many-to-one'));
        $this->assertTrue($method->invoke($this->selector, 'one-to-many'));
        $this->assertFalse($method->invoke($this->selector, 'many-to-many'));
        $this->assertFalse($method->invoke($this->selector, 'unknown'));
    }

    public function testEstimateQueryCount()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->selector);
        $method = $reflection->getMethod('estimateQueryCount');
        $method->setAccessible(true);
        
        $this->assertEquals(10, $method->invoke($this->selector, QueryStrategyInterface::STRATEGY_INDIVIDUAL_LOADING, 10));
        $this->assertEquals(1, $method->invoke($this->selector, QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH, 10));
        $this->assertEquals(1, $method->invoke($this->selector, QueryStrategyInterface::STRATEGY_JOIN_WITH_SELECTION, 10));
        $this->assertEquals(10, $method->invoke($this->selector, 'unknown_strategy', 10));
    }

    public function testGetDecisionFactors()
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->selector);
        $method = $reflection->getMethod('getDecisionFactors');
        $method->setAccessible(true);
        
        $factors = $method->invoke($this->selector, QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH, $this->mockRelationship, 20, ['id', 'title']);
        
        $this->assertIsArray($factors);
        $this->assertNotEmpty($factors);
        
        // Should contain information about field selection
        $hasFieldSelectionFactor = false;
        foreach ($factors as $factor) {
            if (str_contains($factor, 'Field selection available')) {
                $hasFieldSelectionFactor = true;
                break;
            }
        }
        $this->assertTrue($hasFieldSelectionFactor);
    }

    public function testSelectStrategyWithEdgeCases()
    {
        $relationship = $this->createMockRelationship('one-to-many');

        // Test with zero models
        $strategy = $this->selector->selectStrategy($relationship, 0, null);
        $this->assertEquals(QueryStrategyInterface::STRATEGY_INDIVIDUAL_LOADING, $strategy);

        // Test with very large model count
        $strategy = $this->selector->selectStrategy($relationship, 10000, null);
        $this->assertEquals(QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH, $strategy);

        // Test with field selection and small count - actual implementation may choose individual loading for very small counts
        $strategy = $this->selector->selectStrategy($relationship, 2, ['id', 'name']);
        $this->assertContains($strategy, [QueryStrategyInterface::STRATEGY_INDIVIDUAL_LOADING, QueryStrategyInterface::STRATEGY_JOIN_WITH_SELECTION]);
    }
}
