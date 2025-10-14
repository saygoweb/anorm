<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\BatchLoader\ManyHasOneBatchLoader;
use PHPUnit\Framework\TestCase;

class ManyHasOneBatchLoader_Test extends TestCase
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
        $this->batchLoader = new ManyHasOneBatchLoader();
    }

    public function testBatchLoadWithEmptyModels()
    {
        $result = $this->batchLoader->batchLoad([], 'user');
        $this->assertEquals([], $result);
    }

    public function testBatchLoadWithValidModels()
    {
        // Get posts to test belongsTo relationship
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        if (count($postArray) > 0) {
            $result = $this->batchLoader->batchLoad($postArray, 'user');
            $this->assertIsArray($result);

            // Results should be keyed by primary key values
            foreach ($result as $key => $value) {
                $this->assertIsInt($key);
                $this->assertInstanceOf(UserModel::class, $value);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testDistributeBatchResults()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        if (count($postArray) > 0) {
            // Create mock batch results
            $batchResults = [];
            foreach ($postArray as $post) {
                if ($post->user_id) {
                    $user = new UserModel($this->pdo);
                    $user->id = $post->user_id;
                    $user->name = "Test User {$post->user_id}";
                    $batchResults[$post->user_id] = $user;
                }
            }

            $this->batchLoader->distributeBatchResults($postArray, $batchResults, 'user');

            // Verify that the relationship property is set
            foreach ($postArray as $post) {
                if ($post->user_id && isset($batchResults[$post->user_id])) {
                    $this->assertInstanceOf(UserModel::class, $post->user);
                    $this->assertEquals($post->user_id, $post->user->id);
                }
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
        $relationship = $this->createMock(\Anorm\Relationship\ManyHasOne::class);
        $relationship->method('getType')->willReturn('manyHasOne');

        $this->assertTrue($this->batchLoader->canHandle($relationship));

        // Test with wrong type - create a new mock
        $wrongRelationship = $this->createMock(\Anorm\Relationship\OneHasMany::class);
        $wrongRelationship->method('getType')->willReturn('oneHasMany');
        $this->assertFalse($this->batchLoader->canHandle($wrongRelationship));
    }

    public function testGetMaxBatchSize()
    {
        $maxSize = $this->batchLoader->getMaxBatchSize();
        $this->assertIsInt($maxSize);
        $this->assertGreaterThan(0, $maxSize);
        $this->assertEquals(1000, $maxSize); // Expected value from implementation
    }

    public function testGetBatchStatistics()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        if (count($postArray) > 0) {
            $batchResults = [];
            $stats = $this->batchLoader->getBatchStatistics($postArray, $batchResults);

            $this->assertIsArray($stats);
            $this->assertArrayHasKey('source_models', $stats);
            $this->assertArrayHasKey('unique_foreign_keys', $stats);
            $this->assertArrayHasKey('loaded_models', $stats);
            $this->assertArrayHasKey('cache_hit_ratio', $stats);

            $this->assertEquals(count($postArray), $stats['source_models']);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testBatchLoadWithNullForeignKeys()
    {
        // Create models with null foreign keys
        $mockModels = [];
        for ($i = 0; $i < 3; $i++) {
            $model = new PostModel($this->pdo);
            $model->user_id = null; // Null foreign key
            $mockModels[] = $model;
        }

        $result = $this->batchLoader->batchLoad($mockModels, 'user');
        $this->assertEquals([], $result);
    }

    public function testDistributeBatchResultsWithNullForeignKey()
    {
        // Create a model with null foreign key
        $post = new PostModel($this->pdo);
        $post->user_id = null;

        $this->batchLoader->distributeBatchResults([$post], [], 'user');

        // Should set the relationship to null
        $this->assertNull($post->user);
    }

    public function testDistributeBatchResultsWithMissingRelationship()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        if (count($postArray) > 0) {
            // Test with non-existent relationship
            $this->batchLoader->distributeBatchResults($postArray, [], 'nonexistent_relationship');

            // Should not throw an exception and should handle gracefully
            $this->assertTrue(true); // If we get here, the test passed
        } else {
            $this->markTestSkipped('No test data available');
        }
    }
}
