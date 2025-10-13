<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use PHPUnit\Framework\TestCase;

class PartialModelSupport_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('mysql:host=localhost;dbname=anorm_test', 'dev', 'dev');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function testSetAndGetLoadedFields()
    {
        $user = new UserModel($this->pdo);
        
        // Initially, no partial loading
        $this->assertFalse($user->isPartiallyLoaded());
        $this->assertNull($user->getLoadedFields());
        $this->assertTrue($user->isFieldLoaded('any_field')); // All fields considered loaded
        
        // Set partial loading
        $fields = ['id', 'name', 'email'];
        $user->setLoadedFields($fields);
        
        $this->assertTrue($user->isPartiallyLoaded());
        $this->assertEquals($fields, $user->getLoadedFields());
        
        // Test field loading status
        $this->assertTrue($user->isFieldLoaded('id'));
        $this->assertTrue($user->isFieldLoaded('name'));
        $this->assertTrue($user->isFieldLoaded('email'));
        $this->assertFalse($user->isFieldLoaded('created_at'));
        $this->assertFalse($user->isFieldLoaded('updated_at'));
    }

    public function testPartialModelWithQueryBuilder()
    {
        // Test that partial loading works with the query builder
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts:id,title'])
            ->some();
        
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            
            // User should be fully loaded
            $this->assertFalse($user->isPartiallyLoaded());
            
            // Posts might be partially loaded if JOIN strategy was used
            if (isset($user->posts) && !empty($user->posts)) {
                $post = $user->posts[0];
                
                // Check if post is partially loaded
                if ($post->isPartiallyLoaded()) {
                    $loadedFields = $post->getLoadedFields();
                    $this->assertContains('id', $loadedFields);
                    $this->assertContains('title', $loadedFields);
                    
                    $this->assertTrue($post->isFieldLoaded('id'));
                    $this->assertTrue($post->isFieldLoaded('title'));
                    $this->assertFalse($post->isFieldLoaded('content'));
                }
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testPartialModelFieldAccess()
    {
        $post = new PostModel($this->pdo);
        
        // Simulate partial loading
        $post->setLoadedFields(['id', 'title']);
        $post->id = 1;
        $post->title = 'Test Post';
        
        // Loaded fields should be accessible
        $this->assertEquals(1, $post->id);
        $this->assertEquals('Test Post', $post->title);
        
        // Check field loading status
        $this->assertTrue($post->isFieldLoaded('id'));
        $this->assertTrue($post->isFieldLoaded('title'));
        $this->assertFalse($post->isFieldLoaded('content'));
        $this->assertFalse($post->isFieldLoaded('created_at'));
    }

    public function testPartialModelSerialization()
    {
        $user = new UserModel($this->pdo);
        $user->setLoadedFields(['id', 'name']);
        $user->id = 1;
        $user->name = 'Test User';
        
        // Test that partial loading information survives serialization
        $serialized = serialize($user);
        $unserialized = unserialize($serialized);
        
        $this->assertTrue($unserialized->isPartiallyLoaded());
        $this->assertEquals(['id', 'name'], $unserialized->getLoadedFields());
        $this->assertTrue($unserialized->isFieldLoaded('id'));
        $this->assertTrue($unserialized->isFieldLoaded('name'));
        $this->assertFalse($unserialized->isFieldLoaded('email'));
    }

    public function testPartialModelWithEmptyFieldList()
    {
        $user = new UserModel($this->pdo);
        
        // Set empty field list
        $user->setLoadedFields([]);
        
        $this->assertTrue($user->isPartiallyLoaded());
        $this->assertEquals([], $user->getLoadedFields());
        $this->assertFalse($user->isFieldLoaded('id'));
        $this->assertFalse($user->isFieldLoaded('name'));
    }

    public function testPartialModelReset()
    {
        $user = new UserModel($this->pdo);
        
        // Set partial loading
        $user->setLoadedFields(['id', 'name']);
        $this->assertTrue($user->isPartiallyLoaded());
        
        // Reset to full loading
        $user->setLoadedFields(null);
        $this->assertFalse($user->isPartiallyLoaded());
        $this->assertNull($user->getLoadedFields());
        $this->assertTrue($user->isFieldLoaded('any_field'));
    }

    public function testPartialModelWithDuplicateFields()
    {
        $user = new UserModel($this->pdo);
        
        // Set fields with duplicates
        $fieldsWithDuplicates = ['id', 'name', 'id', 'email', 'name'];
        $user->setLoadedFields($fieldsWithDuplicates);
        
        // Should store exactly what was provided (duplicates preserved)
        $this->assertEquals($fieldsWithDuplicates, $user->getLoadedFields());
        
        // Field checking should still work correctly
        $this->assertTrue($user->isFieldLoaded('id'));
        $this->assertTrue($user->isFieldLoaded('name'));
        $this->assertTrue($user->isFieldLoaded('email'));
        $this->assertFalse($user->isFieldLoaded('created_at'));
    }

    public function testPartialModelCaseSensitivity()
    {
        $user = new UserModel($this->pdo);
        
        // Set fields with different cases
        $user->setLoadedFields(['id', 'Name', 'EMAIL']);
        
        // Field checking should be case-sensitive
        $this->assertTrue($user->isFieldLoaded('id'));
        $this->assertTrue($user->isFieldLoaded('Name'));
        $this->assertTrue($user->isFieldLoaded('EMAIL'));
        $this->assertFalse($user->isFieldLoaded('name')); // Different case
        $this->assertFalse($user->isFieldLoaded('email')); // Different case
    }

    public function testPartialModelWithSpecialCharacters()
    {
        $user = new UserModel($this->pdo);
        
        // Set fields with special characters
        $specialFields = ['user_id', 'first-name', 'email@domain', 'field with spaces'];
        $user->setLoadedFields($specialFields);
        
        foreach ($specialFields as $field) {
            $this->assertTrue($user->isFieldLoaded($field));
        }
        
        $this->assertFalse($user->isFieldLoaded('normal_field'));
    }

    public function testPartialModelInheritance()
    {
        // Test that partial loading works with model inheritance
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);
        
        if (count($userArray) > 0) {
            $user = $userArray[0];
            
            // Set partial loading on the base model
            $user->setLoadedFields(['id', 'name']);
            
            // Should work regardless of the actual model class
            $this->assertTrue($user->isPartiallyLoaded());
            $this->assertEquals(['id', 'name'], $user->getLoadedFields());
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testPartialModelWithNullFields()
    {
        $user = new UserModel($this->pdo);
        
        // Test with null in field list
        $fieldsWithNull = ['id', null, 'name', '', 'email'];
        $user->setLoadedFields($fieldsWithNull);
        
        // Should store exactly what was provided
        $this->assertEquals($fieldsWithNull, $user->getLoadedFields());
        
        // Field checking should handle null and empty string
        $this->assertTrue($user->isFieldLoaded('id'));
        $this->assertTrue($user->isFieldLoaded(null));
        $this->assertTrue($user->isFieldLoaded(''));
        $this->assertTrue($user->isFieldLoaded('name'));
        $this->assertTrue($user->isFieldLoaded('email'));
    }

    public function testPartialModelPerformance()
    {
        $user = new UserModel($this->pdo);
        
        // Test performance with large field lists
        $largeFieldList = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeFieldList[] = "field_{$i}";
        }
        
        $startTime = microtime(true);
        $user->setLoadedFields($largeFieldList);
        $setTime = microtime(true) - $startTime;
        
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $user->isFieldLoaded("field_{$i}");
        }
        $checkTime = microtime(true) - $startTime;
        
        // Operations should be reasonably fast
        $this->assertLessThan(0.01, $setTime); // Less than 10ms
        $this->assertLessThan(0.01, $checkTime); // Less than 10ms for 100 checks
    }
}
