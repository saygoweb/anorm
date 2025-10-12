<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\Test\TestEnvironment;

class AnormTest extends TestCase
{
    public function testConnctionAndUse_OK()
    {
        TestEnvironment::connect('testname');
        $anorm1 = Anorm::use('testname');
        $this->assertInstanceOf('Anorm\Anorm', $anorm1);

        $anorm2 = Anorm::use('testname');
        $this->assertInstanceOf('Anorm\Anorm', $anorm2);
        $this->assertEquals($anorm1, $anorm2);
    }

    public function testUse_NotConnected_Fails()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Anorm: Connection 'bogusname' doesn't exist. Call Anorm::connect first.");

        Anorm::use('bogusname');
    }

    public function testPdo_NotConnected_Fails()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Anorm: Connection 'bogusname' doesn't exist. Call Anorm::connect first.");

        Anorm::pdo('bogusname');
    }

    public function testConnction_Bogus_Fails()
    {
        $this->expectException(\PDOException::class);

        TestEnvironment::connectCustom('bogusname', [
            'dbname' => 'bogus',
            'user' => 'bogus',
            'pass' => ''
        ]);
    }
}
