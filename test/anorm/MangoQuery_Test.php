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
        $query = MangoQuery::fromArray([
            MangoQuery::MANGO_SELECTOR => ['name' => 'John'],
            MangoQuery::MANGO_FIELDS => ['id', 'name'],
            MangoQuery::MANGO_LIMIT => 10
        ]);

        $this->assertEquals(['name' => 'John'], $query->selector);
        $this->assertEquals(['id', 'name'], $query->fields);
        $this->assertEquals(10, $query->limit);
        $this->assertTrue($query->hasConditions());
        $this->assertTrue($query->hasFields());
        $this->assertFalse($query->hasSort());
    }

    public function testMangoQuery_EmptyQuery()
    {
        $query = MangoQuery::fromArray([]);

        $this->assertEquals([], $query->selector);
        $this->assertNull($query->fields);
        $this->assertNull($query->limit);
        $this->assertFalse($query->hasConditions());
        $this->assertFalse($query->hasFields());
    }

    public function testMangoQuery_InvalidLimit_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mango query limit must be a non-negative integer');

        MangoQuery::fromArray([MangoQuery::MANGO_LIMIT => -1]);
    }

    public function testMangoQuery_InvalidFields_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mango query fields must be an array');

        MangoQuery::fromArray([MangoQuery::MANGO_FIELDS => 'invalid']);
    }

    public function testMangoQuery_InvalidSkip_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mango query skip must be a non-negative integer');

        MangoQuery::fromArray([MangoQuery::MANGO_SKIP => -5]);
    }

    public function testMangoQuery_InvalidSort_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mango query sort must be an array');

        MangoQuery::fromArray([MangoQuery::MANGO_SORT => 'invalid']);
    }

    public function testMangoQuery_InvalidSelector_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mango query selector must be an array');

        MangoQuery::fromArray([MangoQuery::MANGO_SELECTOR => 'invalid']);
    }

    public function testMangoQuery_AllProperties()
    {
        $query = MangoQuery::fromArray([
            MangoQuery::MANGO_SELECTOR => ['name' => 'John'],
            MangoQuery::MANGO_FIELDS => ['id', 'name'],
            MangoQuery::MANGO_SORT => [['name' => 'asc']],
            MangoQuery::MANGO_LIMIT => 10,
            MangoQuery::MANGO_SKIP => 5,
            MangoQuery::MANGO_USE_INDEX => 'name_index'
        ]);

        $this->assertEquals(['name' => 'John'], $query->selector);
        $this->assertEquals(['id', 'name'], $query->fields);
        $this->assertEquals([['name' => 'asc']], $query->sort);
        $this->assertEquals(10, $query->limit);
        $this->assertEquals(5, $query->skip);
        $this->assertEquals('name_index', $query->useIndex);
        $this->assertTrue($query->hasConditions());
        $this->assertTrue($query->hasFields());
        $this->assertTrue($query->hasSort());
    }

    public function testMangoQuery_ConstantsValues()
    {
        // Test that the constants have the expected values
        $this->assertEquals('selector', MangoQuery::MANGO_SELECTOR);
        $this->assertEquals('fields', MangoQuery::MANGO_FIELDS);
        $this->assertEquals('sort', MangoQuery::MANGO_SORT);
        $this->assertEquals('limit', MangoQuery::MANGO_LIMIT);
        $this->assertEquals('skip', MangoQuery::MANGO_SKIP);
        $this->assertEquals('use_index', MangoQuery::MANGO_USE_INDEX);
    }

    public function testMangoQuery_FromArrayWithConstants()
    {
        // Test that fromArray works with constants
        $query = MangoQuery::fromArray([
            MangoQuery::MANGO_SELECTOR => ['name' => 'Jane'],
            MangoQuery::MANGO_LIMIT => 5
        ]);

        $this->assertEquals(['name' => 'Jane'], $query->selector);
        $this->assertEquals(5, $query->limit);
        $this->assertTrue($query->hasConditions());
        $this->assertFalse($query->hasFields());
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

    public function testSqlCondition_Never()
    {
        $never = SqlCondition::never();
        $this->assertFalse($never->isEmpty());
        $this->assertEquals('1=0', $never->getSql());
        $this->assertEquals([], $never->getBindings());
    }

    public function testSqlCondition_Wrap()
    {
        $condition = new SqlCondition('name = :name', [':name' => 'John']);
        $wrapped = $condition->wrap();

        $this->assertEquals('(name = :name)', $wrapped->getSql());
        $this->assertEquals([':name' => 'John'], $wrapped->getBindings());
    }

    public function testSqlCondition_CombineWithOr()
    {
        $condition1 = new SqlCondition('name = :name', [':name' => 'John']);
        $condition2 = new SqlCondition('age > :age', [':age' => 21]);

        $combined = $condition1->combine($condition2, 'OR');

        $this->assertEquals('(name = :name) OR (age > :age)', $combined->getSql());
        $this->assertEquals([':name' => 'John', ':age' => 21], $combined->getBindings());
    }

    public function testMangoQueryParser_SimpleEquality()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector(['name' => 'John']);

        $this->assertStringContainsString('=', $condition->getSql());
        $this->assertNotEmpty($condition->getBindings());
    }

    public function testMangoQueryParser_ComparisonOperators()
    {
        $model = new SomeTableModel();
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
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector(['status' => ['$in' => ['active', 'pending']]]);

        $this->assertStringContainsString('IN', $condition->getSql());
        $this->assertCount(2, $condition->getBindings());
    }

    public function testMangoQueryParser_AndOperator()
    {
        $model = new SomeTableModel();
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
        $model = new SomeTableModel();
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
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $fieldsClause = $parser->parseFields(['name', 'someId']);

        // Should map property names to column names
        $this->assertStringContainsString('`name`', $fieldsClause);
        $this->assertStringContainsString('`some_id`', $fieldsClause);
    }

    public function testMangoQueryParser_Sort()
    {
        $model = new SomeTableModel();
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

    // Phase 2 Advanced Operators Tests

    public function testMangoQueryParser_RegexOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            'name' => ['$regex' => '^John.*']
        ]);

        $this->assertStringContainsString('`name` REGEXP :mango_param_1', $condition->getSql());
        $this->assertEquals('^John.*', $condition->getBindings()[':mango_param_1']);
    }

    public function testMangoQueryParser_BeginsWithOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            'name' => ['$beginsWith' => 'John']
        ]);

        $this->assertStringContainsString('`name` LIKE :mango_param_1', $condition->getSql());
        $this->assertEquals('John%', $condition->getBindings()[':mango_param_1']);
    }

    public function testMangoQueryParser_AllOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            'tags' => ['$all' => ['php', 'mysql']]
        ]);

        $sql = $condition->getSql();
        $this->assertStringContainsString('JSON_CONTAINS(`tags`, :mango_param_1)', $sql);
        $this->assertStringContainsString('JSON_CONTAINS(`tags`, :mango_param_2)', $sql);
        $this->assertStringContainsString('AND', $sql);
        $this->assertEquals('"php"', $condition->getBindings()[':mango_param_1']);
        $this->assertEquals('"mysql"', $condition->getBindings()[':mango_param_2']);
    }

    public function testMangoQueryParser_SizeOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            'tags' => ['$size' => 3]
        ]);

        $this->assertStringContainsString('JSON_LENGTH(`tags`) = :mango_param_1', $condition->getSql());
        $this->assertEquals(3, $condition->getBindings()[':mango_param_1']);
    }

    public function testMangoQueryParser_NorOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            '$nor' => [
                ['name' => 'John'],
                ['age' => ['$gt' => 65]]
            ]
        ]);

        $sql = $condition->getSql();
        $this->assertStringContainsString('NOT (', $sql);
        $this->assertStringContainsString('`name` = :mango_param_1', $sql);
        $this->assertStringContainsString('OR', $sql);
        $this->assertEquals('John', $condition->getBindings()[':mango_param_1']);
    }

    public function testMangoQueryParser_ElemMatchOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            'items' => ['$elemMatch' => ['price' => 100]]
        ]);

        $sql = $condition->getSql();
        $this->assertStringContainsString('JSON_EXTRACT(`items`, :mango_param_1)', $sql);
        $this->assertEquals('$.*.price', $condition->getBindings()[':mango_param_1']);
        $this->assertEquals(100, $condition->getBindings()[':mango_param_2']);
    }

    public function testMangoQueryParser_Phase2OperatorsWithoutDollarPrefix()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([
            'name' => ['regex' => '^John.*'],
            'description' => ['beginswith' => 'Hello'],
            'tags' => ['size' => 2]
        ]);

        $sql = $condition->getSql();
        $this->assertStringContainsString('`name` REGEXP', $sql);
        $this->assertStringContainsString('`description` LIKE', $sql);
        $this->assertStringContainsString('JSON_LENGTH(`tags`)', $sql);
    }

    public function testMangoQueryParser_InvalidRegexValue()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$regex operator requires a string value');
        $parser->parseSelector(['name' => ['$regex' => 123]]);
    }

    public function testMangoQueryParser_InvalidBeginsWithValue()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$beginsWith operator requires a string value');
        $parser->parseSelector(['name' => ['$beginsWith' => 123]]);
    }

    public function testMangoQueryParser_InvalidAllValue()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$all operator requires an array value');
        $parser->parseSelector(['tags' => ['$all' => 'not-array']]);
    }

    public function testMangoQueryParser_InvalidSizeValue()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$size operator requires a numeric value');
        $parser->parseSelector(['tags' => ['$size' => 'not-numeric']]);
    }

    public function testMangoQueryParser_InvalidElemMatchValue()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$elemMatch operator requires an array/object value');
        $parser->parseSelector(['items' => ['$elemMatch' => 'not-array']]);
    }

    public function testMangoQueryParser_EmptySelector()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $condition = $parser->parseSelector([]);
        $this->assertTrue($condition->isEmpty());
        $this->assertEquals('1=1', $condition->getSql());
    }

    public function testMangoQueryParser_EmptyFields()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $fieldsClause = $parser->parseFields([]);
        $this->assertEquals('*', $fieldsClause);
    }

    public function testMangoQueryParser_EmptySort()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $sortClause = $parser->parseSort([]);
        $this->assertEquals('', $sortClause);
    }

    public function testMangoQueryParser_NullValueComparisons()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Test null equality
        $condition = $parser->parseSelector(['name' => null]);
        $this->assertStringContainsString('IS NULL', $condition->getSql());

        // Test null with $ne operator
        $condition = $parser->parseSelector(['name' => ['$ne' => null]]);
        $this->assertStringContainsString('IS NOT NULL', $condition->getSql());
    }

    public function testMangoQueryParser_EmptyInArray()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Empty $in array should return never condition
        $condition = $parser->parseSelector(['name' => ['$in' => []]]);
        $this->assertEquals('1=0', $condition->getSql());
    }

    public function testMangoQueryParser_EmptyNotInArray()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Empty $nin array should return empty condition (always true)
        $condition = $parser->parseSelector(['name' => ['$nin' => []]]);
        $this->assertTrue($condition->isEmpty());
    }

    public function testMangoQueryParser_ExistsOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Test $exists: true
        $condition = $parser->parseSelector(['name' => ['$exists' => true]]);
        $this->assertStringContainsString('IS NOT NULL', $condition->getSql());

        // Test $exists: false
        $condition = $parser->parseSelector(['name' => ['$exists' => false]]);
        $this->assertStringContainsString('IS NULL', $condition->getSql());
    }

    public function testMangoQueryParser_InvalidAndOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$and operator requires an array of conditions');
        $parser->parseSelector(['$and' => 'not-array']);
    }

    public function testMangoQueryParser_InvalidOrOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$or operator requires an array of conditions');
        $parser->parseSelector(['$or' => 'not-array']);
    }

    public function testMangoQueryParser_InvalidNotOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$not operator requires an array condition');
        $parser->parseSelector(['$not' => 'not-array']);
    }

    public function testMangoQueryParser_InvalidNorOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$nor operator requires an array of conditions');
        $parser->parseSelector(['$nor' => 'not-array']);
    }

    public function testMangoQueryParser_UnsupportedOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported operator: $unknown');
        $parser->parseSelector(['$unknown' => ['test']]);
    }

    public function testMangoQueryParser_UnsupportedFieldOperator()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported field operator: unknown');
        $parser->parseSelector(['name' => ['$unknown' => 'value']]);
    }

    public function testMangoQueryParser_InvalidInValue()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Non-array value for $in should return never condition
        $condition = $parser->parseSelector(['name' => ['$in' => 'not-array']]);
        $this->assertEquals('1=0', $condition->getSql());
    }

    public function testMangoQueryParser_InvalidNinValue()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Non-array value for $nin should return empty condition
        $condition = $parser->parseSelector(['name' => ['$nin' => 'not-array']]);
        $this->assertTrue($condition->isEmpty());
    }

    public function testMangoQueryParser_ComplexElemMatch()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Test complex elemMatch with multiple conditions
        $condition = $parser->parseSelector([
            'items' => ['$elemMatch' => ['price' => 100, 'category' => 'electronics']]
        ]);

        $sql = $condition->getSql();
        $this->assertStringContainsString('JSON_CONTAINS(`items`, :mango_param_1)', $sql);
    }

    public function testMangoQueryParser_SortStringFormat()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Test string format for sort
        $sortClause = $parser->parseSort(['name', 'someId']);
        $this->assertStringContainsString('`name` ASC', $sortClause);
        $this->assertStringContainsString('`some_id` ASC', $sortClause); // someId maps to some_id
    }

    public function testMangoQueryParser_FieldMapping()
    {
        $model = new SomeTableModel();
        $mapper = $model->_mapper;
        $parser = new MangoQueryParser($mapper);

        // Test field that doesn't exist in mapping
        $condition = $parser->parseSelector(['unmapped_field' => 'value']);
        $this->assertStringContainsString('`unmapped_field`', $condition->getSql());
    }
}
