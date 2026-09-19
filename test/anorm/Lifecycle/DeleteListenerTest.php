<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\DataMapper;
use Anorm\Lifecycle\DeleteListenerInterface;
use Anorm\Model;
use Anorm\Test\Lifecycle\LifecycleModel;
use Anorm\Test\Lifecycle\RecordingDeleteListener;
use Anorm\Test\Lifecycle\RecordingListener;
use Anorm\Test\TestEnvironment;

class DeleteListenerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `lifecycle_model`');
        $pdo->query(file_get_contents(__DIR__ . '/LifecycleSchema.sql'));
    }

    protected function setUp(): void
    {
        TestEnvironment::pdo()->query('TRUNCATE TABLE `lifecycle_model`');
    }

    protected function tearDown(): void
    {
        DataMapper::setChangeListener(null);
        DataMapper::setDeleteListener(null);
    }

    /**
     * Register a listener that implements both interfaces on both slots, which is
     * how a consumer wanting write and delete events wires one class up.
     */
    private function listenAll(RecordingDeleteListener $listener)
    {
        DataMapper::setChangeListener($listener);
        DataMapper::setDeleteListener($listener);
    }

    private function makeRow($name)
    {
        $model = new LifecycleModel();
        $model->name = $name;
        $model->write();
        return $model;
    }

    public function testDelete_NoListener_StillDeletes()
    {
        $m = $this->makeRow('alice');
        $this->assertTrue($m->_mapper->delete($m->id));
    }

    public function testDelete_OnlyChangeListenerRegistered_DeleteSucceeds()
    {
        $m = $this->makeRow('alice');
        DataMapper::setChangeListener(new RecordingListener());

        $this->assertTrue($m->_mapper->delete($m->id));
    }

    public function testSetDeleteListener_AcceptsAListenerThatIsNotAChangeListener()
    {
        $listener = new class implements DeleteListenerInterface {
            public function onDelete(string $table, $id, ?Model $model): void
            {
            }
        };

        DataMapper::setDeleteListener($listener);
        $this->assertSame($listener, DataMapper::getDeleteListener());
        $this->assertNull(DataMapper::getChangeListener());

        DataMapper::setDeleteListener(null);
        $this->assertNull(DataMapper::getDeleteListener());
    }

    public function testDelete_WithoutModel_FiresWithTableIdAndNullModel()
    {
        $m = $this->makeRow('alice');
        $id = $m->id;

        $listener = new RecordingDeleteListener();
        DataMapper::setDeleteListener($listener);

        $m->_mapper->delete($id);

        $this->assertCount(1, $listener->deletes);
        $this->assertSame('lifecycle_model', $listener->deletes[0]['table']);
        $this->assertEquals($id, $listener->deletes[0]['id']);
        $this->assertNull($listener->deletes[0]['model']);
    }

    public function testDelete_WithModel_FiresWithThatModel()
    {
        $m = $this->makeRow('alice');

        $listener = new RecordingDeleteListener();
        DataMapper::setDeleteListener($listener);

        $m->_mapper->delete($m->id, $m);

        $this->assertCount(1, $listener->deletes);
        $this->assertSame($m, $listener->deletes[0]['model']);
    }

    public function testDelete_NoRowMatched_DoesNotFire()
    {
        $listener = new RecordingDeleteListener();
        DataMapper::setDeleteListener($listener);

        $m = new LifecycleModel();
        $this->assertFalse($m->_mapper->delete(999999));
        $this->assertCount(0, $listener->deletes);
    }

    public function testDelete_WithModel_ClearsSnapshot()
    {
        $this->listenAll(new RecordingDeleteListener());

        $m = $this->makeRow('alice');
        $this->assertIsArray($m->_lastSnapshot);

        $m->_mapper->delete($m->id, $m);
        $this->assertNull($m->_lastSnapshot);
    }

    public function testDelete_ListenerSeesSnapshotBeforeItIsCleared()
    {
        $listener = new class implements DeleteListenerInterface {
            /** @var array|null */
            public $snapshotAtCallTime = null;
            public function onDelete(string $table, $id, ?Model $model): void
            {
                $this->snapshotAtCallTime = $model === null ? null : $model->_lastSnapshot;
            }
        };
        DataMapper::setDeleteListener($listener);

        $m = $this->makeRow('alice');
        // Assigned rather than read back, so the assertion does not depend on
        // which columns captureSnapshot() happens to include.
        $m->_lastSnapshot = ['name' => 'alice'];

        $m->_mapper->delete($m->id, $m);

        $this->assertSame(['name' => 'alice'], $listener->snapshotAtCallTime);
        $this->assertNull($m->_lastSnapshot);
    }

    public function testDelete_ListenerThrows_DeleteStillReportsSuccess()
    {
        $throwing = new class implements DeleteListenerInterface {
            public function onDelete(string $table, $id, ?Model $model): void
            {
                throw new \RuntimeException('boom');
            }
        };
        DataMapper::setDeleteListener($throwing);

        $m = $this->makeRow('alice');

        $tmp = tempnam(sys_get_temp_dir(), 'anorm_err_');
        $prev = ini_set('error_log', $tmp);
        try {
            $result = $m->_mapper->delete($m->id);
        } finally {
            ini_set('error_log', $prev);
        }

        $this->assertTrue($result);
        $this->assertStringContainsString('boom', file_get_contents($tmp));
        unlink($tmp);
    }

    public function testDelete_NestedDelete_DoesNotReinvokeListener()
    {
        $listener = new class implements DeleteListenerInterface {
            public $callCount = 0;
            /** @var int|null */
            public $nestedId = null;
            public function onDelete(string $table, $id, ?Model $model): void
            {
                $this->callCount++;
                if ($this->callCount === 1 && $this->nestedId !== null) {
                    $other = new LifecycleModel();
                    $other->_mapper->delete($this->nestedId);
                }
            }
        };

        $a = $this->makeRow('alice');
        $b = $this->makeRow('bob');
        $listener->nestedId = $b->id;
        DataMapper::setDeleteListener($listener);

        $a->_mapper->delete($a->id);

        $this->assertSame(1, $listener->callCount);
        $rows = TestEnvironment::pdo()
            ->query('SELECT name FROM `lifecycle_model`')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([], $rows);
    }

    public function testDelete_AfterListenerThrew_NextDeleteStillFires()
    {
        $listener = new class implements DeleteListenerInterface {
            public $callCount = 0;
            public function onDelete(string $table, $id, ?Model $model): void
            {
                $this->callCount++;
                throw new \RuntimeException('boom');
            }
        };
        DataMapper::setDeleteListener($listener);

        $a = $this->makeRow('alice');
        $b = $this->makeRow('bob');

        $tmp = tempnam(sys_get_temp_dir(), 'anorm_err_');
        $prev = ini_set('error_log', $tmp);
        try {
            $a->_mapper->delete($a->id);
            $b->_mapper->delete($b->id);
        } finally {
            ini_set('error_log', $prev);
        }

        $this->assertSame(2, $listener->callCount);
        unlink($tmp);
    }
}
