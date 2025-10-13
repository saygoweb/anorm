<?php

namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class PostModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'posts'; // Override table name to use plural

        // Fix the column mapping - ensure property names map to correct database columns
        $mapper->map = [
            'id' => 'id',
            'title' => 'title',
            'content' => 'content',
            'user_id' => 'user_id',
            'status' => 'status'
        ];

        parent::__construct($pdo, $mapper);

        // Define relationships with explicit property names
        $this->belongsTo('Anorm\Test\UserModel', 'user_id', 'id', 'user');
        $this->hasMany('Anorm\Test\CommentModel', 'post_id', 'id', 'comments');
        $this->hasManyThrough('Anorm\Test\TagModel', 'post_id', 'tag_id', 'post_tags', 'id', 'tags');
    }
    
    public $id;
    public $title;
    public $content;
    public $user_id;
    public $status;
    
    /** @var UserModel */
    public $user;
    
    /** @var CommentModel[] */
    public $comments;
    
    /** @var TagModel[] */
    public $tags;
}
