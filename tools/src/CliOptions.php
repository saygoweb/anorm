<?php
namespace Anorm\Tools;

/**
 * Small repairs to the raw argument list before the CLI parser sees it.
 */
class CliOptions
{
    /**
     * Turn `--option=value` into `--option value`.
     *
     * The argument lexer does not understand the assignment form, and it does not
     * complain about it either: `--user=dev` is not an option at all, so it becomes a
     * positional argument and `--user` keeps its default. That silently connects as
     * the wrong user, or diffs the wrong table, which is worth a dozen lines to avoid.
     *
     * @param string[] $argv The arguments, without the program name
     * @return string[]
     */
    public static function splitAssignments(array $argv)
    {
        $out = array();
        foreach ($argv as $argument) {
            if (\strpos($argument, '--') === 0 && \strpos($argument, '=') !== false) {
                $parts = \explode('=', $argument, 2);
                if ($parts[0] !== '--') {
                    $out[] = $parts[0];
                    $out[] = $parts[1];
                    continue;
                }
            }
            $out[] = $argument;
        }
        return $out;
    }
}
