<?php
namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transform\FunctionTransform;

class SomeTableModel extends Model {
    public function __construct()
    {
        $pdo = Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->modelPrimaryKey = 'someId';
        $this->_mapper->transformers['name'] = new FunctionTransform(
            function($value) { return strtolower($value); },
            function($value) { return strtoupper($value); }
        );
        $this->dtc = null;
    }

    public function countRows()
    {
        $result = $this->_mapper->query('SELECT * FROM `some_table`');
        return $result->rowCount();
    }

    public $someId;
    public $name;
    public $category;
    public $dtc;
}

