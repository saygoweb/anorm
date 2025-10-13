<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\MangoQuery;
use Anorm\SqlCondition;
use Anorm\MangoQueryParser;
use Anorm\DataMapper;
use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;

class MangoQuery_Test extends TestCase
{
    public function testMangoQuery_BasicConstruction()
    {
        $query = new MangoQuery([
            'selector' => ['name' => 'John'],
            'fields' => ['id', 'name'],
            'limit' => 10
        ]);

        $this->assertEquals(['name' => 'John'], $query->getSelector());
        $this->assertEquals(['id', 'name'], $query->getFields());
        $this->assertEquals(10, $query->getLimit());
        $this->assertTrue($query->hasConditions());
        $this->assertTrue($query->hasFields());
        $this->assertFalse($query->hasSort());
    }

    public function testMangoQuery_EmptyQuery()
    {
        $query = new MangoQuery([]);

        $this->assertEquals([], $query->getSelector());
        $this->assertNull($query->getFields());
        $this->assertNull($query->getLimit());
        $this->assertFalse($query->hasConditions());
        $this->assertFalse($query->hasFields());
    }

    public function testMangoQuery_InvalidLimit_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mango query limit must be a non-negative integer');

        new MangoQuery(['limit' => -1]);
    }

    public function testMangoQuery_InvalidFields_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mango query fields must be an array');

        new MangoQuery(['fields' => 'invalid']);
    }

    public function testSqlCondition_BasicUsage()
    {
        $condition = new SqlCondition('name = :name', [':name' => 'John']);

        $this->assertEquals('name = :name', $condition->getSql());
        $this->assertEquals([':name' => 'John'], $condition->getBindings());
        $this->assertFalse($condition->isEmpty());
    }

    public function testSqlCondition_Combine()
    {
        $condition1 = new SqlCondition('name = :name', [':name' => 'John']);
        $condition2 = new SqlCondition('age > :age', [':age' => 21]);

        $combined = $condition1->combine($condition2, 'AND');

        $this->assertEquals('(name = :name) AND (age > :age)', $combined->getSql());
        $this->assertEquals([':name' => 'John', ':age' => 21], $combined->getBindings());
    }

    public function testSqlCondition_Empty()
    {
        $empty = SqlCondition::empty();
        $this->assertTrue($empty->isEmpty());
        $this->assertEquals('1=1', $empty->getSql());
    }

    public function testMangoQueryParser_SimpleEquality()
    {
        $pdo = TestEnvironment::pdo();
        $model = new SomeTableModel($pdo);
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector(['name' => 'John']);

        $this->assertStringContainsString('=', $condition->getSql());
        $this->assertNotEmpty($condition->getBindings());
    }

    public function testMangoQueryParser_ComparisonOperators()
    {
        $pdo = TestEnvironment::pdo();
        $model = new SomeTableModel($pdo);
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Test $gt operator
        $condition = $parser->parseSelector(['age' => ['$gt' => 21]]);
        $this->assertStringContainsString('>', $condition->getSql());

        // Test operator without $ prefix (GraphQL compatibility)
        $condition = $parser->parseSelector(['age' => ['gte' => 18]]);
        $this->assertStringContainsString('>=', $condition->getSql());
    }

    public function testMangoQueryParser_InOperator()
    {
        $pdo = TestEnvironment::pdo();
        $model = new SomeTableModel($pdo);
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector(['status' => ['$in' => ['active', 'pending']]]);

        $this->assertStringContainsString('IN', $condition->getSql());
        $this->assertCount(2, $condition->getBindings());
    }

    public function testMangoQueryParser_AndOperator()
    {
        $pdo = TestEnvironment::pdo();
        $model = new SomeTableModel($pdo);
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            '$and' => [
                ['name' => 'John'],
                ['age' => ['$gt' => 21]]
            ]
        ]);

        $this->assertStringContainsString('AND', $condition->getSql());
        $this->assertCount(2, $condition->getBindings());
    }

    public function testMangoQueryParser_OrOperator()
    {
        $pdo = TestEnvironment::pdo();
        $model = new SomeTableModel($pdo);
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            '$or' => [
                ['name' => 'John'],
                ['name' => 'Jane']
            ]
        ]);

        $this->assertStringContainsString('OR', $condition->getSql());
        $this->assertCount(2, $condition->getBindings());
    }

    public function testMangoQueryParser_Fields()
    {
        $pdo = TestEnvironment::pdo();
        $model = new SomeTableModel($pdo);
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $fieldsClause = $parser->parseFields(['name', 'someId']);

        // Should map property names to column names
        $this->assertStringContainsString('`name`', $fieldsClause);
        $this->assertStringContainsString('`some_id`', $fieldsClause);
    }

    public function testMangoQueryParser_Sort()
    {
        $pdo = TestEnvironment::pdo();
        $model = new SomeTableModel($pdo);
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Test simple string format
        $sortClause = $parser->parseSort(['name']);
        $this->assertStringContainsString('`name` ASC', $sortClause);

        // Test object format
        $sortClause = $parser->parseSort([['name' => 'desc'], ['someId' => 'asc']]);
        $this->assertStringContainsString('`name` DESC', $sortClause);
        $this->assertStringContainsString('`some_id` ASC', $sortClause);
    }
}
