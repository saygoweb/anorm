<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\BatchLoader\ManyHasManyBatchLoader;
use Anorm\Relationship\Strategy\DataSizeEstimator;
use Anorm\Relationship\Strategy\FieldSelectionParser;
use PHPUnit\Framework\TestCase;

class AdditionalCoverage_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testManyHasManyBatchLoaderWithEmptyModels()
    {
        $batchLoader = new ManyHasManyBatchLoader();

        // Test with empty models array
        $result = $batchLoader->batchLoad([], 'any_relationship');
        $this->assertEquals([], $result);

        // Test distribution with empty models - this should work without accessing relationships
        $batchLoader->distributeBatchResults([], [], 'any_relationship');
        $this->assertTrue(true); // Should not throw exception

        // Test other methods
        $this->assertEquals(0, $batchLoader->estimateQueryCount(0));
        $this->assertEquals(1, $batchLoader->estimateQueryCount(10));
        $this->assertEquals(500, $batchLoader->getMaxBatchSize());
    }

    public function testFieldSelectionParserEdgeCases()
    {
        $parser = new FieldSelectionParser();
        
        // Test with whitespace-only field
        $result = $parser->parseFieldSelection('posts:  ,  title  ,  ');
        $this->assertEquals('posts', $result['relationship']);
        $this->assertEquals([1 => 'title'], $result['fields']); // Empty fields should be filtered out, preserving keys
        
        // Test isAllFields method
        $this->assertTrue($parser->isAllFields(null));
        $this->assertTrue($parser->isAllFields([]));
        $this->assertFalse($parser->isAllFields(['id', 'title']));
        
        // Test generateSelectClause with empty fields
        $selectClause = $parser->generateSelectClause([], 'p', 'post');
        $this->assertEquals('`p`.*', $selectClause);
        
        // Test extractPrefixedFields with no matching prefix
        $row = ['user_id' => 1, 'user_name' => 'John'];
        $extracted = $parser->extractPrefixedFields($row, 'post');
        $this->assertEquals([], $extracted);
    }

    public function testDataSizeEstimatorEdgeCases()
    {
        $estimator = new DataSizeEstimator();
        
        // Test with unknown cardinality
        $relationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
        $relationship->method('getCardinality')->willReturn('unknown-type');
        $relationship->method('getRelatedModelClass')->willReturn('TestModel');
        
        $avg = $estimator->getAverageRelatedRecords($relationship);
        $this->assertEquals(2.0, $avg); // Unknown cardinality returns default value of 2.0
        
        // Test calculateSelectedFieldSize with empty array
        $size = $estimator->calculateSelectedFieldSize([]);
        $this->assertEquals(0, $size);
        
        // Test field size estimation with various field names
        $reflection = new \ReflectionClass($estimator);
        $method = $reflection->getMethod('estimateFieldSize');
        $method->setAccessible(true);
        
        // Test edge cases
        $this->assertEquals(50, $method->invoke($estimator, '')); // Empty field name
        $this->assertEquals(8, $method->invoke($estimator, 'ID')); // Uppercase
        $this->assertEquals(8, $method->invoke($estimator, 'company_id')); // Compound with _id
        $this->assertEquals(100, $method->invoke($estimator, 'full_name')); // Contains 'name'
        $this->assertEquals(500, $method->invoke($estimator, 'post_content')); // Contains 'content'
    }

    public function testRelationshipEstimateDataSizeEdgeCases()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');
            
            if ($relationship) {
                // Test with zero source count
                $dataSize = $relationship->estimateDataSize(0, null);
                $this->assertEquals(0, $dataSize);
                
                // Test with zero source count and field selection
                $dataSize = $relationship->estimateDataSize(0, ['id', 'title']);
                $this->assertEquals(0, $dataSize);
                
                // Test with large source count
                $dataSize = $relationship->estimateDataSize(1000, null);
                $this->assertIsInt($dataSize);
                $this->assertGreaterThan(0, $dataSize);
            } else {
                $this->markTestSkipped('Posts relationship not found');
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testBatchLoadingWithMixedNullValues()
    {
        // Create models with mixed null/non-null values
        $models = [];
        for ($i = 0; $i < 5; $i++) {
            $model = new UserModel($this->pdo);
            $model->id = ($i % 2 === 0) ? $i + 1 : null; // Alternate null/non-null
            $models[] = $model;
        }
        
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');
            
            if ($relationship) {
                // Test batch loading with mixed null values
                $batchResults = $relationship->batchLoad($models, $this->pdo);
                $this->assertIsArray($batchResults);
                
                // Test distribution
                $relationship->distributeBatchResults($models, $batchResults);
                
                // Verify that models with null IDs get empty arrays
                foreach ($models as $model) {
                    if ($model->id === null) {
                        $this->assertIsArray($model->posts ?? []);
                    }
                }
            } else {
                $this->markTestSkipped('Posts relationship not found');
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testFieldSelectionValidationEdgeCases()
    {
        $parser = new FieldSelectionParser();
        
        // Test validation with various field types
        $fields = [
            'id',           // Valid
            'name',         // Valid
            '_private',     // Valid but warning
            '',             // Invalid
            123,            // Invalid (not string)
            null,           // Invalid
        ];
        
        $validation = $parser->validateFields($fields, 'TestModel');
        
        $this->assertContains('id', $validation['valid']);
        $this->assertContains('name', $validation['valid']);
        $this->assertContains('_private', $validation['valid']);
        $this->assertContains('', $validation['invalid']);
        $this->assertContains(123, $validation['invalid']);
        $this->assertContains(null, $validation['invalid']);
        
        // Should have warning about _private field
        $this->assertNotEmpty($validation['warnings']);
    }

    public function testCacheManagement()
    {
        $estimator = new DataSizeEstimator();
        
        // Set some cache values
        DataSizeEstimator::setCacheValue('test_key_1', 100);
        DataSizeEstimator::setCacheValue('test_key_2', 'test_value');
        
        // Access cache via reflection to verify
        $reflection = new \ReflectionClass(DataSizeEstimator::class);
        $property = $reflection->getProperty('cache');
        $property->setAccessible(true);
        $cache = $property->getValue();
        
        $this->assertEquals(100, $cache['test_key_1']);
        $this->assertEquals('test_value', $cache['test_key_2']);
        
        // Clear cache
        DataSizeEstimator::clearCache();
        
        // Verify cache is empty
        $cache = $property->getValue();
        $this->assertEmpty($cache);
    }

    public function testQueryBuilderBatchLoadingMethods()
    {
        $queryBuilder = DataMapper::find(UserModel::class, $this->pdo);
        
        // Test batch loading configuration methods
        $this->assertTrue($queryBuilder->isBatchLoadingEnabled()); // Default is enabled
        
        $queryBuilder->disableBatchLoading();
        $this->assertFalse($queryBuilder->isBatchLoadingEnabled());
        
        $queryBuilder->enableBatchLoading();
        $this->assertTrue($queryBuilder->isBatchLoadingEnabled());
        
        $queryBuilder->enableBatchLoading(false);
        $this->assertFalse($queryBuilder->isBatchLoadingEnabled());
        
        // Test configuration setting
        $config = ['debug_mode' => true, 'max_batch_size' => 500];
        $result = $queryBuilder->setBatchLoadingConfig($config);
        $this->assertSame($queryBuilder, $result); // Should return self for chaining
    }
}
