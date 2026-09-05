<?php

namespace Anorm\Test;

use PHPUnit\Framework\TestCase;

/**
 * Guards the PHP 7.4 floor declared in composer.json.
 *
 * Anorm supports PHP 7.4, but the test suite cannot prove that by running:
 * `symfony/polyfill-php80` arrives transitively through the dev dependencies
 * (symfony/console, pulled in by php-coveralls and phpstan), so every PHP 8
 * string function is defined while the suite runs. A consumer installing with
 * `--no-dev` gets no such polyfill, and `str_contains()` in src/ becomes a
 * fatal "call to undefined function" on 7.4 — see issue #52, which is exactly
 * that, unnoticed by a CI matrix that lists 7.4.
 *
 * So the check has to be static. This scans the source for calls to functions
 * that do not exist on 7.4, which works identically on every leg of the matrix
 * and catches a regression on 8.3 just as well as on 7.4.
 *
 * PHP 8 *syntax* (match, enums, promoted properties, nullsafe) needs no guard
 * here: it is a parse error on 7.4, so the 7.4 leg fails the moment the file is
 * loaded.
 */
class Php74Compatibility_Test extends TestCase
{
    /**
     * Functions added after PHP 7.4, mapped to the version that introduced
     * them. Not exhaustive — these are the ones a PHP 8 habit reaches for.
     *
     * @var array<string, string>
     */
    private const POST_74_FUNCTIONS = [
        'str_contains' => '8.0',
        'str_starts_with' => '8.0',
        'str_ends_with' => '8.0',
        'get_debug_type' => '8.0',
        'preg_last_error_msg' => '8.0',
        'array_is_list' => '8.1',
        'enum_exists' => '8.1',
        'json_validate' => '8.3',
        'str_increment' => '8.3',
        'str_decrement' => '8.3',
        'array_find' => '8.4',
        'array_any' => '8.4',
        'array_all' => '8.4',
        'mb_trim' => '8.4',
    ];

    /**
     * Directories that must run on 7.4. test/ is included because the suite
     * itself has to run on the floor, and because relying on a dev-dependency
     * polyfill to make that true is what hid issue #52 in the first place.
     *
     * @var array<int, string>
     */
    private const SCANNED_PATHS = ['src', 'tools/src', 'bin', 'test'];

    public function testSourceDoesNotCallFunctionsMissingOnPhp74(): void
    {
        $offences = [];

        foreach ($this->phpFiles() as $file) {
            foreach ($this->postPhp74CallsIn($file) as $offence) {
                $offences[] = $offence;
            }
        }

        $this->assertSame(
            [],
            $offences,
            "These calls fatal on PHP 7.4 unless a polyfill happens to be installed:\n  "
            . implode("\n  ", $offences)
            . "\nUse the 7.4 equivalent (strpos(), substr(), ...) instead."
        );
    }

    /**
     * Sanity check on the scanner: it has to actually find something, or an
     * empty result above would prove nothing. A wrong root, a glob that stops
     * matching, or token_get_all() changing shape would all show up here.
     */
    public function testScannerReachesTheSource(): void
    {
        $files = iterator_to_array($this->phpFiles());

        $this->assertGreaterThan(50, count($files), 'the scan found suspiciously few PHP files');
        $this->assertContains(
            $this->projectRoot() . '/src/DataMapper.php',
            $files,
            'the scan did not reach src/DataMapper.php'
        );
    }

    /**
     * @return \Generator<int, string>
     */
    private function phpFiles(): \Generator
    {
        foreach (self::SCANNED_PATHS as $path) {
            $root = $this->projectRoot() . '/' . $path;
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    yield $entry->getPathname();
                }
            }
        }
    }

    /**
     * Find calls to post-7.4 functions in one file.
     *
     * Tokens rather than a regex, so that the name inside a string literal —
     * `function_exists('str_contains')`, or the map at the top of this very
     * file — is not mistaken for a call, and neither is a method of the same
     * name reached through `->` or `::`.
     *
     * @param string $file
     * @return array<int, string> Offences as "path:line — name() (PHP x.y)"
     */
    private function postPhp74CallsIn(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $offences = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = strtolower($token[1]);
            if (!isset(self::POST_74_FUNCTIONS[$name])) {
                continue;
            }

            // A declaration, a method call, or a class constant — not a call to
            // the global function.
            $previous = $this->significantToken($tokens, $i, -1);
            if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            if ($this->significantToken($tokens, $i, 1) !== '(') {
                continue;
            }

            $offences[] = sprintf(
                '%s:%d — %s() (PHP %s)',
                $this->relativePath($file),
                $token[2],
                $token[1],
                self::POST_74_FUNCTIONS[$name]
            );
        }

        return $offences;
    }

    /**
     * The next or previous token that isn't whitespace or a comment.
     *
     * @param array<int, mixed> $tokens
     * @param int $from
     * @param int $step -1 to walk backwards, 1 to walk forwards
     * @return array|string|null
     */
    private function significantToken(array $tokens, int $from, int $step)
    {
        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $token;
        }

        return null;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relativePath(string $file): string
    {
        $root = $this->projectRoot() . '/';
        return strpos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
    }
}
