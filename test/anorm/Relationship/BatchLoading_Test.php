<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use PHPUnit\Framework\TestCase;

class BatchLoading_Test extends TestCase
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
    }

    public function testBatchLoadingWithQueryBuilder()
    {
        // Test that batch loading works with QueryBuilder
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);

        // Verify that relationships are loaded
        $john = $userArray[0];
        $this->assertEquals('John Doe', $john->name);
        $this->assertIsArray($john->posts);
        $this->assertInstanceOf(CompanyModel::class, $john->company);
        $this->assertEquals('Tech Corp', $john->company->name);
    }

    public function testBatchLoadingCanBeDisabled()
    {
        // Test that batch loading can be disabled
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->disableBatchLoading()
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);

        // Verify that relationships are still loaded (using individual loading)
        $john = $userArray[0];
        $this->assertEquals('John Doe', $john->name);
        $this->assertIsArray($john->posts);
        $this->assertInstanceOf(CompanyModel::class, $john->company);
    }

    public function testBatchLoadingConfiguration()
    {
        // Test that batch loading configuration can be set
        $queryBuilder = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->setBatchLoadingConfig(['debug_mode' => true]);

        $this->assertTrue($queryBuilder->isBatchLoadingEnabled());
        
        // Test that we can still get results
        $users = $queryBuilder->some();
        $userArray = iterator_to_array($users);
        $this->assertGreaterThan(0, count($userArray));
    }

    public function testBatchLoadingWithNoRelationships()
    {
        // Test that batch loading works when no relationships are specified
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);

        // Verify that no relationships are loaded
        $john = $userArray[0];
        $this->assertEquals('John Doe', $john->name);
        $this->assertNull($john->posts);
        $this->assertNull($john->company);
    }

    public function testBatchLoadingWithSingleRelationship()
    {
        // Test batch loading with just one relationship
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);

        // Verify that only posts are loaded
        $john = $userArray[0];
        $this->assertEquals('John Doe', $john->name);
        $this->assertIsArray($john->posts);
        $this->assertCount(2, $john->posts); // John has 2 posts
        $this->assertNull($john->company); // Company should not be loaded
    }

    public function testBatchLoadingPerformance()
    {
        // This is a basic performance test to ensure batch loading doesn't break
        $startTime = microtime(true);
        
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->some();

        $userArray = iterator_to_array($users);
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Basic assertions
        $this->assertCount(3, $userArray);
        $this->assertLessThan(5.0, $executionTime); // Should complete in under 5 seconds
        
        // Verify data integrity
        foreach ($userArray as $user) {
            $this->assertNotNull($user->name);
            $this->assertIsArray($user->posts);
            if ($user->company_id) {
                $this->assertInstanceOf(CompanyModel::class, $user->company);
            }
        }
    }

    public function testBatchLoadingWithManyToOneRelationship()
    {
        // Test batch loading for belongsTo relationships
        $posts = DataMapper::find(PostModel::class, $this->pdo)
            ->with(['user'])
            ->some();

        $postArray = iterator_to_array($posts);
        $this->assertGreaterThan(0, count($postArray));

        // Verify that user relationships are loaded
        foreach ($postArray as $post) {
            $this->assertInstanceOf(UserModel::class, $post->user);
            $this->assertNotNull($post->user->name);
        }
    }
}
