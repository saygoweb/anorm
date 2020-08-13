<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Transformer;
use Anorm\Test\TestEnvironment;

class TransformerTest extends TestCase
{
    public function testNullTransform_Ok()
    {
        $o = new Transformer(
            function($value) { return $value; },
            function($value) { return $value; }
        );
        $result = $o->txDatabaseToModel('test');
        $this->assertEquals('test', $result);
        $result = $o->txModelToDatabase('test');
        $this->assertEquals('test', $result);
    }

}