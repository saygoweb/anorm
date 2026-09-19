<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;

class DataMapperDeleteTest extends TestCase
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

    private function makeRow($name)
    {
        $model = new SomeTableModel();
        $model->name = $name;
        $model->dtc = '2026-09-20';
        $model->write();
        return $model;
    }

    public function testDelete_ExistingId_RemovesRowAndReturnsTrue()
    {
        $model = $this->makeRow('alice');
        $this->assertTrue($model->_mapper->delete($model->someId));
        $this->assertEquals(0, $model->countRows());
    }

    public function testDelete_UnknownId_ReturnsFalse()
    {
        $this->makeRow('alice');
        $model = new SomeTableModel();
        $this->assertFalse($model->_mapper->delete(999999));
        $this->assertEquals(1, $model->countRows());
    }

    public function testDelete_SqlInjectionInId_DeletesNothing()
    {
        $this->makeRow('alice');
        $this->makeRow('bob');

        // Interpolated, this becomes  WHERE some_id='x' OR '1'='1'  and truncates
        // the table. Bound, the whole payload is one value that matches no id.
        // The payload deliberately starts with a non-digit so MySQL's string ->
        // number coercion yields 0 rather than accidentally matching row 1.
        $model = new SomeTableModel();
        $result = $model->_mapper->delete("x' OR '1'='1");

        $this->assertFalse($result);
        $this->assertEquals(2, $model->countRows());
    }
}
