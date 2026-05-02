<?php

namespace Anorm\Test;

use Anorm\Anorm;

class TestEnvironment
{
    private static $envConfig = null;

    /**
     * Load configuration from .env file
     * @return array
     */
    private static function loadEnvConfig(): array
    {
        if (self::$envConfig !== null) {
            return self::$envConfig;
        }

        self::$envConfig = [];

        // Look for .env.devcontainer file first (for devcontainer environments)
        $devcontainerEnvPath = __DIR__ . '/../../.env.devcontainer';
        $envPath = __DIR__ . '/../../.env';

        // Prefer devcontainer env if it exists, otherwise fall back to regular .env
        $envFileToUse = file_exists($devcontainerEnvPath) ? $devcontainerEnvPath : $envPath;

        if (file_exists($envFileToUse)) {
            $lines = file($envFileToUse, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                // Skip comments and empty lines
                $line = trim($line);
                if (empty($line) || $line[0] === '#') {
                    continue;
                }

                // Parse KEY=VALUE format
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);

                    // Remove quotes if present
                    if (
                        ($value[0] === '"' && $value[-1] === '"') ||
                        ($value[0] === "'" && $value[-1] === "'")
                    ) {
                        $value = substr($value, 1, -1);
                    }

                    self::$envConfig[$key] = $value;
                }
            }
        }

        return self::$envConfig;
    }
    /**
     * Connects using .env file configuration, environment variables, or provided overrides.
     * Priority: overrides > environment variables > .env file > defaults
     * @param string $name Connection name
     * @param array $overrides Optional overrides for host, dbname, user, pass
     */
    public static function connect($name = null, $overrides = [])
    {
        // Load configuration from .env file
        $envConfig = self::loadEnvConfig();

        // Get configuration with priority: overrides > env vars > .env file > defaults
        $host = $overrides['host'] ??
                getenv('DB_HOST') ?:
                $envConfig['DB_HOST'] ??
                'localhost';

        $dbname = $overrides['dbname'] ??
                  getenv('DB_DATABASE') ?: getenv('DB_NAME') ?:
                  $envConfig['DB_DATABASE'] ?? $envConfig['DB_NAME'] ??
                  'anorm_test';

        $user = $overrides['user'] ??
                getenv('DB_USERNAME') ?: getenv('DB_USER') ?:
                $envConfig['DB_USERNAME'] ?? $envConfig['DB_USER'] ??
                'dev';

        $pass = $overrides['pass'] ??
                getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?:
                $envConfig['DB_PASSWORD'] ?? $envConfig['DB_PASS'] ??
                'dev';

        $dsn = "mysql:host=$host;dbname=$dbname";
        $name = $name ?: Anorm::DEFAULT;
        return Anorm::connect($name, $dsn, $user, $pass);
    }

    /**
     * Returns PDO for the default connection, or creates it if needed.
     * @param string $name Connection name
     * @param array $overrides Optional overrides for host, dbname, user, pass
     * @return \PDO
     */
    public static function pdo($name = null, $overrides = []): \PDO
    {
        $name = $name ?: Anorm::DEFAULT;
        static $pdoCache = [];
        if (!isset($pdoCache[$name])) {
            self::connect($name, $overrides);
            $pdoCache[$name] = Anorm::pdo($name);
        }
        return $pdoCache[$name];
    }

    /**
     * For test cases needing a fresh connection (e.g. bogus db/user)
     * @param string $name
     * @param array $overrides
     * @return Anorm
     */
    public static function connectCustom($name, $overrides = [])
    {
        return self::connect($name, $overrides);
    }

    /**
     * For test cases needing a fresh PDO (e.g. bogus db/user)
     * @param string $name
     * @param array $overrides
     * @return \PDO
     */
    public static function pdoCustom($name, $overrides = [])
    {
        self::connect($name, $overrides);
        return Anorm::pdo($name);
    }


    /**
     * Load the shared `RelationshipTestSchema.sql` into the active connection.
     * Called from setUpBeforeClass in tests that need the users/posts/comments/etc.
     * fixture tables but don't manage them themselves. Idempotent — runs the
     * schema's own DROP TABLE IF EXISTS / CREATE TABLE statements with foreign
     * key checks disabled, so a previous test that added dynamic FKs to these
     * tables (e.g. Relationship_Test) doesn't block the reset.
     */
    public static function loadRelationshipSchema()
    {
        $pdo = self::pdo();
        $sql = file_get_contents(__DIR__ . '/RelationshipTestSchema.sql');

        // Strip `#`-prefixed comments line-by-line BEFORE splitting on `;`,
        // otherwise a CREATE preceded by a comment line would be misidentified
        // as a comment by a naive whole-statement check.
        $cleanSql = '';
        foreach (explode("\n", $sql) as $line) {
            $line = trim($line);
            if ($line !== '' && strpos($line, '#') !== 0) {
                $cleanSql .= $line . "\n";
            }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (explode(';', $cleanSql) as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
