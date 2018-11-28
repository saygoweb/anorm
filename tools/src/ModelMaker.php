<?php
namespace Anorm\Tools;

class ModelMaker {

    /** @var bool If true column names will be converted to camelCase property names */
    public $camelCase = true;

    /**
     * @var function Function of the form string: f(string)
     */
    public $propertyFunctor = 'Anorm\Tools\ModelMaker::lowerCamelCase';

    /** @var ModelInfo */
    public $modelInfo = null;

    /** @var ModelMakerOptions */
    public $options;

    /** @var \PDO */
    private $pdo;

    /** @var string */
    public $table;

    public function __construct(\PDO $pdo = null, $table, $options = null)
    {
        if ($pdo)
        {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        $this->pdo = $pdo;
        $this->table = $table;
        $this->options = $options ?: new ModelMakerOptions();
    }

    /**
     * @return ModelInfo
     * @throws \PDOException
     */
    public function modelInfoFromDatabase()
    {
        $sql = "SHOW COLUMNS FROM " . $this->table;
        $result = $this->pdo->query($sql);
        $modelInfo = new ModelInfo();
        while ($schema = $result->fetch(\PDO::FETCH_ASSOC))
        {
            $functor = $this->propertyFunctor;
            $propertyName = $functor($schema['Field']);
            $modelInfo->properties[] = $propertyName;
            if ($schema['Key'] === 'PRI')
            {
                $modelInfo->keyProperty = $propertyName;
            }
        }
        return $modelInfo;
    }

    public static function lowerCamelCase($s)
    {
        $s = preg_replace_callback('/_(.?)/', function ($item) {
            return strtoupper($item[1]);
        }, $s);
        return $s;
    }

    public static function upperCamelCase($s)
    {
        $s = preg_replace_callback('/(?:^|_)(.?)/', function ($item) {
            return strtoupper($item[1]);
        }, $s); 
        return $s;
    }

    public function fileName()
    {
        return $this->modelName() . ".php";
    }

    public function modelName()
    {
        return self::upperCamelCase($this->table) . $this->options->classSuffix;
    }

    public function writeModelAsString($exclude = array())
    {
        // Create modelInfo if needs be
        if (!$this->modelInfo)
        {
            $this->modelInfo = $this->modelInfoFromDatabase();
        }
        // Generate the model properties
        $properties = "";
        foreach ($this->modelInfo->properties as $property)
        {
            $properties .= "    /** @var string */\n";
            $properties .= "    public \$" . $property . ";\n";
            $properties .= "\n";
        }
        // Generate the model key
        $primaryProperty = '';
        if ($this->modelInfo->keyProperty && $this->modelInfo->keyProperty !== 'id')
        {
            $primaryProperty =  '        $this->_mapper->modelPrimaryKey = \'' . $this->modelInfo->keyProperty . '\';';
        }

        $namespace = $this->options->namespace;
        $modelName = $this->modelName();

        $content = <<<"EOD"
<?php
namespace $namespace;

use Anorm\DataMapper;
use Anorm\Model;

class $modelName extends Model
{
    public function __constructor(\\PDO \$pdo)
    {
        parent::__construct(\$pdo, DataMapper::createByClass(\$pdo, \$this));
$primaryProperty
    }

    // Properties
$properties
}
EOD;
        return $content;
    }

    public function writeModelAsFile($filePath, $exclude = array(), $force = false)
    {
        if (\file_exists($filePath) && !$force)
        {
            throw new \Exception("File '$filePath' exists, and force overwrite options not set.");
        }
        $file = @fopen($filePath, 'w');
        if ($file === false)
        {
            throw new \Exception("Could not open $filePath for writing");
        }
        $content = $this->writeModelAsString($exclude);
        \fwrite($file, $content);
        \fclose($file);
    }

}