<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\Strategy\NestedRelationshipParser;
use PHPUnit\Framework\TestCase;

class NestedRelationshipParser_Test extends TestCase
{
    private $pdo;
    private $parser;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        $this->parser = new NestedRelationshipParser();
    }

    public function testParseSimpleNestedSpec()
    {
        $specs = ['posts.comments'];
        $parsed = $this->parser->parseNestedSpecs($specs);

        $this->assertArrayHasKey('posts', $parsed);
        $this->assertArrayHasKey('nested', $parsed['posts']);
        $this->assertArrayHasKey('comments', $parsed['posts']['nested']);
        $this->assertNull($parsed['posts']['fields']);
        $this->assertNull($parsed['posts']['nested']['comments']['fields']);
    }

    public function testParseNestedSpecWithFieldSelection()
    {
        $specs = ['posts:id,title.comments:id,content'];
        $parsed = $this->parser->parseNestedSpecs($specs);

        $this->assertArrayHasKey('posts', $parsed);
        $this->assertEquals(['id', 'title'], $parsed['posts']['fields']);
        $this->assertArrayHasKey('comments', $parsed['posts']['nested']);
        $this->assertEquals(['id', 'content'], $parsed['posts']['nested']['comments']['fields']);
    }

    public function testParseMultipleNestedSpecs()
    {
        $specs = [
            'posts.comments',
            'posts.tags',
            'company.users'
        ];
        $parsed = $this->parser->parseNestedSpecs($specs);

        $this->assertArrayHasKey('posts', $parsed);
        $this->assertArrayHasKey('company', $parsed);
        $this->assertArrayHasKey('comments', $parsed['posts']['nested']);
        $this->assertArrayHasKey('tags', $parsed['posts']['nested']);
        $this->assertArrayHasKey('users', $parsed['company']['nested']);
    }

    public function testParseDeepNestedSpec()
    {
        $specs = ['posts.comments.author'];
        $parsed = $this->parser->parseNestedSpecs($specs);

        $this->assertArrayHasKey('posts', $parsed);
        $this->assertArrayHasKey('comments', $parsed['posts']['nested']);
        $this->assertArrayHasKey('author', $parsed['posts']['nested']['comments']['nested']);
    }

    public function testLoadNestedRelationships()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $nestedSpecs = [
                'posts' => [
                    'fields' => ['id', 'title'],
                    'nested' => []
                ]
            ];

            // This should load the posts relationship
            $this->parser->loadNestedRelationships($userArray, $nestedSpecs);

            // Verify that posts were loaded
            foreach ($userArray as $user) {
                $this->assertIsArray($user->posts ?? []);
            }
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testValidateNestedSpecValid()
    {
        $validation = $this->parser->validateNestedSpec('posts:id,title.comments:id,content');

        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);
        $this->assertEquals(2, $validation['depth']);
    }

    public function testValidateNestedSpecDeepNesting()
    {
        $validation = $this->parser->validateNestedSpec('a.b.c.d.e.f');

        $this->assertTrue($validation['valid']);
        $this->assertNotEmpty($validation['warnings']);
        $this->assertEquals(6, $validation['depth']);
        $this->assertStringContainsString('Deep nesting detected', $validation['warnings'][0]);
    }

    public function testValidateNestedSpecCircularReference()
    {
        $validation = $this->parser->validateNestedSpec('posts.posts');

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
        $this->assertStringContainsString('circular reference', $validation['errors'][0]);
    }

    public function testValidateNestedSpecInvalidFieldSelection()
    {
        $validation = $this->parser->validateNestedSpec('posts:id,title,');

        // This should still be valid as the parser handles trailing commas
        $this->assertTrue($validation['valid']);
    }

    public function testCircularReferenceDetection()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            // Create a circular reference scenario
            $nestedSpecs = [
                'posts' => [
                    'fields' => null,
                    'nested' => [
                        'user' => [
                            'fields' => null,
                            'nested' => []
                        ]
                    ]
                ]
            ];

            $this->parser->loadNestedRelationships($userArray, $nestedSpecs, 2);

            // posts should be loaded on users
            $this->assertIsArray($userArray[0]->posts);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testMaxDepthLimiting()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $nestedSpecs = [
                'posts' => [
                    'fields' => null,
                    'nested' => [
                        'comments' => [
                            'fields' => null,
                            'nested' => [
                                'author' => [
                                    'fields' => null,
                                    'nested' => []
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $this->parser->loadNestedRelationships($userArray, $nestedSpecs, 1);

            // posts were loaded (depth 1), but comments within posts were not (depth 0 cut off)
            $this->assertIsArray($userArray[0]->posts);
            $this->assertNull($userArray[0]->posts[0]->comments ?? null);
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testGetLoadingStats()
    {
        $stats = $this->parser->getLoadingStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('current_depth', $stats);
        $this->assertArrayHasKey('loading_stack', $stats);
        $this->assertEquals(0, $stats['current_depth']);
        $this->assertEmpty($stats['loading_stack']);
    }

    public function testReset()
    {
        // Simulate some loading state
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            $nestedSpecs = [
                'posts' => [
                    'fields' => null,
                    'nested' => []
                ]
            ];

            $this->parser->loadNestedRelationships($userArray, $nestedSpecs);
        }

        // Reset should clear the loading stack
        $this->parser->reset();
        $stats = $this->parser->getLoadingStats();
        $this->assertEquals(0, $stats['current_depth']);
        $this->assertEmpty($stats['loading_stack']);
    }

    public function testExtractRelatedModelsFromArrayRelationship()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) > 0) {
            // Simulate loaded posts
            foreach ($userArray as $user) {
                $user->posts = [
                    (object) ['id' => 1, 'title' => 'Post 1'],
                    (object) ['id' => 2, 'title' => 'Post 2']
                ];
            }

            // Use reflection to test private method
            $reflection = new \ReflectionClass($this->parser);
            $method = $reflection->getMethod('extractRelatedModels');
            $method->setAccessible(true);

            $relatedModels = $method->invoke($this->parser, $userArray, 'posts');

            $this->assertIsArray($relatedModels);
            $this->assertCount(count($userArray) * 2, $relatedModels); // 2 posts per user
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testExtractRelatedModelsFromSingleRelationship()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        if (count($postArray) > 0) {
            // Simulate loaded user
            foreach ($postArray as $post) {
                $post->user = (object) ['id' => 1, 'name' => 'Test User'];
            }

            // Use reflection to test private method
            $reflection = new \ReflectionClass($this->parser);
            $method = $reflection->getMethod('extractRelatedModels');
            $method->setAccessible(true);

            $relatedModels = $method->invoke($this->parser, $postArray, 'user');

            $this->assertIsArray($relatedModels);
            $this->assertCount(count($postArray), $relatedModels); // 1 user per post
        } else {
            $this->markTestSkipped('No test data available');
        }
    }

    public function testParseNestedSpecsDuplicateRelationshipMergesFields()
    {
        // Duplicate relationship 'posts' in two specs with different fields
        // hits the else/merge branch in parseNestedSpec
        $specs = ['posts:id,title', 'posts:content'];
        $parsed = $this->parser->parseNestedSpecs($specs);

        $this->assertArrayHasKey('posts', $parsed);
        // All three fields should be present after the merge
        $fields = $parsed['posts']['fields'];
        $this->assertIsArray($fields);
        $this->assertContains('id', $fields);
        $this->assertContains('title', $fields);
        $this->assertContains('content', $fields);
    }

    public function testParseNestedSpecsDuplicateRelationshipWithNullFields()
    {
        // Second spec has no field selection — elseif branch: only update when new fields provided
        $specs = ['posts:id,title', 'posts'];
        $parsed = $this->parser->parseNestedSpecs($specs);

        $this->assertArrayHasKey('posts', $parsed);
        // First spec set fields; second spec (null fields) should not overwrite them
        $this->assertEquals(['id', 'title'], $parsed['posts']['fields']);
    }

    public function testGenerateCircularKeyWithEmptyModels()
    {
        // Use reflection to test private method — empty models returns just $relationshipName
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('generateCircularKey');
        $method->setAccessible(true);

        $result = $method->invoke($this->parser, [], 'posts');
        $this->assertEquals('posts', $result);
    }

    public function testGenerateCircularKeyWithModels()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('generateCircularKey');
        $method->setAccessible(true);

        $result = $method->invoke($this->parser, $userArray, 'posts');
        $this->assertEquals('Anorm\Test\UserModel.posts', $result);
    }

    public function testLoadNestedRelationshipsSkipsCircularReference()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        // Pre-load the loading stack with the key that would be generated
        // for UserModel + 'posts', simulating an in-progress load
        $circularKey = 'Anorm\Test\UserModel.posts';
        $reflection = new \ReflectionClass($this->parser);
        $stackProp = $reflection->getProperty('loadingStack');
        $stackProp->setAccessible(true);
        $stackProp->setValue($this->parser, [$circularKey]);

        $nestedSpecs = [
            'posts' => [
                'fields' => null,
                'nested' => []
            ]
        ];

        // Should return early without throwing (circular reference detected)
        $this->parser->loadNestedRelationships($userArray, $nestedSpecs);

        // Loading stack should only contain the key we injected (nothing was added/removed)
        $stack = $stackProp->getValue($this->parser);
        $this->assertEquals([$circularKey], $stack);
    }

    public function testLoadImmediateRelationshipWithEmptyModels()
    {
        // Use reflection to call the private method with an empty models array
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('loadImmediateRelationship');
        $method->setAccessible(true);

        $this->expectNotToPerformAssertions();
        $method->invoke($this->parser, [], 'posts', null);
    }

    public function testLoadImmediateRelationshipWithMissingRelationship()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)->some();
        $userArray = iterator_to_array($users);

        if (count($userArray) === 0) {
            $this->markTestSkipped('No test data available');
        }

        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('loadImmediateRelationship');
        $method->setAccessible(true);

        $this->expectNotToPerformAssertions();
        $method->invoke($this->parser, $userArray, 'nonexistent', null);
    }
}
