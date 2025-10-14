<?php

namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class CommentModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'comments'; // Override table name to use plural

        // Fix the column mapping
        $mapper->map = [
            'id' => 'id',
            'content' => 'content',
            'user_id' => 'user_id',
            'post_id' => 'post_id'
        ];

        parent::__construct($pdo, $mapper);

        // Define relationships with explicit property names
        $this->belongsTo('Anorm\Test\UserModel', 'user_id', 'id', 'user');
        $this->belongsTo('Anorm\Test\PostModel', 'post_id', 'id', 'post');
    }

    public $id;
    public $content;
    public $user_id;
    public $post_id;

    /** @var UserModel */
    public $user;

    /** @var PostModel */
    public $post;
}
