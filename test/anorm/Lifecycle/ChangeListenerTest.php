<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\DataMapper;
use Anorm\Test\Lifecycle\RecordingListener;

class ChangeListenerTest extends TestCase
{
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
}
