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

    public function testIntegerPrefixes_DoNotMatchLongerWords()
    {
        // `interval` and `intent` start with `int`; a word boundary keeps them out.
        $this->assertSame('string', SqlType::toPhpType('interval'));
    }
}
