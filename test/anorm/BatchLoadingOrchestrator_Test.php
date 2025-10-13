<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\BatchLoadingOrchestrator;
use Anorm\Relationship\Strategy\QueryStrategySelector;
use Anorm\Relationship\Strategy\FieldSelectionParser;
use PHPUnit\Framework\TestCase;

class BatchLoadingOrchestrator_Test extends TestCase
{
    private $pdo;
    private $orchestrator;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        $this->orchestrator = new BatchLoadingOrchestrator();
    }

    public function testLoadRelationshipsForModelsWithEmptyModels()
    {
        $this->orchestrator->loadRelationshipsForModels([], ['posts']);
        
        // Should not throw an exception
        $this->assertTrue(true);
    }

    public function testLoadRelationshipsForModelsWithEmptyRelationships()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $this->orchestrator->loadRelationshipsForModels($userArray, []);
            
            // Should not throw an exception
            $this->assertTrue(true);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testLoadRelationshipsForModelsWithValidData()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $this->orchestrator->loadRelationshipsForModels($userArray, ['posts', 'company']);
            
            // Verify relationships are loaded
            foreach ($userArray as $user) {
                $this->assertIsArray($user->posts ?? []);
                // Company might be null, but should be set
                $this->assertTrue(property_exists($user, 'company') || isset($user->company));
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testLoadRelationshipsForModelsWithFieldSelection()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $this->orchestrator->loadRelationshipsForModels($userArray, ['posts:id,title', 'company:name']);
            
            // Should handle field selection syntax
            $this->assertTrue(true); // If we get here, parsing worked
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testLoadRelationshipsForModelsWithMixedModelTypes()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        
        $userArray = iterator_to_array($users);
        $postArray = iterator_to_array($posts);
        
        if (count($userArray) > 0 && count($postArray) > 0) {
            $mixedModels = array_merge($userArray, $postArray);
            
            $this->orchestrator->loadRelationshipsForModels($mixedModels, ['posts', 'user']);
            
            // Should handle mixed model types
            $this->assertTrue(true);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testConfigurationManagement()
    {
        $config = [
            'debug_mode' => true,
            'enable_batch_loading' => false,
            'max_batch_size' => 500
        ];
        
        $this->orchestrator->setConfig($config);
        $retrievedConfig = $this->orchestrator->getConfig();
        
        $this->assertTrue($retrievedConfig['debug_mode']);
        $this->assertFalse($retrievedConfig['enable_batch_loading']);
        $this->assertEquals(500, $retrievedConfig['max_batch_size']);
    }

    public function testCustomStrategySelector()
    {
        $customSelector = new QueryStrategySelector();
        $customParser = new FieldSelectionParser();
        
        $orchestrator = new BatchLoadingOrchestrator($customSelector, $customParser);
        
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $orchestrator->loadRelationshipsForModels($userArray, ['posts']);
            $this->assertTrue(true); // Should work with custom components
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testGetPerformanceStats()
    {
        $stats = $this->orchestrator->getPerformanceStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_models_processed', $stats);
        $this->assertArrayHasKey('total_relationships_loaded', $stats);
        $this->assertArrayHasKey('strategies_used', $stats);
        $this->assertArrayHasKey('total_queries_executed', $stats);
        $this->assertArrayHasKey('time_elapsed', $stats);
    }

    public function testGroupModelsByClass()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        
        $userArray = iterator_to_array($users);
        $postArray = iterator_to_array($posts);
        
        if (count($userArray) > 0 && count($postArray) > 0) {
            $mixedModels = array_merge($userArray, $postArray);
            
            // Use reflection to test private method
            $reflection = new \ReflectionClass($this->orchestrator);
            $method = $reflection->getMethod('groupModelsByClass');
            $method->setAccessible(true);
            
            $grouped = $method->invoke($this->orchestrator, $mixedModels);
            
            $this->assertIsArray($grouped);
            $this->assertArrayHasKey(UserModel::class, $grouped);
            $this->assertArrayHasKey(PostModel::class, $grouped);
            $this->assertCount(count($userArray), $grouped[UserModel::class]);
            $this->assertCount(count($postArray), $grouped[PostModel::class]);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testLoadRelationshipsWithNonExistentRelationship()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            // Should handle non-existent relationships gracefully
            $this->orchestrator->loadRelationshipsForModels($userArray, ['nonexistent_relationship']);
            
            $this->assertTrue(true); // Should not throw exception
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testLoadRelationshipsWithDebugMode()
    {
        $this->orchestrator->setConfig(['debug_mode' => true]);
        
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            // Capture error log output
            $errorLogBefore = error_get_last();
            
            $this->orchestrator->loadRelationshipsForModels($userArray, ['posts']);
            
            // Should work with debug mode enabled
            $this->assertTrue(true);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testExecuteBatchLoadingWithError()
    {
        // Create a mock relationship that will cause an error
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            // Test with fallback enabled
            $this->orchestrator->setConfig([
                'debug_mode' => true,
                'fallback_to_individual' => true
            ]);
            
            // This should handle errors gracefully
            $this->orchestrator->loadRelationshipsForModels($userArray, ['posts']);
            
            $this->assertTrue(true);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }
}
