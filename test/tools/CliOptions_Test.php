<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\Tools\CliOptions;

class CliOptionsTest extends TestCase
{
    public function testAssignmentForm_BecomesTwoArguments()
    {
        $this->assertSame(
            ['schema:diff', 'anorm_test', '--models', 'src/Models/'],
            CliOptions::splitAssignments(['schema:diff', 'anorm_test', '--models=src/Models/'])
        );
    }

    public function testAValueContainingAnEquals_IsKeptWhole()
    {
        $this->assertSame(['--dsn', 'a=b=c'], CliOptions::splitAssignments(['--dsn=a=b=c']));
    }

    public function testOtherArguments_AreUntouched()
    {
        $argv = ['make', 'anorm_test', 'users', '-p', '--force', '-u', 'dev'];
        $this->assertSame($argv, CliOptions::splitAssignments($argv));
    }

    public function testShortOptionsAndBareDashes_AreLeftAlone()
    {
        // Only long options take the assignment form; `-u=dev` means something else
        // to the parser, and `--` is a separator rather than an option.
        $this->assertSame(['-u=dev'], CliOptions::splitAssignments(['-u=dev']));
        $this->assertSame(['--=x'], CliOptions::splitAssignments(['--=x']));
    }
}
