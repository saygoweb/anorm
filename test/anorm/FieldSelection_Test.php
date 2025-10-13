<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\Strategy\FieldSelectionParser;
use PHPUnit\Framework\TestCase;

class FieldSelection_Test extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('mysql:host=localhost;dbname=anorm_test', 'dev', 'dev');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function testFieldSelectionParser()
    {
        $parser = new FieldSelectionParser();
        
        // Test simple relationship name
        $result = $parser->parseFieldSelection('posts');
        $this->assertEquals('posts', $result['relationship']);
        $this->assertNull($result['fields']);
        $this->assertTrue($result['all_fields']);
        
        // Test field selection syntax
        $result = $parser->parseFieldSelection('posts:id,title,created_at');
        $this->assertEquals('posts', $result['relationship']);
        $this->assertEquals(['id', 'title', 'created_at'], $result['fields']);
        $this->assertFalse($result['all_fields']);
        
        // Test wildcard
        $result = $parser->parseFieldSelection('posts:*');
        $this->assertEquals('posts', $result['relationship']);
        $this->assertNull($result['fields']);
        $this->assertTrue($result['all_fields']);
    }

    public function testMultipleFieldSelections()
    {
        $parser = new FieldSelectionParser();
        
        $specs = ['posts:id,title', 'company:name,address', 'comments'];
        $result = $parser->parseMultipleSelections($specs);
        
        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('company', $result);
        $this->assertArrayHasKey('comments', $result);
        
        $this->assertEquals(['id', 'title'], $result['posts']['fields']);
        $this->assertEquals(['name', 'address'], $result['company']['fields']);
        $this->assertNull($result['comments']['fields']);
    }

    public function testFieldSelectionWithQueryBuilder()
    {
        // Test that field selection syntax works with QueryBuilder
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts:id,title', 'company:name'])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);

        // Verify that relationships are loaded
        $john = $userArray[0];
        $this->assertEquals('John Doe', $john->name);
        $this->assertIsArray($john->posts);
        $this->assertInstanceOf(CompanyModel::class, $john->company);
        
        // Note: The actual field selection optimization would be implemented
        // in Phase 3 (JOIN with field selection). For now, this tests that
        // the syntax is accepted and relationships are loaded normally.
    }

    public function testInvalidFieldSelectionSyntax()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        DataMapper::find(UserModel::class, $this->pdo)
            ->with(['']) // Empty string should throw exception
            ->some();
    }

    public function testFieldSelectionNormalization()
    {
        $parser = new FieldSelectionParser();
        
        // Test normalization of whitespace
        $normalized = $parser->normalizeSpec('  posts : id , title , created_at  ');
        $this->assertEquals('posts:id,title,created_at', $normalized);
        
        $result = $parser->parseFieldSelection($normalized);
        $this->assertEquals(['id', 'title', 'created_at'], $result['fields']);
    }

    public function testSelectClauseGeneration()
    {
        $parser = new FieldSelectionParser();
        
        // Test SELECT clause generation for specific fields
        $selectClause = $parser->generateSelectClause(['id', 'title'], 'p', 'post');
        $this->assertEquals('`p`.`id` AS `post_id`, `p`.`title` AS `post_title`', $selectClause);
        
        // Test SELECT clause for all fields
        $selectClause = $parser->generateSelectClause(null, 'p', '');
        $this->assertEquals('`p`.*', $selectClause);
    }

    public function testPrefixedFieldExtraction()
    {
        $parser = new FieldSelectionParser();
        
        $row = [
            'user_id' => 1,
            'user_name' => 'John',
            'post_id' => 10,
            'post_title' => 'Test Post',
            'other_field' => 'value'
        ];
        
        $userFields = $parser->extractPrefixedFields($row, 'user');
        $this->assertEquals(['id' => 1, 'name' => 'John'], $userFields);
        
        $postFields = $parser->extractPrefixedFields($row, 'post');
        $this->assertEquals(['id' => 10, 'title' => 'Test Post'], $postFields);
    }

    public function testFieldValidation()
    {
        $parser = new FieldSelectionParser();
        
        $fields = ['id', 'name', 'email', '_internal', ''];
        $validation = $parser->validateFields($fields, 'UserModel');
        
        $this->assertContains('id', $validation['valid']);
        $this->assertContains('name', $validation['valid']);
        $this->assertContains('email', $validation['valid']);
        $this->assertContains('_internal', $validation['valid']); // Valid but with warning
        $this->assertContains('', $validation['invalid']); // Empty field name
        
        $this->assertContains("Field '_internal' starts with underscore - may be internal", $validation['warnings']);
    }

    public function testComplexFieldSelectionScenarios()
    {
        $parser = new FieldSelectionParser();
        
        // Test complex scenarios
        $specs = [
            'posts:id,title,status',
            'company:name',
            'comments:id,content',
            'tags:*'
        ];
        
        $parsed = $parser->parseMultipleSelections($specs);
        
        // Verify all relationships are parsed correctly
        $this->assertCount(4, $parsed);
        $this->assertEquals(['id', 'title', 'status'], $parsed['posts']['fields']);
        $this->assertEquals(['name'], $parsed['company']['fields']);
        $this->assertEquals(['id', 'content'], $parsed['comments']['fields']);
        $this->assertNull($parsed['tags']['fields']); // Wildcard means all fields
    }
}
