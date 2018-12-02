#!/usr/bin/env php
<?php
namespace Anorm\Tools;

define('VERSION', '0.2.1');

// Try 3rd party install relative to bin folder
if (\file_exists(__DIR__ . '/../../../autoload.php')) {
    require_once(__DIR__ . '/../../../autoload.php');
// Try dev install relative to the bin folder
} elseif (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once(__DIR__ . '/../vendor/autoload.php');
} else {
    echo "Anorm Error: Failed to load vendor/autoload.php" . PHP_EOL;
}

use \cli\Arguments;

define('HEADER', 'Anorm: Yet Another ORM CLI');

class App
{

    /** @var string */
    public $command;

    /** @var array */
    public $commandArgs;

    /** @var array */
    public $options;

    public function __construct()
    {
        $arguments = new Arguments(array('strict' => false));
        $arguments->addFlag(array('help', 'h'), 'Display this help');
        $arguments->addFlag('version', 'Display the version');
        $arguments->addFlag(array('force', 'f'), 'Force overwrite of files');
        $arguments->addFlag(array('password', 'p'), 'Prompt for the database password');
        $arguments->addOption(array('user', 'u'), array(
            'default' => '',
            'description' => 'Database username'
        ));
        $arguments->addOption(array('classsuffix', 'c'), array(
            'default' => 'Model',
            'description' => 'Suffix for generated class names'
        ));
        $arguments->addOption(array('models', 'm'), array(
            'default' => 'src/Models/',
            'description' => 'Models folder'
        ));
        $arguments->addOption(array('namespace', 'n'), array(
            'default' => 'App\Models',
            'description' => 'Namespace for generated models'
        ));
        $arguments->parse();
        $this->options = $arguments;
        $this->commandArgs = $arguments->getInvalidArguments();
        $c = count($this->commandArgs);
        if ($c >= 1) {
            $this->command = array_shift($this->commandArgs);
        }
    }

    public function run()
    {
        if ($this->options['help']) {
            $this->help();
            return;
        }
        if ($this->options['version']) {
            $this->version();
            return;
        }
        switch ($this->command) {
            case 'make':
                $this->make();
                break;

            default:
                echo $this->title();
                printf("Error: Unknown command '%s', try 'help'\n", $this->command);
        }
    }

    public function make()
    {
        echo $this->title();
        // var_dump($this->commandArgs);
        $database;
        $table = '';
        if (count($this->commandArgs) >= 2) {
            $table = $this->commandArgs[1];
        }
        if (count($this->commandArgs) >= 1) {
            $database = $this->commandArgs[0];
        } else {
            echo "Error: make command must have a database specified" . PHP_EOL;
            return;
        }

        $password = '';
        if ($this->options['password']) {
            $password = \cli\prompt("Password", false, ':', true); // hide
        }
        try {
            $pdo = new \PDO('mysql:host=localhost;dbname=' . $this->commandArgs[0], $this->options['user'], $password);
        } catch (\PDOException $e) {
            echo 'Error: Database connection failed, ' . $e->getMessage() . PHP_EOL;
            return;
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $tables = array($table);
        foreach ($tables as $table) {
            $modelMakerOptions = new ModelMakerOptions();
            $modelMakerOptions->classSuffix = $this->options['classsuffix'];
            $modelMakerOptions->namespace = $this->options['namespace'];
            $modelMaker = new \Anorm\Tools\ModelMaker($pdo, $table, $modelMakerOptions);
            $filePath = $this->options['models'] . $modelMaker->fileName();
            echo "Making model for $table in $filePath" . PHP_EOL;
            $modelMaker->writeModelAsFile($filePath, array(), $this->options['force']);
        }
    }

    public function help()
    {
        echo $this->title();
        echo <<<'EOD'
Commands
  make <database> [table]
    Makes models for the given database table in the Models folder with Namespace.
EOD;
        echo PHP_EOL;
        echo $this->options->getHelpScreen();
        echo PHP_EOL;
    }

    public function title()
    {
        return HEADER . " v" . VERSION . PHP_EOL;
    }

    public function version()
    {
        echo HEADER . PHP_EOL;
        echo 'Version: ' . VERSION . PHP_EOL;
    }
}

$app = new App();
$app->run();
