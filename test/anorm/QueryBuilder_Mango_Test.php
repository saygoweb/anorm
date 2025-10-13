<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\QueryBuilder;
use Anorm\DataMapper;
use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;

class QueryBuilder_Mango_Test extends TestCase
{
    private $pdo;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect(); // Ensure connection is established
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $sql = file_get_contents(__DIR__ . '/TestSchema.sql');
        $pdo->query($sql);
    }

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();

        // Clean up any existing data
        $this->pdo->exec('DELETE FROM some_table');

        // Create test data
        $model1 = new SomeTableModel();
        $model1->name = 'Alice';
        $model1->dtc = '2023-01-01';
        $model1->write();

        $model2 = new SomeTableModel();
        $model2->name = 'Bob';
        $model2->dtc = '2023-02-01';
        $model2->write();

        $model3 = new SomeTableModel();
        $model3->name = 'Charlie';
        $model3->dtc = '2023-03-01';
        $model3->write();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->pdo->exec('DELETE FROM some_table');
    }

    public function testQueryBuilder_BasicMangoQuery()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => ['name' => 'Alice']
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('alice', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithFields()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => ['name' => 'Bob'],
                'fields' => ['name', 'someId']
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('bob', $model->name);
            $this->assertNotNull($model->someId);
            // dtc should not be loaded due to field restriction
            $this->assertNull($model->dtc);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithSort()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [],
                'sort' => [['name' => 'desc']]
            ])
            ->some();

        $expectedNames = ['charlie', 'bob', 'alice'];
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $index++;
        }

        $this->assertEquals(3, $index);
    }

    public function testQueryBuilder_MangoQueryWithLimit()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [],
                'sort' => [['name' => 'asc']],
                'limit' => 2
            ])
            ->some();

        $expectedNames = ['alice', 'bob'];
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $index++;
        }

        $this->assertEquals(2, $index);
    }

    public function testQueryBuilder_MangoQueryWithSkip()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [],
                'sort' => [['name' => 'asc']],
                'limit' => 2,
                'skip' => 1
            ])
            ->some();

        $expectedNames = ['bob', 'charlie'];
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $index++;
        }

        $this->assertEquals(2, $index);
    }

    public function testQueryBuilder_MangoQueryWithComparisonOperators()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'dtc' => ['$gte' => '2023-02-01']
                ],
                'sort' => [['name' => 'asc']]
            ])
            ->some();

        $expectedNames = ['bob', 'charlie'];
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $index++;
        }

        $this->assertEquals(2, $index);
    }

    public function testQueryBuilder_MangoQueryWithInOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'name' => ['$in' => ['Alice', 'Charlie']]
                ],
                'sort' => [['name' => 'asc']]
            ])
            ->some();

        $expectedNames = ['alice', 'charlie'];
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $index++;
        }

        $this->assertEquals(2, $index);
    }

    public function testQueryBuilder_MangoQueryWithAndOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    '$and' => [
                        ['name' => ['$in' => ['Alice', 'Bob']]],
                        ['dtc' => ['$gte' => '2023-01-15']]
                    ]
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('bob', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithOrOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    '$or' => [
                        ['name' => 'Alice'],
                        ['name' => 'Charlie']
                    ]
                ],
                'sort' => [['name' => 'asc']]
            ])
            ->some();

        $expectedNames = ['alice', 'charlie'];
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $index++;
        }

        $this->assertEquals(2, $index);
    }

    public function testQueryBuilder_MangoAlias()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->mango([
                'selector' => ['name' => 'Alice']
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('alice', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_OperatorsWithoutDollarPrefix()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'name' => ['in' => ['Alice', 'Bob']],
                    'dtc' => ['gte' => '2023-01-15']
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('bob', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_BackwardCompatibility()
    {
        // Ensure existing QueryBuilder functionality still works
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->where("`name` = :name", [':name' => 'Alice'])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('alice', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    // Phase 2 Advanced Operators Integration Tests

    public function testQueryBuilder_MangoQueryWithRegexOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'name' => ['$regex' => '^[Aa].*']  // Names starting with A or a
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('alice', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithBeginsWithOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'name' => ['$beginsWith' => 'B']  // Names starting with B
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('bob', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithNorOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    '$nor' => [
                        ['name' => 'Alice'],
                        ['name' => 'Bob']
                    ]
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('charlie', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithPhase2OperatorsWithoutDollarPrefix()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'name' => ['beginswith' => 'C']  // Names starting with C (without $ prefix)
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('charlie', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithNullValues()
    {
        // Add a record with null dtc
        $model = new SomeTableModel();
        $model->name = 'David';
        $model->dtc = null;
        $model->write();

        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'dtc' => null  // Find records with null dtc
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('david', $model->name);
            $this->assertNull($model->dtc);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithExistsOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'dtc' => ['$exists' => true]  // Find records where dtc is not null
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertNotNull($model->dtc);
            $count++;
        }

        $this->assertEquals(3, $count); // Alice, Bob, Charlie all have dtc values
    }

    public function testQueryBuilder_MangoQueryWithNotExistsOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'category' => ['$exists' => false]  // Find records where category is null
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertNull($model->category);
            $count++;
        }

        $this->assertGreaterThan(0, $count); // Should find records with null category
    }

    public function testQueryBuilder_MangoQueryWithNotEqualOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'name' => ['$ne' => 'Alice']
                ],
                'sort' => [['name' => 'asc']]
            ])
            ->some();

        $expectedNames = ['bob', 'charlie'];
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $index++;
        }

        $this->assertEquals(2, $index);
    }

    public function testQueryBuilder_MangoQueryWithNotInOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    'name' => ['$nin' => ['Alice', 'Bob']]
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('charlie', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithComplexAndOr()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    '$or' => [
                        [
                            '$and' => [
                                ['name' => 'Alice'],
                                ['dtc' => ['$gte' => '2023-01-01']]
                            ]
                        ],
                        ['name' => 'Charlie']
                    ]
                ],
                'sort' => [['name' => 'asc']]
            ])
            ->some();

        $expectedNames = ['alice', 'charlie'];
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $index++;
        }

        $this->assertEquals(2, $index);
    }

    public function testQueryBuilder_MangoQueryWithNotOperator()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [
                    '$not' => [
                        'name' => ['$in' => ['Alice', 'Bob']]
                    ]
                ]
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('charlie', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryEmptySelector()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => [],  // Empty selector should return all records
                'limit' => 2
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertNotNull($model->name);
            $count++;
        }

        $this->assertEquals(2, $count);
    }

    public function testQueryBuilder_MangoQueryWithUseIndex()
    {
        // Test that use_index doesn't break the query (even though it's not implemented)
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => ['name' => 'Alice'],
                'use_index' => 'name_index'  // This should be ignored for now
            ])
            ->some();

        $count = 0;
        foreach ($generator as $model) {
            $this->assertEquals('alice', $model->name);
            $count++;
        }

        $this->assertEquals(1, $count);
    }

    public function testQueryBuilder_MangoQueryWithAllProperties()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->fromMango([
                'selector' => ['dtc' => ['$gte' => '2023-01-01']],
                'fields' => ['name', 'dtc'],
                'sort' => [['dtc' => 'desc']],
                'limit' => 2,
                'skip' => 1,
                'use_index' => 'dtc_index'
            ])
            ->some();

        $expectedNames = ['bob', 'alice']; // Charlie (2023-03-01), Bob (2023-02-01), skip Charlie, get Bob and Alice
        $index = 0;
        foreach ($generator as $model) {
            $this->assertEquals($expectedNames[$index], $model->name);
            $this->assertNotNull($model->dtc);
            $this->assertNull($model->category); // Should be null due to field restriction
            $index++;
        }

        $this->assertEquals(2, $index);
    }
}
