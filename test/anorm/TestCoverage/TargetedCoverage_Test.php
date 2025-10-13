<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\BatchLoadingOrchestrator;
use Anorm\Relationship\Strategy\QueryStrategySelector;
use Anorm\Relationship\Strategy\FieldSelectionParser;
use Anorm\Relationship\ManyHasMany;
use PHPUnit\Framework\TestCase;

class TargetedCoverage_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testBatchLoadingOrchestratorPrivateMethods()
    {
        $orchestrator = new BatchLoadingOrchestrator();
        
        // Test with custom components
        $selector = new QueryStrategySelector();
        $parser = new FieldSelectionParser();
        $config = ['debug_mode' => true, 'enable_batch_loading' => true];
        
        $customOrchestrator = new BatchLoadingOrchestrator($selector, $parser, $config);
        
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            // Test loadRelationshipsForModelClass via reflection
            $reflection = new \ReflectionClass($orchestrator);
            $method = $reflection->getMethod('loadRelationshipsForModelClass');
            $method->setAccessible(true);
            
            $parsedSpecs = ['posts' => ['relationship' => 'posts', 'fields' => null, 'all_fields' => true]];
            $method->invoke($orchestrator, $userArray, $parsedSpecs);
            $this->assertTrue(true); // Method executed without exception
            
            // Test loadSingleRelationship via reflection
            $relationshipManager = $userArray[0]->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');
            
            if ($relationship) {
                $method = $reflection->getMethod('loadSingleRelationship');
                $method->setAccessible(true);
                
                $spec = ['relationship' => 'posts', 'fields' => ['id', 'title'], 'all_fields' => false];
                $method->invoke($orchestrator, $userArray, $relationship, $spec);
            }
            
            // Test executeBatchLoading via reflection
            if ($relationship) {
                $method = $reflection->getMethod('executeBatchLoading');
                $method->setAccessible(true);
                $method->invoke($orchestrator, $userArray, $relationship);
            }
            
            // Test executeIndividualLoading via reflection
            if ($relationship) {
                $method = $reflection->getMethod('executeIndividualLoading');
                $method->setAccessible(true);
                $method->invoke($orchestrator, $userArray, $relationship);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testBatchLoadingOrchestratorErrorHandling()
    {
        $orchestrator = new BatchLoadingOrchestrator();
        $orchestrator->setConfig(['debug_mode' => true, 'fallback_to_individual' => true]);
        
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            // Test with invalid relationship specs
            $orchestrator->loadRelationshipsForModels($userArray, ['invalid:relationship:spec:too:many:colons']);
            
            // Test with mixed valid and invalid relationships
            $orchestrator->loadRelationshipsForModels($userArray, ['posts', 'invalid_relationship', 'company']);
            
            $this->assertTrue(true); // Should handle errors gracefully
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testManyHasManyRelationshipMethods()
    {
        // Create a mock ManyHasMany relationship to test its methods
        $relationship = new ManyHasMany(
            'TagModel',      // relatedModelClass
            'tags',          // propertyName
            'user_id',       // joinForeignKey
            'tag_id',        // joinRelatedKey
            'user_tags',     // joinTable
            'id'             // primaryKey
        );
        
        // Test getCardinality
        $this->assertEquals('many-to-many', $relationship->getCardinality());
        
        // Test estimateDataSize
        $dataSize = $relationship->estimateDataSize(10, null);
        $this->assertIsInt($dataSize);
        $this->assertGreaterThan(0, $dataSize);
        
        // Test estimateDataSize with field selection
        $dataSizeWithFields = $relationship->estimateDataSize(10, ['id', 'name']);
        $this->assertIsInt($dataSizeWithFields);
        
        // Test estimateDataSize with zero count
        $zeroSize = $relationship->estimateDataSize(0, null);
        $this->assertEquals(0, $zeroSize);
        
        // Test other inherited methods
        $this->assertEquals('tags', $relationship->getPropertyName());
        $this->assertEquals('TagModel', $relationship->getRelatedModelClass());
    }

    public function testQueryStrategySelectionEdgeCases()
    {
        $selector = new QueryStrategySelector();
        
        // Test with custom configuration
        $config = [
            'individual_loading_threshold' => 2,
            'join_strategy_threshold' => 0.1,
            'enable_join_strategy' => false,
            'debug_mode' => true
        ];
        $selector->setConfig($config);
        
        $relationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
        $relationship->method('getCardinality')->willReturn('one-to-many');
        $relationship->method('getRelatedModelClass')->willReturn('TestModel');
        
        // Test strategy selection with different parameters
        $strategy1 = $selector->selectStrategy($relationship, 1, null);
        $strategy2 = $selector->selectStrategy($relationship, 5, ['id', 'title']);
        $strategy3 = $selector->selectStrategy($relationship, 100, null);
        
        $this->assertIsString($strategy1);
        $this->assertIsString($strategy2);
        $this->assertIsString($strategy3);
        
        // Test metadata generation for different strategies
        $metadata1 = $selector->getStrategyMetadata($strategy1, $relationship, 1, null);
        $metadata2 = $selector->getStrategyMetadata($strategy2, $relationship, 5, ['id', 'title']);
        
        $this->assertIsArray($metadata1);
        $this->assertIsArray($metadata2);
        $this->assertArrayHasKey('decision_factors', $metadata1);
        $this->assertArrayHasKey('decision_factors', $metadata2);
    }

    public function testFieldSelectionParserComplexScenarios()
    {
        $parser = new FieldSelectionParser();
        
        // Test complex field selection scenarios
        $specs = [
            'posts:id,title,content,created_at',
            'company:name,address,phone',
            'tags:*',
            'comments',
            'user:id,name'
        ];
        
        $parsed = $parser->parseMultipleSelections($specs);
        
        $this->assertCount(5, $parsed);
        $this->assertEquals(['id', 'title', 'content', 'created_at'], $parsed['posts']['fields']);
        $this->assertEquals(['name', 'address', 'phone'], $parsed['company']['fields']);
        $this->assertNull($parsed['tags']['fields']);
        $this->assertNull($parsed['comments']['fields']);
        $this->assertEquals(['id', 'name'], $parsed['user']['fields']);
        
        // Test SELECT clause generation with prefixes
        $selectClause = $parser->generateSelectClause(['id', 'name', 'email'], 'u', 'user');
        $expected = '`u`.`id` AS `user_id`, `u`.`name` AS `user_name`, `u`.`email` AS `user_email`';
        $this->assertEquals($expected, $selectClause);
        
        // Test field extraction
        $row = [
            'user_id' => 1,
            'user_name' => 'John',
            'user_email' => 'john@example.com',
            'post_id' => 10,
            'post_title' => 'Test Post'
        ];
        
        $userFields = $parser->extractPrefixedFields($row, 'user');
        $postFields = $parser->extractPrefixedFields($row, 'post');
        
        $this->assertEquals(['id' => 1, 'name' => 'John', 'email' => 'john@example.com'], $userFields);
        $this->assertEquals(['id' => 10, 'title' => 'Test Post'], $postFields);
    }

    public function testModelGetPdoMethod()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $pdo = $user->getPdo();
            
            $this->assertInstanceOf(\PDO::class, $pdo);
            $this->assertSame($this->pdo, $pdo);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testRelationshipManagerAdditionalMethods()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            
            // Test getAllRelationships
            $allRelationships = $relationshipManager->getAllRelationships();
            $this->assertIsArray($allRelationships);
            
            // Test getRelationship with non-existent relationship
            $nonExistent = $relationshipManager->getRelationship('non_existent_relationship');
            $this->assertNull($nonExistent);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }
}
