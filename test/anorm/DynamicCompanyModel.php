<?php

namespace Anorm\Test;

use Anorm\Model;
use Anorm\DataMapper;
use Anorm\Anorm;

/**
 * Test model for dynamic foreign key creation
 */
class DynamicCompanyModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'dynamic_companies';
        $mapper->mode = DataMapper::MODE_DYNAMIC; // Enable dynamic schema creation
        
        // Set up column mapping
        $mapper->map = [
            'id' => 'id',
            'name' => 'name',
            'address' => 'address'
        ];
        
        parent::__construct($pdo, $mapper);
        
        // Define relationships
        $this->hasMany('Anorm\Test\DynamicUserModel', 'company_id', 'id', 'users');
    }

    // Model properties
    public $id;
    public $name;
    public $address;

    // Relationship properties
    /** @var DynamicUserModel[] */
    public $users;
}
