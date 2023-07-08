<?php
namespace Anorm;

class TableMaker
{

    public static function fix(\Exception $exception, DataMapper $mapper, $model = null)
    {
        // TODO We could create this from an Anorm factory / container
        $maker = new TableMaker($exception, $mapper, $model);
        return $maker->_fix();
    }

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
            throw new \Exception('Anorm: Could not parse PDOException', 0, $this->exception);
        }
        $tableName = $matches[2];
        // Create the table with an auto increment id as primary key.
        // Review: Should we also try and create all the columns we can now,
        // or wait until possibly later when we might have better data
        // to hint the type?
        // Current design choice is to wait until later even if it means
        // a highly iterative, multiple exception approach on the common
        // first write case.
        $sql = "CREATE TABLE `$tableName`(
            id INT(11) AUTO_INCREMENT PRIMARY KEY
        )";
        $this->mapper->pdo->query($sql);
    }

    private function createColumn()
    {
        // Regex the message to get the name of the table
        $matches = array();
        if (!\preg_match("/column '([^\.']*)'/", $this->exception->getMessage(), $matches)) {
            throw new \Exception('Anorm: Could not parse PDOException', 0, $this->exception);
        }
        $columnName = $matches[1];
        // Add the column.
        // TODO Have a go at figuring out the type if the model is available.
        $sampleData = null;
        if ($this->model) {
            // See if we can reverse map the 
            $invertMap = array_flip($this->mapper->map);
            $property = $invertMap[$columnName];
            $sampleData = $this->model->$property;
        }
        $columnFn = Anorm::$columnFn; // Redundant, but can't do this Anorm::$columnFn(...)
        $columnDefinition = $columnFn($columnName, $sampleData);
        $sql = "ALTER TABLE `" . $this->mapper->table . "` ADD $columnName $columnDefinition";
        $this->mapper->pdo->query($sql);
    }

    public static function columnDefinition($columnName, $sampleData)
    {
        if ($sampleData) {
            if (\is_numeric($sampleData)) {
                if (\is_integer($sampleData)) {
                    return "INT(11) NULL";
                }
                if (\is_float($sampleData)) {
                    return "DOUBLE NULL";
                }
            }
            if (is_object($sampleData) && get_class($sampleData) == 'Moment\Moment') {
                return "DATETIME NULL";
            }
            if (is_string($sampleData)) {
                if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $sampleData) === 1) {
                    return "DATETIME NULL";
                }
                if (strlen($sampleData) > 256) {
                    return "TEXT";
                }
                if (strlen($sampleData) > 128) {
                    return "VARCHAR(256)";
                }
            }
        }
        return 'VARCHAR(128)';
    }
}