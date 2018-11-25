<?php
namespace Anorm;

class Model
{
    /** @var DataMapper */
    public $_mapper;

    public function __construct(\PDO $pdo, DataMapper $mapper)
    {
        $this->_mapper = $mapper;
    }

    public function write()
    {
        $this->_mapper->write($this);
    }

    public function read($id)
    {
        return $this->_mapper->read($this, $id);
    }
    
}