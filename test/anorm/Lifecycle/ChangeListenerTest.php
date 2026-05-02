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
}
