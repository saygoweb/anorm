<?php
namespace Anorm;

class Model
{
    /** @var DataMapper */
    public $_mapper;

    public function __construct(\PDO $pdo, DataMapper $mapper)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
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