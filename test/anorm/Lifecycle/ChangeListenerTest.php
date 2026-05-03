<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\DataMapper;
use Anorm\Lifecycle\ChangeListenerInterface;
use Anorm\Test\Lifecycle\LifecycleModel;
use Anorm\Test\Lifecycle\RecordingListener;
use Anorm\Test\TestEnvironment;

class ChangeListenerTest extends TestCase
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
    }

    public function testSetAndGetListener_RoundTrips()
    {
        $this->assertNull(DataMapper::getChangeListener());

        $listener = new RecordingListener();
        DataMapper::setChangeListener($listener);
        $this->assertSame($listener, DataMapper::getChangeListener());

        DataMapper::setChangeListener(null);
        $this->assertNull(DataMapper::getChangeListener());
    }

    public function testFixture_BasicCrud_Works()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $m->write();
        $this->assertNotNull($m->id);

        $m2 = new LifecycleModel();
        $m2->read($m->id);
        $this->assertEquals('alice', $m2->name);
    }

    public function testLastSnapshotProperty_DefaultsToNull()
    {
        $m = new LifecycleModel();
        $this->assertNull($m->_lastSnapshot);
    }

    public function testLastSnapshotProperty_NotMappedAsColumn()
    {
        $m = new LifecycleModel();
        $this->assertArrayNotHasKey('_lastSnapshot', $m->_mapper->map);
    }

    public function testRead_WithoutListener_DoesNotCaptureSnapshot()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $m->write();
        $id = $m->id;

        $loaded = new LifecycleModel();
        $loaded->read($id);

        $this->assertNull($loaded->_lastSnapshot);
    }

    public function testRead_WithListener_CapturesSnapshot()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $m->write();
        $id = $m->id;

        DataMapper::setChangeListener(new RecordingListener());

        $loaded = new LifecycleModel();
        $loaded->read($id);

        $this->assertIsArray($loaded->_lastSnapshot);
        $this->assertSame('alice', $loaded->_lastSnapshot['name']);
        $this->assertSame('a@example.com', $loaded->_lastSnapshot['email']);
        $this->assertArrayNotHasKey('_lastSnapshot', $loaded->_lastSnapshot);
    }

    public function testDiff_NoChanges_ReturnsEmpty()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $snapshot = ['name' => 'alice', 'email' => 'a@example.com', 'dtu' => null, 'payload' => null];
        $this->assertSame([], $m->_mapper->diff($snapshot, $m));
    }

    public function testDiff_OneFieldChanged_ReturnsThatField()
    {
        $m = new LifecycleModel();
        $m->name = 'bob';
        $m->email = 'a@example.com';
        $snapshot = ['name' => 'alice', 'email' => 'a@example.com', 'dtu' => null, 'payload' => null];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertSame(['name' => ['from' => 'alice', 'to' => 'bob']], $diff);
    }

    public function testDiff_PrimaryKeyAlwaysExcluded()
    {
        $m = new LifecycleModel();
        $m->id = 99;
        $m->name = 'alice';
        $snapshot = ['id' => 1, 'name' => 'alice', 'email' => null, 'dtu' => null, 'payload' => null];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayNotHasKey('id', $diff);
    }

    public function testDiff_InfrastructurePropertiesExcluded()
    {
        $m = new LifecycleModel();
        $m->_mapper->infrastructureProperties = ['dtu'];
        $m->name = 'alice';
        $m->dtu = '2026-05-02 12:00:00';
        $snapshot = ['name' => 'alice', 'email' => null, 'dtu' => '2026-05-01 12:00:00', 'payload' => null];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertSame([], $diff);
    }

    public function testDiff_PartialLoad_OmitsUnloadedFields()
    {
        $m = new LifecycleModel();
        $m->setLoadedFields(['name']);
        $m->name = 'bob';
        $m->email = 'changed@example.com';
        $snapshot = ['name' => 'alice', 'email' => 'a@example.com', 'dtu' => null, 'payload' => null];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayHasKey('name', $diff);
        $this->assertArrayNotHasKey('email', $diff);
    }

    public function testDiff_ObjectEquality_ViaEquals()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->payload = new \Anorm\Test\Lifecycle\LifecycleMoney(100, 'USD');
        $snapshot = [
            'name' => 'alice', 'email' => null, 'dtu' => null,
            'payload' => new \Anorm\Test\Lifecycle\LifecycleMoney(100, 'USD'),
        ];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayNotHasKey('payload', $diff);
    }

    public function testDiff_ObjectEquality_ViaIsSame()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->payload = new \Anorm\Test\Lifecycle\LifecycleSameMoney(100, 'USD');
        $snapshot = [
            'name' => 'alice', 'email' => null, 'dtu' => null,
            'payload' => new \Anorm\Test\Lifecycle\LifecycleSameMoney(100, 'USD'),
        ];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayNotHasKey('payload', $diff);
    }

    public function testDiff_ObjectEquality_LooseCompareFallback()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->payload = new \Anorm\Test\Lifecycle\LifecycleBagMoney(100, 'USD');
        $snapshot = [
            'name' => 'alice', 'email' => null, 'dtu' => null,
            'payload' => new \Anorm\Test\Lifecycle\LifecycleBagMoney(100, 'USD'),
        ];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayNotHasKey('payload', $diff);
    }

    public function testDiff_ObjectEquality_LooseCompareDetectsDifference()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->payload = new \Anorm\Test\Lifecycle\LifecycleBagMoney(200, 'USD');
        $snapshot = [
            'name' => 'alice', 'email' => null, 'dtu' => null,
            'payload' => new \Anorm\Test\Lifecycle\LifecycleBagMoney(100, 'USD'),
        ];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayHasKey('payload', $diff);
    }

    public function testDiff_NullToValue_DetectsChange()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $snapshot = ['name' => 'alice', 'email' => null, 'dtu' => null, 'payload' => null];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertSame(['email' => ['from' => null, 'to' => 'a@example.com']], $diff);
    }

    public function testDiff_ValueToNull_DetectsChange()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = null;
        $snapshot = ['name' => 'alice', 'email' => 'a@example.com', 'dtu' => null, 'payload' => null];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertSame(['email' => ['from' => 'a@example.com', 'to' => null]], $diff);
    }

    public function testDiff_ArrayEquality_NoChange()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->payload = ['a', 'b', 'c'];
        $snapshot = [
            'name' => 'alice', 'email' => null, 'dtu' => null,
            'payload' => ['a', 'b', 'c'],
        ];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayNotHasKey('payload', $diff);
    }

    public function testDiff_ArrayEquality_DetectsDifference()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->payload = ['a', 'b', 'c'];
        $snapshot = [
            'name' => 'alice', 'email' => null, 'dtu' => null,
            'payload' => ['a', 'b'],
        ];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayHasKey('payload', $diff);
    }

    public function testDiff_ObjectEquality_DifferentClasses()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->payload = new \Anorm\Test\Lifecycle\LifecycleMoney(100, 'USD');
        $snapshot = [
            'name' => 'alice', 'email' => null, 'dtu' => null,
            'payload' => new \stdClass(),
        ];
        $diff = $m->_mapper->diff($snapshot, $m);
        $this->assertArrayHasKey('payload', $diff);
    }

    public function testWrite_NoListener_BehavesUnchanged()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->write();
        $this->assertNotNull($m->id);
        $this->assertNull($m->_lastSnapshot);
    }

    public function testWrite_InsertPath_FiresWithIsInsertTrueAndEmptyDiff()
    {
        $listener = new RecordingListener();
        DataMapper::setChangeListener($listener);

        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $m->write();

        $this->assertCount(1, $listener->calls);
        $this->assertTrue($listener->calls[0]['isInsert']);
        $this->assertSame([], $listener->calls[0]['diff']);
        /** @var LifecycleModel $capturedModel */
        $capturedModel = $listener->calls[0]['model'];
        $this->assertNotNull($capturedModel->id);
    }

    public function testWrite_UpdatePathNoChange_FiresWithEmptyDiff()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $m->write();
        $id = $m->id;

        $listener = new RecordingListener();
        DataMapper::setChangeListener($listener);

        $loaded = new LifecycleModel();
        $loaded->read($id);
        $loaded->write();

        $this->assertCount(1, $listener->calls);
        $this->assertFalse($listener->calls[0]['isInsert']);
        $this->assertSame([], $listener->calls[0]['diff']);
    }

    public function testWrite_UpdatePathOneField_FiresWithDiff()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $m->write();
        $id = $m->id;

        $listener = new RecordingListener();
        DataMapper::setChangeListener($listener);

        $loaded = new LifecycleModel();
        $loaded->read($id);
        $loaded->name = 'bob';
        $loaded->write();

        $this->assertCount(1, $listener->calls);
        $this->assertFalse($listener->calls[0]['isInsert']);
        $this->assertSame(
            ['name' => ['from' => 'alice', 'to' => 'bob']],
            $listener->calls[0]['diff']
        );
    }

    public function testWrite_SnapshotRefreshedAfterWrite()
    {
        DataMapper::setChangeListener(new RecordingListener());

        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->write();

        $this->assertIsArray($m->_lastSnapshot);
        $this->assertSame('alice', $m->_lastSnapshot['name']);

        $m->name = 'bob';
        $m->write();
        $this->assertSame('bob', $m->_lastSnapshot['name']);
    }

    public function testWrite_ListenerThrowsRuntimeException_WriteSucceeds()
    {
        $throwing = new class implements ChangeListenerInterface {
            public function onWrite(\Anorm\Model $model, array $diff, bool $isInsert): void
            {
                throw new \RuntimeException('boom');
            }
        };
        DataMapper::setChangeListener($throwing);

        $m = new LifecycleModel();
        $m->name = 'alice';

        // Capture error_log output by redirecting it to a temp file.
        $tmp = tempnam(sys_get_temp_dir(), 'anorm_err_');
        $prev = ini_set('error_log', $tmp);
        try {
            $m->write();
        } finally {
            ini_set('error_log', $prev);
        }

        $this->assertNotNull($m->id);
        $this->assertStringContainsString('boom', file_get_contents($tmp));
        $this->assertIsArray($m->_lastSnapshot);
        $this->assertSame('alice', $m->_lastSnapshot['name']);
        unlink($tmp);
    }

    public function testWrite_ListenerCallsWrite_NestedWriteCommits()
    {
        $listener = new class implements ChangeListenerInterface {
            /** @var LifecycleModel|null */
            public $captured = null;
            public function onWrite(\Anorm\Model $model, array $diff, bool $isInsert): void
            {
                $other = new LifecycleModel();
                $other->name = 'side-effect';
                $other->write();
                $this->captured = $other;
            }
        };
        DataMapper::setChangeListener($listener);

        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->write();

        $this->assertNotNull($m->id);
        $this->assertNotNull($listener->captured);
        $this->assertNotNull($listener->captured->id);
        $this->assertNotSame($m->id, $listener->captured->id);

        // Nested write must still refresh its own snapshot, since the listener
        // is registered. The listener simply isn't re-invoked for it.
        $this->assertIsArray($listener->captured->_lastSnapshot);
        $this->assertSame('side-effect', $listener->captured->_lastSnapshot['name']);
    }

    public function testWrite_NestedWrite_DoesNotReinvokeListener()
    {
        $listener = new class implements ChangeListenerInterface {
            public $callCount = 0;
            public function onWrite(\Anorm\Model $model, array $diff, bool $isInsert): void
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    $other = new LifecycleModel();
                    $other->name = 'side-effect';
                    $other->write();
                }
            }
        };
        DataMapper::setChangeListener($listener);

        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->write();

        $this->assertSame(1, $listener->callCount);

        $rows = TestEnvironment::pdo()
            ->query('SELECT name FROM `lifecycle_model` ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['alice', 'side-effect'], $rows);
    }

    public function testWrite_ListenerThrows_SubsequentWriteStillFiresListener()
    {
        $listener = new class implements ChangeListenerInterface {
            public $callCount = 0;
            public function onWrite(\Anorm\Model $model, array $diff, bool $isInsert): void
            {
                $this->callCount++;
                throw new \RuntimeException('boom');
            }
        };
        DataMapper::setChangeListener($listener);

        $tmp = tempnam(sys_get_temp_dir(), 'anorm_err_');
        $prev = ini_set('error_log', $tmp);
        try {
            $a = new LifecycleModel();
            $a->name = 'alice';
            $a->write();

            $b = new LifecycleModel();
            $b->name = 'bob';
            $b->write();
        } finally {
            ini_set('error_log', $prev);
        }

        // Both writes must fire the listener — the finally block resets
        // $insideListener after the first throw.
        $this->assertSame(2, $listener->callCount);
        unlink($tmp);
    }

    public function testWrite_AfterNestedWrite_OuterContinuesNormally()
    {
        $listener = new class implements ChangeListenerInterface {
            public $callCount = 0;
            public function onWrite(\Anorm\Model $model, array $diff, bool $isInsert): void
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    $other = new LifecycleModel();
                    $other->name = 'side-effect';
                    $other->write();
                }
            }
        };
        DataMapper::setChangeListener($listener);

        $a = new LifecycleModel();
        $a->name = 'alice';
        $a->write();

        // After the nested-write call returns, $insideListener must be reset
        // so a subsequent top-level write fires the listener again.
        $b = new LifecycleModel();
        $b->name = 'bob';
        $b->write();

        $this->assertSame(2, $listener->callCount);
    }

    public function testWrite_PartialLoad_DiffOnlyHasLoadedFields()
    {
        $m = new LifecycleModel();
        $m->name = 'alice';
        $m->email = 'a@example.com';
        $m->write();
        $id = $m->id;

        $listener = new RecordingListener();
        DataMapper::setChangeListener($listener);

        $loaded = new LifecycleModel();
        $loaded->read($id);
        $loaded->setLoadedFields(['name']);
        $loaded->name = 'bob';
        $loaded->email = 'changed@example.com'; // not loaded — should be ignored in diff
        $loaded->write();

        $this->assertCount(1, $listener->calls);
        $this->assertArrayHasKey('name', $listener->calls[0]['diff']);
        $this->assertArrayNotHasKey('email', $listener->calls[0]['diff']);
    }
}
