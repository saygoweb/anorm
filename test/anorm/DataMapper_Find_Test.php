<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

require_once(__DIR__ . '/SomeTableModel.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\Model;

class DataMapperFindTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public function __construct()
    {
        parent::__construct();
        $this->pdo = new \PDO('mysql:host=localhost;dbname=anorm_test', 'travis', '');
    }
    
    public static function setUpBeforeClass()
    {
        $pdo = new \PDO('mysql:host=localhost;dbname=anorm_test', 'travis', '');
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $sql = file_get_contents(__DIR__ . '/TestSchema.sql');
        $pdo->query($sql);

        // Write some data for this test suite
        $model = new SomeTableModel($pdo);
        for ($i = 0; $i < 10; ++$i) {
            $model->name = "Name $i";
            $model->someId = null;
            $model->write();
        }
    }

    public function testFindOne_OK()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find('SomeTableModel', $this->pdo)
            ->where("`name`='Name 1'")
            ->one();
        $this->assertEquals('Name 1', $model->name);
    }

    public function testFindSome_OK()
    {
        $generator = DataMapper::find('SomeTableModel', $this->pdo)
            ->orderBy("name")
            ->limit(3)
            ->some();
        $i = 0;
        foreach ($generator as $model) {
            $this->assertEquals("Name $i", $model->name);
            ++$i;
        }
        $this->assertEquals(3, $i);
    }

    public function testFindOne_NotPresent_False()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find('SomeTableModel', $this->pdo)
            ->where("`name`='Bogus Name'")
            ->one();
        $this->assertEquals(false, $model);
    }

}