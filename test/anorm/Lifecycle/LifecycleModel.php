<?php

namespace Anorm\Test\Lifecycle;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class LifecycleModel extends Model
{
    public $id;
    public $name;
    public $email;
    public $dtu;
    public $payload;

    public function __construct()
    {
        $pdo = Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->table = 'lifecycle_model';
    }
}
