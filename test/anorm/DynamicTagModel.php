<?php

namespace Anorm\Test;

use Anorm\Model;
use Anorm\DataMapper;
use Anorm\Anorm;

/**
 * Test model for dynamic foreign key creation
 */
class DynamicTagModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'dynamic_tags';
        $mapper->mode = DataMapper::MODE_DYNAMIC; // Enable dynamic schema creation

        // Set up column mapping
        $mapper->map = [
            'id' => 'id',
            'name' => 'name'
        ];

        parent::__construct($pdo, $mapper);

        // Define relationships
        $this->hasManyThrough('Anorm\Test\DynamicPostModel', 'tag_id', 'post_id', 'dynamic_post_tags', 'id', 'posts');
    }

    // Model properties
    public $id;
    public $name;

    // Relationship properties
    /** @var DynamicPostModel[] */
    public $posts;
}
