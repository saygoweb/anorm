<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Tools\ModelMaker;
use Anorm\Tools\ModelInfo;

class ModelMakerTest extends TestCase
{
    public static function tearDownAfterClass()
    {
        foreach (glob('/tmp/ModelMakerTest*') as $file)
        {
            if (is_file($file))
            {
                unlink($file);
            }
        }
    }

    public function testLowerCamelCase_TwoWords_OK()
    {
        $s = ModelMaker::lowerCamelCase('one_two');
        $this->assertEquals('oneTwo', $s);
    }

    public function testUpperCamelCase_TwoWords_OK()
    {
        $s = ModelMaker::upperCamelCase('one_two');
        $this->assertEquals('OneTwo', $s);
    }

    public function testFileName_OK()
    {
        $o = new ModelMaker(null, 'some_table');
        $actual = $o->fileName();
        $this->assertEquals('SomeTableModel.php', $actual);
    }

    public function testWriteModelAsString_OK()
    {
        $o = new ModelMaker(null, 'some_table');
        $this->assertEquals('some_table', $o->table);
        $o->modelInfo = new ModelInfo();
        $o->modelInfo->properties = array('fieldOne', 'fieldTwo');
        $actual = $o->writeModelAsString();
        $expected = <<<"EOD"
<?php
namespace App;

use Anorm\DataMapper;
use Anorm\Model;

class SomeTableModel extends Model
{
    public function __construct(\PDO \$pdo)
    {
        parent::__construct(\$pdo, DataMapper::createByClass(\$pdo, \$this));

    }

    // Properties
    /** @var string */
    public \$fieldOne;

    /** @var string */
    public \$fieldTwo;


}
EOD;
        $this->assertEquals($expected, $actual);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessageRegExp /File '[^']+' exists, and force overwrite options not set./
     */
    public function testWriteModelAsFile_FileExists_Throws()
    {
        $o = new ModelMaker(null, 'some_table');
        $this->assertEquals('some_table', $o->table);
        $o->modelInfo = new ModelInfo();
        $o->modelInfo->properties = array('fieldOne', 'fieldTwo');
        $tempFilePath = tempnam("/tmp", "ModelMakerTest");
        // It is already created and this will cause an exception to be thrown.
        $actual = $o->writeModelAsFile($tempFilePath);
    }
    
    /**
     * @expectedException \Exception
     */
    public function testWriteModelAsFile_BadPath_Throws()
    {
        $o = new ModelMaker(null, 'some_table');
        $actual = $o->writeModelAsFile('bogus_path/to_model');
    }

    public function testWriteModelAsFile_OK()
    {
        $o = new ModelMaker(null, 'some_table');
        $this->assertEquals('some_table', $o->table);
        $o->modelInfo = new ModelInfo();
        $o->modelInfo->properties = array('fieldOne', 'fieldTwo');
        $tempFilePath = tempnam("/tmp", "ModelMakerTest");
        // It is already created and this will cause an exception to be thrown.
        // So we delete the tempfile.
        unlink($tempFilePath);
        $actual = $o->writeModelAsFile($tempFilePath);
        $expected = <<<"EOD"
<?php
namespace App;

use Anorm\DataMapper;
use Anorm\Model;

class SomeTableModel extends Model
{
    public function __construct(\PDO \$pdo)
    {
        parent::__construct(\$pdo, DataMapper::createByClass(\$pdo, \$this));

    }

    // Properties
    /** @var string */
    public \$fieldOne;

    /** @var string */
    public \$fieldTwo;


}
EOD;
        $actual = \file_get_contents($tempFilePath);
        $this->assertEquals($expected, $actual);
        unlink($tempFilePath);
    }

    public function testWriteModelAsFile_WithForce_OK()
    {
        $o = new ModelMaker(null, 'some_table');
        $this->assertEquals('some_table', $o->table);
        $o->modelInfo = new ModelInfo();
        $o->modelInfo->properties = array('fieldOne', 'fieldTwo');
        $tempFilePath = tempnam("/tmp", "ModelMakerTest");
        // It is already created so use the force flag to overwrite
        $actual = $o->writeModelAsFile($tempFilePath, null, true);
        $expected = <<<"EOD"
<?php
namespace App;

use Anorm\DataMapper;
use Anorm\Model;

class SomeTableModel extends Model
{
    public function __construct(\PDO \$pdo)
    {
        parent::__construct(\$pdo, DataMapper::createByClass(\$pdo, \$this));

    }

    // Properties
    /** @var string */
    public \$fieldOne;

    /** @var string */
    public \$fieldTwo;


}
EOD;
        $actual = \file_get_contents($tempFilePath);
        $this->assertEquals($expected, $actual);
        unlink($tempFilePath);
    }

}
