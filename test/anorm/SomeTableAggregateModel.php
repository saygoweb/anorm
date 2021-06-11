<?php
namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Transformer;

class SomeTableAggregateModel extends Model {
    public function __construct()
    {
        $pdo = Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->table = 'some_table';
    }

    public $categoryCount;
    public $idMin;
    public $idMax;
}

