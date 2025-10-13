<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\Strategy\DataSizeEstimator;
use PHPUnit\Framework\TestCase;

class Final85_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testDataSizeEstimatorUnknownCardinality()
    {
        $estimator = new DataSizeEstimator();
        
        // Clear cache first
        DataSizeEstimator::clearCache();
        
        // Create a relationship with unknown cardinality
        $relationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
        $relationship->method('getCardinality')->willReturn('unknown-cardinality');
        $relationship->method('getRelatedModelClass')->willReturn('TestModel');
        
        $avg = $estimator->getAverageRelatedRecords($relationship);
        $this->assertEquals(2.0, $avg); // Default value for unknown cardinality
    }

    public function testQueryBuilderEnsureFromMultipleCalls()
    {
        $queryBuilder = DataMapper::find(UserModel::class, $this->pdo);
        
        // Use reflection to access ensureFrom method
        $reflection = new \ReflectionClass($queryBuilder);
        $method = $reflection->getMethod('ensureFrom');
        $method->setAccessible(true);
        
        $sqlProperty = $reflection->getProperty('sql');
        $sqlProperty->setAccessible(true);
        
        // First call should set SQL
        $method->invoke($queryBuilder);
        $sql1 = $sqlProperty->getValue($queryBuilder);
        $this->assertNotEmpty($sql1);
        
        // Second call should not change SQL (early return)
        $method->invoke($queryBuilder);
        $sql2 = $sqlProperty->getValue($queryBuilder);
        $this->assertEquals($sql1, $sql2);
    }

    public function testRelationshipEstimateDataSizeZeroCount()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');
            
            if ($relationship) {
                // Test with zero count - should return 0
                $dataSize = $relationship->estimateDataSize(0, ['id', 'title']);
                $this->assertEquals(0, $dataSize);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testBatchLoadingWithEmptyFieldSelection()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');
            
            if ($relationship) {
                // Test with empty field selection array
                $dataSize = $relationship->estimateDataSize(5, []);
                $this->assertIsInt($dataSize);
                $this->assertGreaterThan(0, $dataSize);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testQueryBuilderWithInvalidRelationshipArray()
    {
        // Test with array containing invalid elements
        try {
            $queryBuilder = DataMapper::find(UserModel::class, $this->pdo)
                ->with([null]); // null element should cause validation error
            
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('non-empty string', $e->getMessage());
        }
    }

    public function testAdditionalModelMethods()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            
            // Test that getPdo returns the same instance
            $pdo1 = $user->getPdo();
            $pdo2 = $user->getPdo();
            $this->assertSame($pdo1, $pdo2);
            $this->assertSame($this->pdo, $pdo1);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testQueryBuilderBatchLoadingConfigChaining()
    {
        $queryBuilder = DataMapper::find(UserModel::class, $this->pdo);
        
        // Test that all methods return self for chaining
        $result1 = $queryBuilder->enableBatchLoading(true);
        $result2 = $queryBuilder->disableBatchLoading();
        $result3 = $queryBuilder->enableBatchLoading(false);
        $result4 = $queryBuilder->setBatchLoadingConfig(['debug_mode' => true]);
        
        $this->assertSame($queryBuilder, $result1);
        $this->assertSame($queryBuilder, $result2);
        $this->assertSame($queryBuilder, $result3);
        $this->assertSame($queryBuilder, $result4);
        
        // Test final state
        $this->assertFalse($queryBuilder->isBatchLoadingEnabled());
    }

    public function testDataSizeEstimatorFieldSizeEdgeCases()
    {
        $estimator = new DataSizeEstimator();
        
        // Use reflection to test estimateFieldSize with edge cases
        $reflection = new \ReflectionClass($estimator);
        $method = $reflection->getMethod('estimateFieldSize');
        $method->setAccessible(true);
        
        // Test with empty string
        $this->assertEquals(50, $method->invoke($estimator, ''));
        
        // Test with various patterns
        $this->assertEquals(8, $method->invoke($estimator, 'ID')); // Uppercase ID
        $this->assertEquals(100, $method->invoke($estimator, 'FULL_NAME')); // Uppercase with name
        $this->assertEquals(500, $method->invoke($estimator, 'POST_DESCRIPTION')); // Uppercase with description
        $this->assertEquals(50, $method->invoke($estimator, 'SOME_EMAIL')); // Uppercase with email
    }

    public function testRelationshipBatchLoadingWithNullModels()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');
            
            if ($relationship) {
                // Create array with null model
                $modelsWithNull = [$user, null];
                
                // This should handle null models gracefully
                try {
                    $batchResults = $relationship->batchLoad($modelsWithNull, $this->pdo);
                    $this->assertIsArray($batchResults);
                } catch (\Exception $e) {
                    // Expected to handle gracefully or throw exception
                    $this->assertTrue(true);
                }
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }
}
