<?php

namespace Anorm\Test;

use Anorm\Model;
use Anorm\DataMapper;
use Anorm\Anorm;

/**
 * Test model for dynamic foreign key creation with CASCADE delete
 */
class DynamicUserWithCascadeModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'dynamic_users_cascade';
        $mapper->mode = DataMapper::MODE_DYNAMIC; // Enable dynamic schema creation
        
        // Set up column mapping
        $mapper->map = [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
            'company_id' => 'company_id'
        ];
        
        parent::__construct($pdo, $mapper);
        
        // Define relationship with CASCADE delete constraint
        $this->belongsTo(
            'Anorm\Test\DynamicCompanyModel', 
            'company_id', 
            'id', 
            'company',
            $this->cascadeDelete() // Use CASCADE delete constraint
        );
    }

    // Model properties
    public $id;
    public $name;
    public $email;
    public $company_id;

    // Relationship properties
    /** @var DynamicCompanyModel */
    public $company;
}
