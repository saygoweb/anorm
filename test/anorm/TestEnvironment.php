<?php
namespace Anorm\Test;

use Anorm\Anorm;
class TestEnvironment
{

    public static function connect()
    {
        Anorm::connect(Anorm::DEFAULT, 'mysql:host=localhost;dbname=anorm_test', 'travis', '');
    }

    public static function pdo() : \PDO
    {
        static $pdo = null;
        if (!$pdo) {
            self::connect();
            $pdo = Anorm::pdo(Anorm::DEFAULT);
        }
        return $pdo;
    }
}