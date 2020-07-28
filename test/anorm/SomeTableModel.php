<?php

use Anorm\DataMapper;
use Anorm\Model;

class SomeTableModel extends Model {
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->modelPrimaryKey = 'someId';
        $this->dtc = null;
    }

    public function countRows()
    {
        $result = $this->_mapper->query('SELECT * FROM `some_table`');
        return $result->rowCount();
    }

    public $someId;
    public $name;
    public $dtc;
}

