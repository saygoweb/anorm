<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Transformer;
use Anorm\Test\TestEnvironment;
use Anorm\Transform\BooleanTransform;
use Anorm\Transform\FunctionTransform;
use Anorm\Transform\JsonArrayTransform;
use Anorm\Transform\SqlDateTimeTransform;

class TransformerTest extends TestCase
{
    public function testNullFunctionTransform_Ok()
    {
        $o = new FunctionTransform(
            function ($value) {
                return $value;
            },
            function ($value) {
                return $value;
            }
        );
        $result = $o->txDatabaseToModel('test');
        $this->assertEquals('test', $result);
        $result = $o->txModelToDatabase('test');
        $this->assertEquals('test', $result);
    }

    public function testJsonArrayTransform_Ok()
    {
        $t = new JsonArrayTransform();
        $result1 = $t->txDatabaseToModel('{ "key": "value" }');
        $this->assertEquals(['key' => 'value'], $result1);
        $result2 = $t->txModelToDatabase($result1);
        $this->assertEquals('{"key":"value"}', $result2);
    }

    public function testBooleanTransform_RoundTripsABoolean()
    {
        $t = new BooleanTransform();

        $this->assertSame(1, $t->txModelToDatabase(true));
        $this->assertSame(0, $t->txModelToDatabase(false));
        $this->assertTrue($t->txDatabaseToModel('1'));
        $this->assertFalse($t->txDatabaseToModel('0'));
    }

    public function testBooleanTransform_LeavesNullAlone()
    {
        // A nullable flag has three states, and a transformer that collapsed NULL to
        // false would lose the one that means "not answered".
        $t = new BooleanTransform();

        $this->assertNull($t->txModelToDatabase(null));
        $this->assertNull($t->txDatabaseToModel(null));
    }

    public function testBooleanTransform_ReadsWhatMySqlActuallyReturns()
    {
        // A TINYINT arrives from PDO as a string, which is the whole reason a model
        // cannot simply compare the property to true.
        $t = new BooleanTransform();

        $this->assertFalse($t->txDatabaseToModel('0'));
        $this->assertTrue($t->txDatabaseToModel('1'));
        $this->assertFalse($t->txDatabaseToModel(0));
        $this->assertTrue($t->txDatabaseToModel(1));
    }

    public function testBooleanTransform_SaysWhatColumnItNeeds()
    {
        $this->assertEquals('TINYINT(1) NULL', (new BooleanTransform())->sqlColumnType());
    }

    public function testSqlDateTimeTransform_Ok()
    {
        $t = new SqlDateTimeTransform();
        $testString = '2021-06-29 16:10:11';
        $result1 = $t->txDatabaseToModel($testString);
        $this->assertInstanceOf('DateTime', $result1);
        $this->assertEquals(new DateTime($testString), $result1);
        $result2 = $t->txModelToDatabase($result1);
        $this->assertEquals($testString, $result2);
    }
}
