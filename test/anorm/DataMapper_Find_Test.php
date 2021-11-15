<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\Test\SomeTableAggregateModel;
use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;

class DataMapperFindTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public function __construct()
    {
        parent::__construct();
        $this->pdo = TestEnvironment::pdo();
    }
    
    public static function setUpBeforeClass()
    {
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $sql = file_get_contents(__DIR__ . '/TestSchema.sql');
        $pdo->query($sql);

        // Write some data for this test suite
        $model = new SomeTableModel($pdo);
        for ($i = 0; $i < 10; ++$i) {
            $model->name = "Name $i";
            $model->someId = null;
            $model->category = floor($i / 4);
            $model->write();
        }
    }

    // public function testFindOne_OK()
    // {
    //     /** @var SomeTableModel */
    //     $model = DataMapper::find(SomeTableModel::class, $this->pdo)
    //         ->where("`name`='Name 1'")
    //         ->one();
    //     $this->assertEquals('Name 1', $model->name);
    // }

    public function testFindOne_OK()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->where("`name`=:name", [':name' => 'Name 1'])
            ->one();
        $this->assertEquals('name 1', $model->name);
    }

    public function testFindOneOrThrow_OK()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find(SomeTableModel::class, $this->pdo)
        ->where("`name`=:name", [':name' => 'Name 1'])
        ->oneOrThrow();
        $this->assertEquals('name 1', $model->name);
    }

    public function testFindSome_OK()
    {
        $generator = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->orderBy("name")
            ->limit(2, 1)
            ->some();
        $i = 1;
        $j = 0;
        foreach ($generator as $model) {
            $this->assertEquals("name $i", $model->name);
            ++$i;
            ++$j;
        }
        $this->assertEquals(2, $j);
    }

    public function testFindOne_NotPresent_False()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->where("`name`=:name", [':name' => 'Bogus Name'])
            ->one();
        $this->assertEquals(false, $model);
    }

    /** 
     * @expectedException \Exception
     * @expectedExceptionMessage QueryBuilder: Expected one not found from 'SELECT * FROM `some_table` WHERE `name`=:name LIMIT 0, 1'
     */
    public function testFindOneOrThrow_NotPresent_Throws()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find(SomeTableModel::class, $this->pdo)
            ->where("`name`=:name", [':name' => 'Bogus Name'])
            ->oneOrThrow();
    }

    public function testFindSome_GroupBy_OK()
    {
        /** @var SomeTableAggregateModel[] */
        $generator = DataMapper::find(SomeTableAggregateModel::class, $this->pdo)
            ->select("COUNT(s.category) AS category_count,MIN(s.some_id) AS id_min,MAX(s.some_id) AS id_max")
            ->from("some_table AS s")
            ->groupBy("category")
            ->some();
        $result = [];
        foreach ($generator as $model) {
            $result[] = [
                'count' => $model->categoryCount,
                'min' => $model->idMin,
                'max' => $model->idMax,
            ];
        }
        $this->assertEquals(3, count($result));
        $this->assertEquals([
            'count' => '4',
            'min' => '1',
            'max' => '4',
        ], $result[0]);
        $this->assertEquals([
            'count' => '4',
            'min' => '5',
            'max' => '8',
        ], $result[1]);
        $this->assertEquals([
            'count' => '2',
            'min' => '9',
            'max' => '10',
        ], $result[2]);
    }

}
