<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;


class TestClassModel {
    public $testProperty;
}

class DataMapperTest extends TestCase
{
    function testAutoTable_TwoWords_OK()
    {
        $name = DataMapper::autoTable(new TestClassModel());
        $this->assertEquals('test_class', $name);
    }
    
    function testSplitUpper_TwoWords_OK()
    {
        $actual = DataMapper::splitUpper('TestWord');
        $this->assertEquals(array('Test', 'Word'), $actual);
    }
    
    function testPropertyName_TwoWords_OK()
    {
        $name = DataMapper::propertyName('testTwoThreeFour');
        $this->assertEquals('test_two_three_four', $name);
    }
    
    function testPropertyName_EndsWithDigit_OK()
    {
        $name = DataMapper::propertyName('test1');
        $this->assertEquals('test1', $name);
    }
    
    function testPropertyName_TwoWordsEndsWithDigit_OK()
    {
        $name = DataMapper::propertyName('testTwo1');
        $this->assertEquals('test_two1', $name);
    }
    
    function testAutoMap_TestClass_OK()
    {
        $actual = DataMapper::autoMap(new TestClassModel());
        $this->assertEquals(array('testProperty' => 'test_property'), $actual);
    }
    
}