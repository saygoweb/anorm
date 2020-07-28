<?php

class TestEnvironment
{

    public static function pdo() : \PDO
    {
        static $pdo = null;
        if (!$pdo) {
            $pdo = new \PDO('mysql:host=localhost;dbname=anorm_test', 'travis', '');
        }
        return $pdo;
    }
}