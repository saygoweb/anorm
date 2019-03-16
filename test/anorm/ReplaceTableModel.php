<?php

use Anorm\DataMapper;
use Anorm\Model;

class ReplaceTableModel extends Model {
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->modelPrimaryKey = 'replaceId';
        $this->_mapper->useReplace = true;
    }

    public function countRows()
    {
        $result = $this->_mapper->query('SELECT * FROM `replace_table`');
        return $result->rowCount();
    }

    public $replaceId;
    public $name;
}

