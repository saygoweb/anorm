<?php
namespace Anorm\Test;

class TestDbConfig
{
    public static function getPdo()
    {
        $host = getenv('DB_HOST') ?: 'db';
        $dbname = getenv('DB_NAME') ?: 'anorm_test';
        $user = getenv('DB_USER') ?: 'dev';
        $pass = getenv('DB_PASS') ?: 'dev';
        return new \PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    }
}
