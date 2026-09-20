<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;

class TestClassModel
{
    public $testProperty;
}

class TestUnderscoreClassModel
{
    public $id;
    public $company_id;
    public $last_synced_at;
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
        $this->assertEquals(['Test', 'Word'], $actual);
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

    function testPropertyName_AlreadyUnderscored_KeepsEverySegment()
    {
        // A property spelled the way the column is spelled maps to itself. Anything
        // else silently writes to a column the model did not name.
        $name = DataMapper::propertyName('company_id');
        $this->assertEquals('company_id', $name);
    }

    function testPropertyName_ThreeUnderscoredSegments_KeepsEverySegment()
    {
        $name = DataMapper::propertyName('last_synced_at');
        $this->assertEquals('last_synced_at', $name);
    }

    function testPropertyName_UnderscoredSegmentInCamelCase_SplitsBoth()
    {
        $name = DataMapper::propertyName('billing_addressLine1');
        $this->assertEquals('billing_address_line1', $name);
    }

    function testAutoMap_TestClass_OK()
    {
        $actual = DataMapper::autoMap(new TestClassModel());
        $this->assertEquals(['testProperty' => 'test_property'], $actual);
    }

    function testAutoMap_UnderscoredProperties_MapToTheColumnsTheyName()
    {
        $actual = DataMapper::autoMap(new TestUnderscoreClassModel());
        $this->assertEquals([
            'id' => 'id',
            'company_id' => 'company_id',
            'last_synced_at' => 'last_synced_at',
        ], $actual);
    }
}
