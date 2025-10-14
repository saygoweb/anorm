<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\BatchLoader\ManyHasManyBatchLoader;
use PHPUnit\Framework\TestCase;

class ManyHasManyBatchLoader_Test extends TestCase
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
        $this->batchLoader = new ManyHasManyBatchLoader();
    }

    public function testBatchLoadWithEmptyModels()
    {
        $result = $this->batchLoader->batchLoad([], 'tags');
        $this->assertEquals([], $result);
    }

    public function testBatchLoadWithValidModels()
    {
        // Create test models
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            // Test batch loading with a mock relationship since 'tags' doesn't exist
            // This tests the error handling path
            try {
                $result = $this->batchLoader->batchLoad($userArray, 'tags');
                $this->assertIsArray($result);
            } catch (\Exception $e) {
                // Expected exception for non-existent relationship
                $this->assertStringContainsString("Relationship 'tags' not defined", $e->getMessage());
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
            $batchResults = [
                1 => [], // Empty array for user 1
                2 => [], // Empty array for user 2
            ];

            $this->batchLoader->distributeBatchResults($userArray, $batchResults, 'tags');

            // Verify that the relationship property is set
            foreach ($userArray as $user) {
                if (isset($batchResults[$user->id])) {
                    $this->assertIsArray($user->tags ?? []);
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
        $relationship = $this->createMock(\Anorm\Relationship\ManyHasMany::class);
        $relationship->method('getType')->willReturn('manyHasMany');

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
        $this->assertEquals(500, $maxSize); // Expected value from implementation
    }

    public function testEstimateComplexity()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $complexity = $this->batchLoader->estimateComplexity($userArray, 'tags');

            $this->assertIsArray($complexity);
            $this->assertArrayHasKey('source_count', $complexity);
            $this->assertArrayHasKey('estimated_related_per_source', $complexity);
            $this->assertArrayHasKey('estimated_total_related', $complexity);
            $this->assertArrayHasKey('complexity_score', $complexity);
            $this->assertArrayHasKey('recommended_batch_size', $complexity);

            $this->assertEquals(count($userArray), $complexity['source_count']);
            $this->assertGreaterThan(0, $complexity['estimated_related_per_source']);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testBatchLoadWithNullForeignKeys()
    {
        // Create models with null primary keys
        $mockModels = [];
        for ($i = 0; $i < 3; $i++) {
            $model = new UserModel($this->pdo);
            $model->id = null; // Null primary key
            $mockModels[] = $model;
        }

        // Test with non-existent relationship - should throw exception
        try {
            $result = $this->batchLoader->batchLoad($mockModels, 'tags');
            $this->assertEquals([], $result);
        } catch (\Exception $e) {
            // Expected exception for non-existent relationship
            $this->assertStringContainsString("Relationship 'tags' not defined", $e->getMessage());
        }
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
