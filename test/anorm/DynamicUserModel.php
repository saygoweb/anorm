<?php

namespace Anorm\Test;

use Anorm\Model;
use Anorm\DataMapper;
use Anorm\Anorm;

/**
 * Test model for dynamic foreign key creation
 */
class DynamicUserModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'dynamic_users';
        $mapper->mode = DataMapper::MODE_DYNAMIC; // Enable dynamic schema creation
        
        // Set up column mapping
        $mapper->map = [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
            'company_id' => 'company_id'
        ];
        
        parent::__construct($pdo, $mapper);
        
        // Define relationships - these will trigger foreign key creation
        $this->belongsTo('Anorm\Test\DynamicCompanyModel', 'company_id', 'id', 'company');
        $this->hasMany('Anorm\Test\DynamicPostModel', 'user_id', 'id', 'posts');
    }

    // Model properties
    public $id;
    public $name;
    public $email;
    public $company_id;

    // Relationship properties
    /** @var DynamicCompanyModel */
    public $company;
    
    /** @var DynamicPostModel[] */
    public $posts;
}
