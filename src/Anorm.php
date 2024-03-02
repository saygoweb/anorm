<?php
namespace Anorm;

class Anorm
{

    const DEFAULT = 'default';

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
    public static function use($name = self::DEFAULT)
    {
        if (!\array_key_exists($name, self::$connections)) {
            throw new \Exception("Anorm: Connection '$name' doesn't exist. Call Anorm::connection first.");
        }
        return self::$connections[$name];
    }

    /**
     * Returns the \PDO instance of the Anorm connection of the given $name
     * @param string $name Name of the connection to use.
     * @return \PDO
     * @see connect
     */
    public static function pdo($name = self::DEFAULT)
    {
        if (!\array_key_exists($name, self::$connections)) {
            throw new \Exception("Anorm: Connection '$name' doesn't exist. Call Anorm::connect first.");
        }
        return self::$connections[$name]->pdo;
    }

    /** @var \PDO The connection */
    public $pdo;

    private function __construct($dsn, $user, $password)
    {
        if (strpos($dsn, 'mysql') !== false && strpos($dsn, 'charset') === false) {
            $dsn .= ';charset=utf8mb4';
        }
        $this->pdo = new \PDO($dsn, $user, $password);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }
}