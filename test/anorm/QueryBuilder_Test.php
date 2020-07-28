<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\QueryBuilder;

require_once(__DIR__ . '/TestEnvironment.php');

class QueryBuilderTest extends TestCase
{
    /** 
     * @expectedException \Exception
     * @expectedExceptionMessage '$creatable' is not a class
     */
    public function testConstruct_BogusClass_Throws()
    {
        $o = new QueryBuilder('bogus', null);
    }

    public function testFunctions_ReturnThis()
    {
        $pdo = TestEnvironment::pdo();
        $o = new QueryBuilder('SomeTableModel', $pdo);
        $result = $o->select('');
        $this->assertSame($o, $result);
        $result = $o->from('');
        $this->assertSame($o, $result);
        $result = $o->where('', []);
        $this->assertSame($o, $result);
        $result = $o->orderBy('');
        $this->assertSame($o, $result);
        $result = $o->limit('');
        $this->assertSame($o, $result);
    }
}