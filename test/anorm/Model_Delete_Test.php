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

    private function makeRow($name)
    {
        $model = new SomeTableModel();
        $model->name = $name;
        $model->dtc = '2026-09-20';
        $model->write();
        return $model;
    }

    public function testMapper_ReturnsTheSameInstanceAsTheUnderscoreProperty()
    {
        $model = new SomeTableModel();
        $this->assertInstanceOf(DataMapper::class, $model->mapper());
        $this->assertSame($model->_mapper, $model->mapper());
    }

    public function testDelete_KeySet_RemovesRowAndReturnsTrue()
    {
        $model = $this->makeRow('alice');
        $this->assertTrue($model->delete());
        $this->assertEquals(0, $model->countRows());
    }

    public function testDelete_ByAssignedKeyWithoutReading_RemovesRow()
    {
        $written = $this->makeRow('alice');

        $model = new SomeTableModel();
        $model->someId = $written->someId;
        $this->assertTrue($model->delete());
        $this->assertEquals(0, $model->countRows());
    }

    public function testDelete_UnknownId_ReturnsFalse()
    {
        $this->makeRow('alice');

        $model = new SomeTableModel();
        $model->someId = 999999;
        $this->assertFalse($model->delete());
        $this->assertEquals(1, $model->countRows());
    }

    public function testDelete_KeyNotSet_Throws()
    {
        $model = new SomeTableModel();
        $this->expectException(\Exception::class);
        $model->delete();
    }

    public function testDelete_KeyNotSet_MessageOk()
    {
        $model = new SomeTableModel();
        try {
            $model->delete();
            $this->fail('expected an exception');
        } catch (\Exception $e) {
            $this->assertEquals(
                "SomeTable cannot be deleted: primary key 'someId' is not set",
                $e->getMessage()
            );
        }
    }

    public function testDelete_EmptyStringKey_Throws()
    {
        $model = new SomeTableModel();
        $model->someId = '';
        $this->expectException(\Exception::class);
        $model->delete();
    }

    public function testReadOrThrow_MessageUnchanged()
    {
        $model = new SomeTableModel();
        try {
            $model->readOrThrow(1);
            $this->fail('expected an exception');
        } catch (\Exception $e) {
            $this->assertEquals("SomeTable id '1' not found", $e->getMessage());
        }
    }

    public function testDeleteOrThrow_RowExists_ReturnsTrue()
    {
        $model = $this->makeRow('alice');
        $this->assertTrue($model->deleteOrThrow());
        $this->assertEquals(0, $model->countRows());
    }

    public function testDeleteOrThrow_UnknownId_Throws()
    {
        $model = new SomeTableModel();
        $model->someId = 999999;
        $this->expectException(\Exception::class);
        $model->deleteOrThrow();
    }

    public function testDeleteOrThrow_UnknownId_MessageOk()
    {
        $model = new SomeTableModel();
        $model->someId = 999999;
        try {
            $model->deleteOrThrow();
            $this->fail('expected an exception');
        } catch (\Exception $e) {
            $this->assertEquals("SomeTable id '999999' not deleted", $e->getMessage());
        }
    }

    public function testDeleteOrThrow_KeyNotSet_ThrowsTheUnsetKeyMessage()
    {
        $model = new SomeTableModel();
        try {
            $model->deleteOrThrow();
            $this->fail('expected an exception');
        } catch (\Exception $e) {
            $this->assertEquals(
                "SomeTable cannot be deleted: primary key 'someId' is not set",
                $e->getMessage()
            );
        }
    }
}
