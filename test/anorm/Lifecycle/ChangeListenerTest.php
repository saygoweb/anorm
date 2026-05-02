<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\DataMapper;
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
}
