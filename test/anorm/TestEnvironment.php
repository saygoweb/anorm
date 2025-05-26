<?php
namespace Anorm\Test;

use Anorm\Anorm;

class TestEnvironment
{
    /**
     * Connects using environment variables or provided overrides.
     * @param string $name Connection name
     * @param array $overrides Optional overrides for host, dbname, user, pass
     */
    public static function connect($name = null, $overrides = [])
    {
        $host = $overrides['host'] ?? getenv('DB_HOST') ?: 'db';
        $dbname = $overrides['dbname'] ?? getenv('DB_NAME') ?: 'anorm_test';
        $user = $overrides['user'] ?? getenv('DB_USER') ?: 'dev';
        $pass = $overrides['pass'] ?? getenv('DB_PASS') ?: 'dev';
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
    public static function pdo($name = null, $overrides = []) : \PDO
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
}