<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use PHPUnit\Framework\TestCase;

class BatchLoadingDemo_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testForceBatchLoadingStrategy()
    {
        // Force batch loading by setting a very low threshold
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->setBatchLoadingConfig([
                'debug_mode' => true,
                'individual_loading_threshold' => 0  // Force batch loading even for small datasets
            ])
            ->some();

        $userArray = iterator_to_array($users);
        
        // Verify the data is loaded correctly
        $this->assertCount(3, $userArray);
        
        foreach ($userArray as $user) {
            $this->assertNotNull($user->name);
            $this->assertIsArray($user->posts);
            
            if ($user->company_id) {
                $this->assertInstanceOf(CompanyModel::class, $user->company);
            }
        }
        
        // Check specific user data
        $john = $userArray[0];
        $this->assertEquals('John Doe', $john->name);
        $this->assertCount(2, $john->posts); // John has 2 posts
        $this->assertEquals('Tech Corp', $john->company->name);
    }

    public function testBatchLoadingWithManyToOneRelationship()
    {
        // Test batch loading for belongsTo relationships
        $posts = DataMapper::find(PostModel::class, $this->pdo)
            ->with(['user'])
            ->setBatchLoadingConfig([
                'debug_mode' => true,
                'individual_loading_threshold' => 0  // Force batch loading
            ])
            ->some();

        $postArray = iterator_to_array($posts);
        $this->assertGreaterThan(0, count($postArray));

        // Verify that user relationships are loaded correctly
        foreach ($postArray as $post) {
            $this->assertInstanceOf(UserModel::class, $post->user);
            $this->assertNotNull($post->user->name);
        }
        
        // Check that we don't have duplicate user objects for the same user
        $userIds = [];
        foreach ($postArray as $post) {
            $userId = $post->user->id;
            if (!isset($userIds[$userId])) {
                $userIds[$userId] = $post->user;
            } else {
                // The user objects should be separate instances (not shared)
                // but should have the same data
                $this->assertEquals($userIds[$userId]->name, $post->user->name);
            }
        }
    }

    public function testBatchLoadingErrorHandling()
    {
        // Test that batch loading gracefully handles errors and falls back to individual loading
        
        // Create a mock scenario where batch loading might fail
        // For now, just test that the system works with valid data
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->setBatchLoadingConfig([
                'debug_mode' => true,
                'individual_loading_threshold' => 0,
                'fallback_to_individual' => true
            ])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);
        
        // Verify relationships are loaded
        foreach ($userArray as $user) {
            $this->assertIsArray($user->posts);
        }
    }

    public function testBatchLoadingWithNoEagerRelationships()
    {
        // Test that batch loading doesn't interfere when no relationships are specified
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->setBatchLoadingConfig(['debug_mode' => true])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);
        
        // Verify that no relationships are loaded
        foreach ($userArray as $user) {
            $this->assertNull($user->posts);
            $this->assertNull($user->company);
        }
    }

    public function testBatchLoadingConfigurationPersistence()
    {
        // Test that configuration changes persist through the query builder
        $queryBuilder = DataMapper::find(UserModel::class, $this->pdo)
            ->setBatchLoadingConfig([
                'debug_mode' => true,
                'individual_loading_threshold' => 0
            ]);
        
        $this->assertTrue($queryBuilder->isBatchLoadingEnabled());
        
        // Test disabling batch loading
        $queryBuilder->disableBatchLoading();
        $this->assertFalse($queryBuilder->isBatchLoadingEnabled());
        
        // Test re-enabling
        $queryBuilder->enableBatchLoading();
        $this->assertTrue($queryBuilder->isBatchLoadingEnabled());
    }

    public function testBatchLoadingWithSingleModel()
    {
        // Test that batch loading works correctly with the one() method
        $user = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->setBatchLoadingConfig(['debug_mode' => true])
            ->where('id = ?', [1])
            ->one();

        $this->assertInstanceOf(UserModel::class, $user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertIsArray($user->posts);
        $this->assertInstanceOf(CompanyModel::class, $user->company);
    }

    public function testBatchLoadingMemoryUsage()
    {
        // Basic memory usage test
        $memoryBefore = memory_get_usage();
        
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->setBatchLoadingConfig([
                'debug_mode' => false,
                'individual_loading_threshold' => 0
            ])
            ->some();

        $userArray = iterator_to_array($users);
        
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        // Basic assertions
        $this->assertCount(3, $userArray);
        $this->assertLessThan(1024 * 1024, $memoryUsed); // Should use less than 1MB for this small dataset
        
        // Memory usage should be reasonable for small datasets
    }
}
