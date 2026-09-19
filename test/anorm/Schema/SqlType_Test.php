<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\Schema\SqlType;

/**
 * The PHP type a declared column corresponds to — the direction that does not have
 * to guess, because the column has been declared.
 */
class SqlType_Test extends TestCase
{
    public function testIntegerWidths_AreAllInt()
    {
        $this->assertSame('int', SqlType::toPhpType('int(11)'));
        $this->assertSame('int', SqlType::toPhpType('INT(11) UNSIGNED'));
        $this->assertSame('int', SqlType::toPhpType('bigint(20)'));
        $this->assertSame('int', SqlType::toPhpType('smallint(6)'));
        $this->assertSame('int', SqlType::toPhpType('mediumint(9)'));
        $this->assertSame('int', SqlType::toPhpType('tinyint(4)'), 'a width other than 1 is a number');
    }

    public function testTinyint1_IsHowMysqlSpellsBoolean()
    {
        $this->assertSame('bool', SqlType::toPhpType('tinyint(1)'));
    }

    public function testFractionalTypes_AreFloat()
    {
        $this->assertSame('float', SqlType::toPhpType('decimal(10,2)'));
        $this->assertSame('float', SqlType::toPhpType('double'));
        $this->assertSame('float', SqlType::toPhpType('float'));
        $this->assertSame('float', SqlType::toPhpType('numeric(8,4)'));
    }

    public function testEverythingElse_ReachesAModelAsAString()
    {
        $this->assertSame('string', SqlType::toPhpType('varchar(128)'));
        $this->assertSame('string', SqlType::toPhpType('text'));
        $this->assertSame('string', SqlType::toPhpType('datetime'));
        $this->assertSame('string', SqlType::toPhpType('date'));
        $this->assertSame('string', SqlType::toPhpType('json'));
        $this->assertSame('string', SqlType::toPhpType("enum('a','b')"));
        $this->assertSame('string', SqlType::toPhpType(''));
    }

    public function testFamily_SeparatesDatesFromOtherStrings()
    {
        // A date reaches a model as a string, but a DATETIME column and a VARCHAR
        // column are not the same decision, and a diff has to be able to say so.
        $this->assertSame('datetime', SqlType::family('datetime'));
        $this->assertSame('datetime', SqlType::family('DATETIME NULL'));
        $this->assertSame('datetime', SqlType::family('date'));
        $this->assertSame('datetime', SqlType::family('timestamp'));
        $this->assertSame('string', SqlType::family('varchar(128)'));
        $this->assertSame('int', SqlType::family('INT(11) NULL'));
        $this->assertSame('bool', SqlType::family('TINYINT(1) NULL'));
    }

    public function testIntegerRank_OrdersTheWidths()
    {
        $this->assertGreaterThan(SqlType::integerRank('int(11)'), SqlType::integerRank('bigint(20)'));
        $this->assertGreaterThan(SqlType::integerRank('smallint(6)'), SqlType::integerRank('int(11)'));
        $this->assertSame(SqlType::integerRank('int(11)'), SqlType::integerRank('INTEGER'));
        $this->assertSame(0, SqlType::integerRank('varchar(128)'), 'not an integer type');
    }

    public function testStringLength_ComparesTextWithVarchar()
    {
        $this->assertSame(128, SqlType::stringLength('varchar(128)'));
        $this->assertSame(32, SqlType::stringLength('char(32)'));
        $this->assertSame(255, SqlType::stringLength('tinytext'));
        $this->assertSame(65535, SqlType::stringLength('text'));
        $this->assertSame(16777215, SqlType::stringLength('mediumtext'));
        $this->assertGreaterThan(SqlType::stringLength('varchar(255)'), SqlType::stringLength('text'));
        $this->assertGreaterThan(SqlType::stringLength('text'), SqlType::stringLength('longtext'));
        $this->assertNull(SqlType::stringLength('int(11)'));
    }

    public function testIntegerPrefixes_DoNotMatchLongerWords()
    {
        // `interval` and `intent` start with `int`; a word boundary keeps them out.
        $this->assertSame('string', SqlType::toPhpType('interval'));
    }
}
