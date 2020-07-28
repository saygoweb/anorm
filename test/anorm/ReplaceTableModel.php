<?php
namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class ReplaceTableModel extends Model {
    public function __construct()
    {
        $pdo = Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->modelPrimaryKey = 'replaceId';
        $this->_mapper->useReplace = true;
        $this->dtc = null;
    }

    public function countRows()
    {
        $result = $this->_mapper->query('SELECT * FROM `replace_table`');
        return $result->rowCount();
    }

    public $replaceId;
    public $name;
    public $dtc;
}

