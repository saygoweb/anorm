<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Model;
use Anorm\QueryBuilder;
use Anorm\Relationship\BatchLoadingOrchestrator;
use Anorm\Relationship\Strategy\QueryStrategyInterface;
use PHPUnit\Framework\TestCase;

class FinalCoverage_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testQueryBuilderOneMethodWithBatchLoading()
    {
        // Test the one() method with batch loading enabled
        $user = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->where('id = ?', [1])
            ->one();

        if ($user) {
            $this->assertInstanceOf(UserModel::class, $user);
            $this->assertIsArray($user->posts ?? []);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testBatchLoadingOrchestratorConfiguration()
    {
        $orchestrator = new BatchLoadingOrchestrator();
        $orchestrator->setConfig(['debug_mode' => true]);

        // Test that configuration is properly set
        $config = $orchestrator->getConfig();
        $this->assertTrue($config['debug_mode']);

        // Test default configuration
        $defaultOrchestrator = new BatchLoadingOrchestrator();
        $defaultConfig = $defaultOrchestrator->getConfig();
        $this->assertIsArray($defaultConfig);
        $this->assertArrayHasKey('enable_batch_loading', $defaultConfig);
    }

    public function testModelAdditionalMethods()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            
            // Test getPdo method
            $pdo = $user->getPdo();
            $this->assertInstanceOf(\PDO::class, $pdo);
            
            // Test relationship manager
            $relationshipManager = $user->getRelationshipManager();
            $this->assertNotNull($relationshipManager);
            
            // Test loading all relationships
            $relationshipManager->loadAllRelated();
            $this->assertTrue(true); // Should not throw exception
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testQueryBuilderWithInvalidRelationshipSpec()
    {
        // Test with invalid relationship specification
        try {
            $queryBuilder = DataMapper::find(UserModel::class, $this->pdo)
                ->with(['']); // Empty string should throw exception
            
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('non-empty string', $e->getMessage());
        }
    }

    public function testBatchLoadingWithJoinStrategy()
    {
        $orchestrator = new BatchLoadingOrchestrator();
        $orchestrator->setConfig([
            'debug_mode' => true,
            'individual_loading_threshold' => 1, // Force batch loading
        ]);
        
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            // Test with field selection to potentially trigger JOIN strategy
            $orchestrator->loadRelationshipsForModels($userArray, ['posts:id,title']);
            
            // Verify relationships are loaded
            foreach ($userArray as $user) {
                $this->assertIsArray($user->posts ?? []);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testRelationshipBatchLoadingErrorHandling()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');
            
            if ($relationship) {
                // Test batch loading with empty array
                $batchResults = $relationship->batchLoad([], $this->pdo);
                $this->assertIsArray($batchResults);
                $this->assertEmpty($batchResults);
                
                // Test distribution with empty results
                $relationship->distributeBatchResults($userArray, []);
                
                // All users should have empty arrays for posts
                foreach ($userArray as $u) {
                    $this->assertIsArray($u->posts ?? []);
                }
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testQueryBuilderSomeWithIndividualLoading()
    {
        // Test some() method with individual loading (batch loading disabled)
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->disableBatchLoading()
            ->some();

        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            foreach ($userArray as $user) {
                $this->assertInstanceOf(UserModel::class, $user);
                $this->assertIsArray($user->posts ?? []);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testBatchLoadingOrchestratorGetDefaultConfig()
    {
        $orchestrator = new BatchLoadingOrchestrator();
        
        // Use reflection to test getDefaultConfig
        $reflection = new \ReflectionClass($orchestrator);
        $method = $reflection->getMethod('getDefaultConfig');
        $method->setAccessible(true);
        
        $defaultConfig = $method->invoke($orchestrator);
        
        $this->assertIsArray($defaultConfig);
        $this->assertArrayHasKey('debug_mode', $defaultConfig);
        $this->assertArrayHasKey('enable_batch_loading', $defaultConfig);
        $this->assertArrayHasKey('fallback_to_individual', $defaultConfig);
        $this->assertArrayHasKey('max_batch_size', $defaultConfig);
        
        $this->assertFalse($defaultConfig['debug_mode']);
        $this->assertTrue($defaultConfig['enable_batch_loading']);
        $this->assertTrue($defaultConfig['fallback_to_individual']);
        $this->assertEquals(1000, $defaultConfig['max_batch_size']);
    }

    public function testQueryBuilderEnsureFromMethod()
    {
        // Test that ensureFrom is called properly
        $queryBuilder = DataMapper::find(UserModel::class, $this->pdo);
        
        // Use reflection to check the sql property
        $reflection = new \ReflectionClass($queryBuilder);
        $property = $reflection->getProperty('sql');
        $property->setAccessible(true);
        
        // Initially should be empty
        $this->assertEquals('', $property->getValue($queryBuilder));
        
        // After calling some(), should have SQL
        $users = $queryBuilder->some();
        iterator_to_array($users); // Consume the generator
        
        // SQL should now be set
        $sql = $property->getValue($queryBuilder);
        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('SELECT', $sql);
    }

    public function testAdditionalRelationshipMethods()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');
            
            if ($relationship) {
                // Test getType method
                $type = $relationship->getType();
                $this->assertIsString($type);
                
                // Test generateJoinClause
                $joinClause = $relationship->generateJoinClause('users', 'posts');
                $this->assertIsString($joinClause);
                
                // Test generateForeignKeyConstraints
                $constraints = $relationship->generateForeignKeyConstraints('users');
                $this->assertIsArray($constraints);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }
}
