#!/usr/bin/env php
<?php
namespace Anorm\Tools;

define('VERSION', '0.9.0');

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
        // `--option=value` is not understood by the lexer and is not rejected either,
        // so it has to be split before parsing. See CliOptions::splitAssignments.
        $argv = isset($_SERVER['argv']) ? array_slice($_SERVER['argv'], 1) : array();
        $arguments = new Arguments(array('strict' => false, 'input' => CliOptions::splitAssignments($argv)));
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
        $arguments->addOption('host', array(
            'default' => 'db',
            'description' => 'Database host'
        ));
        $arguments->addOption('format', array(
            'default' => 'text',
            'description' => 'Output format for schema:diff, text or json'
        ));
        $arguments->addFlag(array('all', 'a'), 'Include informational findings in schema:diff');
        $arguments->parse();
        $this->options = $arguments;
        $this->commandArgs = $arguments->getInvalidArguments();
        $c = count($this->commandArgs);
        if ($c >= 1) {
            $this->command = array_shift($this->commandArgs);
        }
    }

    /**
     * @return int The process exit code
     */
    public function run()
    {
        if ($this->options['help']) {
            $this->help();
            return 0;
        }
        if ($this->options['version']) {
            $this->version();
            return 0;
        }
        switch ($this->command) {
            case 'make':
                $this->make();
                break;

            case 'schema:diff':
                return $this->schemaDiff();

            default:
                echo $this->title();
                printf("Error: Unknown command '%s', try 'help'\n", $this->command);
                return 2;
        }
        return 0;
    }

    /**
     * Connect to $database, prompting for a password when asked to.
     * @return \PDO|null null when the connection failed, having said why
     */
    public function connect($database)
    {
        $password = '';
        if ($this->options['password']) {
            $password = \cli\prompt("Password", false, ':', true); // hide
        }
        $dsn = 'mysql:host=' . $this->options['host'] . ';dbname=' . $database;
        try {
            $pdo = new \PDO($dsn, $this->options['user'], $password);
        } catch (\PDOException $e) {
            echo 'Error: Database connection failed, ' . $e->getMessage() . PHP_EOL;
            return null;
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * Compare the live schema with what the models imply.
     * @return int An exit code: 1 when the schema cannot hold what a model writes
     */
    public function schemaDiff()
    {
        echo $this->title();
        if (count($this->commandArgs) < 1) {
            echo "Error: schema:diff command must have a database specified" . PHP_EOL;
            return 2;
        }
        $database = $this->commandArgs[0];
        $table = count($this->commandArgs) >= 2 ? $this->commandArgs[1] : '';

        $pdo = $this->connect($database);
        if ($pdo === null) {
            return 2;
        }

        $command = new SchemaDiffCommand($pdo);
        $command->format = $this->options['format'];
        $command->showInfo = (bool) $this->options['all'];
        try {
            $result = $command->run($this->options['models'], $this->options['namespace'], $table);
        } catch (\Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
            return 2;
        }
        echo $result->output;
        return $result->exitCode();
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

        $pdo = $this->connect($database);
        if ($pdo === null) {
            return;
        }
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

  schema:diff <database> [table]
    Compares the live schema with what the models in the Models folder imply, and
    reports every difference: columns typed as the wrong kind of thing, columns the
    model expects that are not there, and relationships with no foreign key
    constraint. Exits 1 when the schema cannot hold what a model writes, so it can
    be run in CI or before committing a dump.
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
exit((int) $app->run());
