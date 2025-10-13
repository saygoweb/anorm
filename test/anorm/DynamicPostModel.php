<?php

namespace Anorm\Test;

use Anorm\Model;
use Anorm\DataMapper;
use Anorm\Anorm;

/**
 * Test model for dynamic foreign key creation
 */
class DynamicPostModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'dynamic_posts';
        $mapper->mode = DataMapper::MODE_DYNAMIC; // Enable dynamic schema creation
        
        // Set up column mapping
        $mapper->map = [
            'id' => 'id',
            'title' => 'title',
            'content' => 'content',
            'user_id' => 'user_id'
        ];
        
        parent::__construct($pdo, $mapper);
        
        // Define relationships - these will trigger foreign key creation
        $this->belongsTo('Anorm\Test\DynamicUserModel', 'user_id', 'id', 'user');
        $this->hasManyThrough('Anorm\Test\DynamicTagModel', 'post_id', 'tag_id', 'dynamic_post_tags', 'id', 'tags');
    }

    // Model properties
    public $id;
    public $title;
    public $content;
    public $user_id;

    // Relationship properties
    /** @var DynamicUserModel */
    public $user;
    
    /** @var DynamicTagModel[] */
    public $tags;
}
