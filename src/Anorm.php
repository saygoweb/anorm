<?php
namespace Anorm;

class Anorm
{

    private static $connections = array();

    /**
     * @var callable Function that returns a SQL type definition for creating a column
     *   function($fieldName, $sampleData) {
     *       return TableMaker::columnDefinition($fieldName, $sampleData);
     *   };
     */
    public static $columnFn = '\Anorm\TableMaker::columnDefinition';

    /**
     * Creates a new Anorm connection named $name connected to $dsn.
     * @param string $name Name of this connection for later use.
     * @param string $dsn PDO DSN string to establish the connection.
     * @return Anorm
     * @see connect
     */
    public static function connect($name, $dsn, $user, $password)
    {
        if (!\array_key_exists($name, self::$connections)) {
            self::$connections[$name] = new Anorm($dsn, $user, $password);
        }
        return self::$connections[$name];
    }

    /**
     * Returns the Anorm connection of the given $name.
     * Note that the connection must have been previously opened
     * with a call to connect.
     * @param string $name Name of the connection to use. 
     * @return Anorm
     * @see connect
     */
    public static function use($name)
    {
        if (!\array_key_exists($name, self::$connections)) {
            throw new \Exception("Anorm: Connection '$name' doesn't exist. Call Anorm::connection first.");
        }
        return self::$connections[$name];
    }

    /** @var PDO The connection */
    public $pdo;

    private function __construct($dsn, $user, $password)
    {
        $this->pdo = new \PDO($dsn, $user, $password);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }
}