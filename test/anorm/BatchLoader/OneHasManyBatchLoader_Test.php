<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\BatchLoader\OneHasManyBatchLoader;
use PHPUnit\Framework\TestCase;

class OneHasManyBatchLoader_Test extends TestCase
{
    private $pdo;
    private $batchLoader;

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
        $this->batchLoader = new OneHasManyBatchLoader();
    }

    public function testBatchLoadWithEmptyModels()
    {
        $result = $this->batchLoader->batchLoad([], 'posts');
        $this->assertEquals([], $result);
    }

    public function testBatchLoadWithValidModels()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $result = $this->batchLoader->batchLoad($userArray, 'posts');
            $this->assertIsArray($result);

            // Results should be keyed by primary key values and contain arrays
            foreach ($result as $key => $value) {
                $this->assertIsInt($key);
                $this->assertIsArray($value);
                foreach ($value as $post) {
                    $this->assertInstanceOf(PostModel::class, $post);
                }
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testDistributeBatchResults()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            // Create mock batch results
            $batchResults = [];
            foreach ($userArray as $user) {
                $posts = [];
                for ($i = 1; $i <= 2; $i++) {
                    $post = new PostModel($this->pdo);
                    $post->id = $user->id * 10 + $i;
                    $post->title = "Test Post {$i} for User {$user->id}";
                    $post->user_id = $user->id;
                    $posts[] = $post;
                }
                $batchResults[$user->id] = $posts;
            }

            $this->batchLoader->distributeBatchResults($userArray, $batchResults, 'posts');

            // Verify that the relationship property is set
            foreach ($userArray as $user) {
                if (isset($batchResults[$user->id])) {
                    $this->assertIsArray($user->posts);
                    $this->assertCount(2, $user->posts);
                    foreach ($user->posts as $post) {
                        $this->assertInstanceOf(PostModel::class, $post);
                        $this->assertEquals($user->id, $post->user_id);
                    }
                }
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testDistributeBatchResultsWithEmptyResults()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            // Test with empty batch results
            $this->batchLoader->distributeBatchResults($userArray, [], 'posts');

            // All users should have empty arrays for posts
            foreach ($userArray as $user) {
                $this->assertIsArray($user->posts);
                $this->assertEmpty($user->posts);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testEstimateQueryCount()
    {
        $this->assertEquals(0, $this->batchLoader->estimateQueryCount(0));
        $this->assertEquals(1, $this->batchLoader->estimateQueryCount(5));
        $this->assertEquals(1, $this->batchLoader->estimateQueryCount(100));
    }

    public function testCanHandle()
    {
        // Create a mock relationship
        $relationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
        $relationship->method('getType')->willReturn('oneHasMany');

        $this->assertTrue($this->batchLoader->canHandle($relationship));

        // Test with wrong type - create a new mock
        $wrongRelationship = $this->createMock(\Anorm\Relationship\ManyHasOne::class);
        $wrongRelationship->method('getType')->willReturn('manyHasOne');
        $this->assertFalse($this->batchLoader->canHandle($wrongRelationship));
    }

    public function testGetMaxBatchSize()
    {
        $maxSize = $this->batchLoader->getMaxBatchSize();
        $this->assertIsInt($maxSize);
        $this->assertGreaterThan(0, $maxSize);
        $this->assertEquals(1000, $maxSize); // Expected value from implementation
    }

    public function testBatchLoadWithNullPrimaryKeys()
    {
        // Create models with null primary keys
        $mockModels = [];
        for ($i = 0; $i < 3; $i++) {
            $model = new UserModel($this->pdo);
            $model->id = null; // Null primary key
            $mockModels[] = $model;
        }

        $result = $this->batchLoader->batchLoad($mockModels, 'posts');
        $this->assertEquals([], $result);
    }

    public function testDistributeBatchResultsWithMissingRelationship()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            // Test with non-existent relationship
            $this->batchLoader->distributeBatchResults($userArray, [], 'nonexistent_relationship');

            // Should not throw an exception and should handle gracefully
            $this->assertTrue(true); // If we get here, the test passed
        } else {
            $this->markTestSkipped('No test data available');
        }
    }
}
