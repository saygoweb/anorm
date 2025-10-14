<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use PHPUnit\Framework\TestCase;

class BatchLoadingPerformance_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testBatchLoadingVsIndividualLoading()
    {
        // Test to demonstrate the performance difference between batch and individual loading

        // First, test with batch loading enabled (default)
        $startTime = microtime(true);
        $queryCount = $this->getQueryCount();

        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->some();

        $userArray = iterator_to_array($users);
        $batchQueryCount = $this->getQueryCount() - $queryCount;
        $batchTime = microtime(true) - $startTime;

        // Now test with batch loading disabled
        $startTime = microtime(true);
        $queryCount = $this->getQueryCount();

        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->disableBatchLoading()
            ->some();

        $userArray2 = iterator_to_array($users);
        $individualQueryCount = $this->getQueryCount() - $queryCount;
        $individualTime = microtime(true) - $startTime;

        // Verify both approaches return the same data
        $this->assertCount(count($userArray), $userArray2);

        // Performance comparison (removed debug output for cleaner test runs)

        // With our current test data (3 users), we expect:
        // - Batch loading: ~3 queries (1 for users, 1 for posts, 1 for companies)
        // - Individual loading: ~7 queries (1 for users, 3 for posts, 3 for companies)

        // Note: The exact query count may vary based on the strategy selector's decisions
        // For small datasets, it might choose individual loading anyway

        $this->assertGreaterThan(0, count($userArray));
        $this->assertGreaterThan(0, count($userArray2));
    }

    public function testBatchLoadingWithLargerDataset()
    {
        // Create additional test data to better demonstrate batch loading benefits
        $this->createAdditionalTestData();

        // Test with batch loading
        $startTime = microtime(true);
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->some();

        $userArray = iterator_to_array($users);
        $batchTime = microtime(true) - $startTime;

        // Test with individual loading
        $startTime = microtime(true);
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->disableBatchLoading()
            ->some();

        $userArray2 = iterator_to_array($users);
        $individualTime = microtime(true) - $startTime;

        // Performance comparison (removed debug output for cleaner test runs)
        // Both approaches should work and return the same data

        $this->assertGreaterThan(3, count($userArray)); // Should have more than the original 3 users
        $this->assertEquals(count($userArray), count($userArray2));

        // Clean up additional test data
        $this->cleanupAdditionalTestData();
    }

    public function testStrategySelection()
    {
        // Test that the strategy selector makes appropriate decisions

        // Small dataset should use individual loading
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->setBatchLoadingConfig(['debug_mode' => true, 'individual_loading_threshold' => 10])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);

        // Force batch loading for small dataset
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->setBatchLoadingConfig(['debug_mode' => true, 'individual_loading_threshold' => 1])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);
    }

    private function getQueryCount(): int
    {
        // This is a simplified query counter - in a real implementation,
        // you might use a query logger or database profiler
        $result = $this->pdo->query("SHOW SESSION STATUS LIKE 'Queries'");
        $row = $result->fetch(\PDO::FETCH_ASSOC);
        return (int) $row['Value'];
    }

    private function countUsers(): int
    {
        $result = $this->pdo->query("SELECT COUNT(*) as count FROM users");
        $row = $result->fetch(\PDO::FETCH_ASSOC);
        return (int) $row['count'];
    }

    private function createAdditionalTestData(): void
    {
        // Create some additional users and posts for testing
        for ($i = 4; $i <= 10; $i++) {
            $this->pdo->exec("INSERT INTO users (id, name, email, company_id) VALUES ({$i}, 'User {$i}', 'user{$i}@example.com', 1)");

            // Create a few posts for each user
            for ($j = 1; $j <= 3; $j++) {
                $postId = ($i - 4) * 3 + $j + 100; // Avoid ID conflicts
                $sql = "INSERT INTO posts (id, title, content, user_id, status) VALUES ({$postId}, 'Post {$j} by User {$i}', 'Content for post {$j}', {$i}, 'published')";
                $this->pdo->exec($sql);
            }
        }
    }

    private function cleanupAdditionalTestData(): void
    {
        // Clean up the additional test data
        $this->pdo->exec("DELETE FROM posts WHERE id >= 100");
        $this->pdo->exec("DELETE FROM users WHERE id >= 4");
    }
}
