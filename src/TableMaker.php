<?php
namespace Anorm;

class TableMaker
{

    /** @var \PDOException The exception that requires the database schema to be fixed. */
    public $exception;

    /** @var DataMapper The DataMapper */
    private $mapper;

    /** @var Model An optional model instance */
    private $model;

    public function __construct(\Exception $exception, DataMapper $mapper, $model)
    {
        $this->exception = $exception;
        $this->mapper = $mapper;
        $this->model = $model;
    }

    public static function fix(\Exception $exception, DataMapper $mapper, $model = null)
    {
        $maker = new TableMaker($exception, $mapper, $model);
        return $maker->_fix();
    }

    private function _fix()
    {
        switch ($this->exception->getCode()) {
            case '42S02': // table not found
                $this->createTable();
                break;
            case '42S22': // column not found
                $this->createColumn();
                break;
        }
    }

    private function createTable()
    {
        // Regex the message to get the name of the table
        $matches = array();
        if (!\preg_match("/'([^\.']*)\.([^\.']*)'/", $this->exception->getMessage(), $matches)) {
            return; // Or throw?
        }
        $tableName = $matches[2];
        // Create the table with an auto increment id as primary key.
        // printf($this->exception->getMessage());
        // var_dump($matches);
        // exit;
        $sql = "CREATE TABLE $tableName(
            id INT(11) AUTO_INCREMENT PRIMARY KEY
        )";
        $this->mapper->pdo->query($sql);
    }

    private function createColumn()
    {
        // Regex the message to get the name of the table
        $matches = array();
        if (!\preg_match("/column '([^\.']*)'/", $this->exception->getMessage(), $matches)) {
            return; // Or throw?
        }
        $columnName = $matches[1];
        // Add the column.
        // printf($this->exception->getMessage());
        // var_dump($matches);
        // exit;
        // TODO Have a go at figuring out the type if the model is available.
        $sql = "ALTER TABLE " . $this->mapper->table . " ADD $columnName VARCHAR(128)";
        $this->mapper->pdo->query($sql);
    }
}