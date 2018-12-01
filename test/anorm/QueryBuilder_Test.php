<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\QueryBuilder;

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
}