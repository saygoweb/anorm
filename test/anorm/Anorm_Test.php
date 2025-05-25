<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;

class AnormTest extends TestCase
{
    public function testConnctionAndUse_OK()
    {
        $anorm1 = Anorm::connect('testname', 'mysql:host=db;dbname=anorm_test', 'dev', 'dev');
        $this->assertInstanceOf('Anorm\Anorm', $anorm1);

        $anorm2 = Anorm::use('testname');
        $this->assertInstanceOf('Anorm\Anorm', $anorm2);
        $this->assertEquals($anorm1, $anorm2);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Anorm: Connection 'bogusname' doesn't exist. Call Anorm::connect first.
     */
    public function testUse_NotConnected_Fails()
    {
        $result = Anorm::use('bogusname');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Anorm: Connection 'bogusname' doesn't exist. Call Anorm::connect first.
     */
    public function testPdo_NotConnected_Fails()
    {
        $result = Anorm::pdo('bogusname');
    }

    /**
     * @expectedException \PDOException
     */
    public function testConnction_Bogus_Fails()
    {
        $result = Anorm::connect('bogusname', 'mysql:host=db;dbname=bogus', 'bogus', '');
    }

}