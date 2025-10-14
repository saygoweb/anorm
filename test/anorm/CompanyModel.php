<?php

namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class CompanyModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'companies'; // Override table name to use plural
        parent::__construct($pdo, $mapper);

        // Define relationships with explicit property names
        $this->hasMany('Anorm\Test\UserModel', 'company_id', 'id', 'users');
    }

    public $id;
    public $name;
    public $address;

    /** @var UserModel[] */
    public $users;
}
