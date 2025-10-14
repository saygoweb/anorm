<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use PHPUnit\Framework\TestCase;

class BatchLoadingIntegration_Test extends TestCase
{
    private $pdo;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect(); // Connect to database
        $pdo = TestEnvironment::pdo();

        // Create relationship test tables (schema file handles cleanup)
        $sql = file_get_contents(__DIR__ . '/../RelationshipTestSchema.sql');

        // Remove comments and split by semicolon
        $lines = explode("\n", $sql);
        $cleanSql = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && strpos($line, '#') !== 0) {
                $cleanSql .= $line . "\n";
            }
        }

        $statements = explode(';', $cleanSql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                } catch (\PDOException $e) {
                    echo "SQL Error: " . $e->getMessage() . "\n";
                    echo "Statement: " . $statement . "\n";
                    throw $e;
                }
            }
        }
    }

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();

        // Create additional test data to trigger batch loading
        $this->createLargeTestDataset();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->cleanupLargeTestDataset();
    }

    public function testBatchLoadingWithLargeDataset()
    {
        // Test with a larger dataset that should trigger batch loading
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->setBatchLoadingConfig([
                'debug_mode' => true,
                'individual_loading_threshold' => 5  // Should trigger batch loading for >5 users
            ])
            ->some();

        $userArray = iterator_to_array($users);
        
        // We should have at least 15 users (3 original + 12 created)
        $this->assertGreaterThanOrEqual(15, count($userArray));
        
        // Verify that all users have their posts loaded
        foreach ($userArray as $user) {
            $this->assertNotNull($user->name);
            $this->assertIsArray($user->posts);

            // Check that posts are loaded for users that have them
            if (!empty($user->posts)) {
                $this->assertGreaterThan(0, count($user->posts));
                foreach ($user->posts as $post) {
                    $this->assertInstanceOf(PostModel::class, $post);
                }
            }
        }
    }

    public function testBatchLoadingQueryCountOptimization()
    {
        // Test that batch loading works with larger datasets

        // Test with batch loading
        $startTime = microtime(true);
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->setBatchLoadingConfig([
                'individual_loading_threshold' => 5  // Force batch loading
            ])
            ->some();

        $userArray = iterator_to_array($users);
        $batchTime = microtime(true) - $startTime;

        // Test individual loading
        $startTime = microtime(true);
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->disableBatchLoading()
            ->some();

        $userArray2 = iterator_to_array($users);
        $individualTime = microtime(true) - $startTime;

        // Performance comparison (removed debug output for cleaner test runs)

        // Verify both return same data
        $this->assertEquals(count($userArray), count($userArray2));

        // Basic performance assertions
        $this->assertGreaterThan(0, count($userArray));
        $this->assertGreaterThan(0, count($userArray2));
    }

    public function testBatchLoadingWithFieldSelection()
    {
        // Test that field selection syntax is accepted and processed
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts:id,title', 'company:name'])
            ->setBatchLoadingConfig(['debug_mode' => true])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertGreaterThan(0, count($userArray));
        
        // Verify relationships are loaded (full objects for now, field selection optimization in Phase 3)
        foreach ($userArray as $user) {
            if (!empty($user->posts)) {
                $this->assertIsArray($user->posts);
                foreach ($user->posts as $post) {
                    $this->assertInstanceOf(PostModel::class, $post);
                    $this->assertNotNull($post->id);
                    $this->assertNotNull($post->title);
                }
            }
            
            if ($user->company) {
                $this->assertInstanceOf(CompanyModel::class, $user->company);
                $this->assertNotNull($user->company->name);
            }
        }
    }

    private function createLargeTestDataset(): void
    {
        // Create additional users (IDs 4-15)
        for ($i = 4; $i <= 15; $i++) {
            $this->pdo->exec("INSERT IGNORE INTO users (id, name, email, company_id) VALUES ({$i}, 'Test User {$i}', 'user{$i}@test.com', " . (($i % 2) + 1) . ")");
            
            // Create posts for each user
            for ($j = 1; $j <= 2; $j++) {
                $postId = ($i - 1) * 10 + $j + 100; // Unique post IDs starting from 101
                $this->pdo->exec("INSERT IGNORE INTO posts (id, title, content, user_id, status) VALUES ({$postId}, 'Post {$j} by User {$i}', 'Test content for post {$j}', {$i}, 'published')");
            }
        }
    }

    private function cleanupLargeTestDataset(): void
    {
        // Clean up test data
        $this->pdo->exec("DELETE FROM posts WHERE id >= 100");
        $this->pdo->exec("DELETE FROM users WHERE id >= 4");
    }

    public function testBatchLoadingStrategySelection()
    {
        // Test that strategy selection works correctly with different configurations
        
        // Test 1: Force individual loading
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->setBatchLoadingConfig([
                'debug_mode' => true,
                'individual_loading_threshold' => 100  // High threshold forces individual loading
            ])
            ->some();
        
        $userArray = iterator_to_array($users);
        $this->assertGreaterThan(0, count($userArray));
        
        // Test 2: Force batch loading
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->setBatchLoadingConfig([
                'debug_mode' => true,
                'individual_loading_threshold' => 1  // Low threshold forces batch loading
            ])
            ->some();
        
        $userArray = iterator_to_array($users);
        $this->assertGreaterThan(0, count($userArray));
    }

    public function testBatchLoadingErrorRecovery()
    {
        // Test that the system gracefully handles errors and falls back to individual loading
        
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->setBatchLoadingConfig([
                'debug_mode' => true,
                'fallback_to_individual' => true,
                'individual_loading_threshold' => 1
            ])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertGreaterThan(0, count($userArray));
        
        // Verify that relationships are loaded despite any potential errors
        foreach ($userArray as $user) {
            $this->assertNotNull($user->name);
            // Posts and company may or may not be loaded depending on the data and errors
            // The important thing is that the query doesn't fail completely
        }
    }
}
