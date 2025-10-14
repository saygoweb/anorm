<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\OneHasMany;
use Anorm\Relationship\ManyHasOne;
use Anorm\Relationship\ManyHasMany;
use PHPUnit\Framework\TestCase;

class RelationshipBatchMethods_Test extends TestCase
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

    public function testOneHasManyBatchMethods()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');

            if ($relationship instanceof OneHasMany) {
                // Test batch loading
                $batchResults = $relationship->batchLoad($userArray, $this->pdo);
                $this->assertIsArray($batchResults);

                // Test distribution
                $relationship->distributeBatchResults($userArray, $batchResults);

                // Test data size estimation
                $dataSize = $relationship->estimateDataSize(count($userArray), null);
                $this->assertIsInt($dataSize);
                $this->assertGreaterThan(0, $dataSize);

                // Test with field selection
                $dataSizeWithFields = $relationship->estimateDataSize(count($userArray), ['id', 'title']);
                $this->assertIsInt($dataSizeWithFields);

                // Test cardinality
                $this->assertEquals('one-to-many', $relationship->getCardinality());
            } else {
                $this->markTestSkipped('Posts relationship not found or not OneHasMany');
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testManyHasOneBatchMethods()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        if (count($postArray) > 0) {
            $post = $postArray[0];
            $relationshipManager = $post->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('user');

            if ($relationship instanceof ManyHasOne) {
                // Test batch loading
                $batchResults = $relationship->batchLoad($postArray, $this->pdo);
                $this->assertIsArray($batchResults);

                // Test distribution
                $relationship->distributeBatchResults($postArray, $batchResults);

                // Test data size estimation
                $dataSize = $relationship->estimateDataSize(count($postArray), null);
                $this->assertIsInt($dataSize);
                $this->assertGreaterThan(0, $dataSize);

                // Test with field selection
                $dataSizeWithFields = $relationship->estimateDataSize(count($postArray), ['id', 'name']);
                $this->assertIsInt($dataSizeWithFields);

                // Test cardinality
                $this->assertEquals('many-to-one', $relationship->getCardinality());
            } else {
                $this->markTestSkipped('User relationship not found or not ManyHasOne');
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testManyHasManyBatchMethods()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();

            // Try to find a many-to-many relationship
            $allRelationships = $relationshipManager->getAllRelationships();
            $manyToManyRelationship = null;

            foreach ($allRelationships as $name => $rel) {
                if ($rel instanceof ManyHasMany) {
                    $manyToManyRelationship = $rel;
                    break;
                }
            }

            if ($manyToManyRelationship) {
                // Test batch loading
                $batchResults = $manyToManyRelationship->batchLoad($userArray, $this->pdo);
                $this->assertIsArray($batchResults);

                // Test distribution
                $manyToManyRelationship->distributeBatchResults($userArray, $batchResults);

                // Test data size estimation
                $dataSize = $manyToManyRelationship->estimateDataSize(count($userArray), null);
                $this->assertIsInt($dataSize);
                $this->assertGreaterThan(0, $dataSize);

                // Test with field selection
                $dataSizeWithFields = $manyToManyRelationship->estimateDataSize(count($userArray), ['id', 'name']);
                $this->assertIsInt($dataSizeWithFields);

                // Test cardinality
                $this->assertEquals('many-to-many', $manyToManyRelationship->getCardinality());
            } else {
                $this->markTestSkipped('No ManyHasMany relationship found');
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testEstimateDataSizeWithEmptyFieldSelection()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');

            if ($relationship) {
                // Test with empty field selection
                $dataSize = $relationship->estimateDataSize(count($userArray), []);
                $this->assertIsInt($dataSize);
                $this->assertGreaterThan(0, $dataSize);
            } else {
                $this->markTestSkipped('Posts relationship not found');
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testBatchLoadingWithZeroModels()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');

            if ($relationship) {
                // Test with empty array
                $batchResults = $relationship->batchLoad([], $this->pdo);
                $this->assertIsArray($batchResults);
                $this->assertEmpty($batchResults);

                // Test data size estimation with zero models
                $dataSize = $relationship->estimateDataSize(0, null);
                $this->assertEquals(0, $dataSize);
            } else {
                $this->markTestSkipped('Posts relationship not found');
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
            $user = $userArray[0];
            $relationshipManager = $user->getRelationshipManager();
            $relationship = $relationshipManager->getRelationship('posts');

            if ($relationship) {
                // Test distribution with empty results
                $relationship->distributeBatchResults($userArray, []);

                // Should not throw an exception
                $this->assertTrue(true);
            } else {
                $this->markTestSkipped('Posts relationship not found');
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }
}
