<?php

namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class TagModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'tags'; // Override table name to use plural
        parent::__construct($pdo, $mapper);

        // Define relationships with explicit property names
        $this->hasManyThrough('Anorm\Test\PostModel', 'tag_id', 'post_id', 'post_tags', 'id', 'posts');
    }
    
    public $id;
    public $name;
    
    /** @var PostModel[] */
    public $posts;
}
