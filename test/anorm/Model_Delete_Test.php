<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;

class ModelDeleteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $pdo->query(file_get_contents(__DIR__ . '/TestSchema.sql'));
    }

    protected function setUp(): void
    {
        TestEnvironment::pdo()->query('TRUNCATE TABLE `some_table`');
    }

    public function testMapper_ReturnsTheSameInstanceAsTheUnderscoreProperty()
    {
        $model = new SomeTableModel();
        $this->assertInstanceOf(DataMapper::class, $model->mapper());
        $this->assertSame($model->_mapper, $model->mapper());
    }
}
