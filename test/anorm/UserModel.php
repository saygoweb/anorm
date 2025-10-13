<?php

namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class UserModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'users'; // Override table name to use plural

        // Fix the column mapping
        $mapper->map = [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
            'company_id' => 'company_id'
        ];

        parent::__construct($pdo, $mapper);

        // Define relationships with explicit property names
        $this->hasMany('Anorm\Test\PostModel', 'user_id', 'id', 'posts');
        $this->hasMany('Anorm\Test\CommentModel', 'user_id', 'id', 'comments');
        $this->belongsTo('Anorm\Test\CompanyModel', 'company_id', 'id', 'company');
    }
    
    public $id;
    public $name;
    public $email;
    public $company_id;
    
    /** @var PostModel[] */
    public $posts;
    
    /** @var CommentModel[] */
    public $comments;
    
    /** @var CompanyModel */
    public $company;
}
