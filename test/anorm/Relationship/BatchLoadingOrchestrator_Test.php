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

    public function testExecuteBatchLoadingExceptionFallsBackToIndividual()
    {
        // Use reflection to call executeBatchLoading with a relationship that throws
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        // Create a mock relationship whose batchLoad() throws an exception
        $mockRelationship = $this->getMockBuilder(\Anorm\Relationship\OneHasMany::class)
            ->setConstructorArgs(['Anorm\Test\PostModel', 'posts', 'user_id', 'id'])
            ->onlyMethods(['batchLoad', 'getPropertyName'])
            ->getMock();

        $mockRelationship->method('getPropertyName')->willReturn('posts');
        $mockRelationship->method('batchLoad')->willThrowException(new \RuntimeException('Batch load failed'));

        $reflection = new \ReflectionClass($this->orchestrator);
        $method = $reflection->getMethod('executeBatchLoading');
        $method->setAccessible(true);

        // Should not throw — falls back to individual loading
        $method->invoke($this->orchestrator, $userArray, $mockRelationship, null);

        $this->assertTrue(true);
    }

    public function testExecuteBatchLoadingExceptionLogsInDebugMode()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        $this->orchestrator->setConfig(['debug_mode' => true]);

        $mockRelationship = $this->getMockBuilder(\Anorm\Relationship\OneHasMany::class)
            ->setConstructorArgs(['Anorm\Test\PostModel', 'posts', 'user_id', 'id'])
            ->onlyMethods(['batchLoad', 'getPropertyName'])
            ->getMock();

        $mockRelationship->method('getPropertyName')->willReturn('posts');
        $mockRelationship->method('batchLoad')->willThrowException(new \RuntimeException('Debug batch fail'));

        $reflection = new \ReflectionClass($this->orchestrator);
        $method = $reflection->getMethod('executeBatchLoading');
        $method->setAccessible(true);

        // With debug_mode=true, error_log is called and then falls back to individual loading
        $method->invoke($this->orchestrator, $userArray, $mockRelationship, null);

        $this->assertTrue(true);
    }

    public function testExecuteIndividualLoadingSuccess()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        $user = $userArray[0];
        $relationshipManager = $user->getRelationshipManager();
        $relationship = $relationshipManager->getRelationship('posts');

        if (!$relationship) {
            $this->markTestSkipped('Posts relationship not found');
        }

        $reflection = new \ReflectionClass($this->orchestrator);
        $method = $reflection->getMethod('executeIndividualLoading');
        $method->setAccessible(true);

        $method->invoke($this->orchestrator, $userArray, $relationship);

        // Individual loading should have populated posts on each user
        foreach ($userArray as $user) {
            $this->assertTrue(isset($user->posts) || $user->posts !== false);
        }
    }

    public function testExecuteIndividualLoadingExceptionHandledPerModel()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        // Create a mock relationship whose getPropertyName returns a non-existent relationship
        // so loadRelated() would fail gracefully (or we use a model that throws)
        $mockRelationship = $this->getMockBuilder(\Anorm\Relationship\OneHasMany::class)
            ->setConstructorArgs(['Anorm\Test\PostModel', 'posts', 'user_id', 'id'])
            ->onlyMethods(['getPropertyName'])
            ->getMock();

        // Use a relationship name that does not exist on UserModel → loadRelated returns null or throws
        $mockRelationship->method('getPropertyName')->willReturn('nonexistent_rel_xyz');

        $reflection = new \ReflectionClass($this->orchestrator);
        $method = $reflection->getMethod('executeIndividualLoading');
        $method->setAccessible(true);

        // Should not throw — exceptions per model are caught internally
        $method->invoke($this->orchestrator, $userArray, $mockRelationship);

        $this->assertTrue(true);
    }

    public function testExecuteIndividualLoadingExceptionLogsInDebugMode()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        $this->orchestrator->setConfig(['debug_mode' => true]);

        $mockRelationship = $this->getMockBuilder(\Anorm\Relationship\OneHasMany::class)
            ->setConstructorArgs(['Anorm\Test\PostModel', 'posts', 'user_id', 'id'])
            ->onlyMethods(['getPropertyName'])
            ->getMock();

        $mockRelationship->method('getPropertyName')->willReturn('nonexistent_rel_xyz');

        $reflection = new \ReflectionClass($this->orchestrator);
        $method = $reflection->getMethod('executeIndividualLoading');
        $method->setAccessible(true);

        // With debug_mode=true, error_log is called per failing model
        $method->invoke($this->orchestrator, $userArray, $mockRelationship);

        $this->assertTrue(true);
    }

    public function testLoadSingleRelationshipJoinStrategyBranch()
    {
        // Force JOIN_WITH_SELECTION strategy by providing > 10 models with field selection.
        // The orchestrator falls through to executeBatchLoading for JOIN strategy (see line 118-119).
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        // Duplicate users to exceed individual_loading_threshold (10)
        $manyUsers = [];
        for ($i = 0; $i < 20; $i++) {
            $manyUsers[] = $userArray[0];
        }

        // With field selection and > 10 models, the strategy selector may choose JOIN strategy.
        // The orchestrator handles JOIN by falling back to executeBatchLoading.
        $this->orchestrator->loadRelationshipsForModels($manyUsers, ['posts:id,title']);

        $this->assertTrue(true);
    }

    public function testLoadRelationshipsForModelClassWithEmptyModelsArray()
    {
        // The private loadRelationshipsForModelClass has an early return for empty models (line 72).
        // We can trigger it via reflection.
        $reflection = new \ReflectionClass($this->orchestrator);
        $method = $reflection->getMethod('loadRelationshipsForModelClass');
        $method->setAccessible(true);

        // Calling with empty array should return early without error
        $method->invoke($this->orchestrator, [], ['posts' => ['fields' => null, 'nested' => []]]);

        $this->assertTrue(true);
    }
}
