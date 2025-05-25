<?php
namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\Test\TestDbConfig;
class TestEnvironment
{

    public static function connect()
    {
        $host = getenv('DB_HOST') ?: 'db';
        $dbname = getenv('DB_NAME') ?: 'anorm_test';
        $user = getenv('DB_USER') ?: 'dev';
        $pass = getenv('DB_PASS') ?: 'dev';
        $dsn = "mysql:host=$host;dbname=$dbname";
        \Anorm\Anorm::connect(\Anorm\Anorm::DEFAULT, $dsn, $user, $pass);
    }

    public static function pdo() : \PDO
    {
        static $pdo = null;
        if (!$pdo) {
            self::connect();
            $pdo = TestDbConfig::getPdo();
        }
        return $pdo;
    }
}