<?php
namespace Anorm\Tools;

use Anorm\Model;

/**
 * Finds the models in a directory and instantiates them.
 *
 * A model is a class extending Anorm\Model whose constructor takes a PDO, which is
 * what `anorm make` writes. Anything that cannot be loaded or constructed is reported
 * as skipped, with the reason, rather than stopping the run: one model with an awkward
 * constructor should not cost you the diff of every other table.
 */
class ModelLocator
{
    /** @var Model[] Located models, keyed by class name */
    public $models = array();

    /** @var array Class or file name => why it was not usable */
    public $skipped = array();

    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Load every PHP file under $directory and instantiate the models it defines.
     *
     * @param string $directory Directory to search, recursively
     * @param string $namespace Only classes in this namespace are considered; '' for any
     * @return Model[] Located models, keyed by class name
     */
    public function locate($directory, $namespace = '')
    {
        if (!\is_dir($directory))
        {
            throw new \Exception("Models directory '$directory' does not exist");
        }
        foreach ($this->phpFiles($directory) as $file)
        {
            try
            {
                require_once($file);
            }
            catch (\Throwable $e)
            {
                $this->skipped[$file] = 'could not be loaded: ' . $e->getMessage();
            }
        }
        $namespace = \trim($namespace, '\\');
        foreach (\get_declared_classes() as $class)
        {
            if (!\is_subclass_of($class, Model::class))
            {
                continue;
            }
            if ($namespace !== '' && \strpos($class, $namespace . '\\') !== 0)
            {
                continue;
            }
            $this->instantiate($class);
        }
        return $this->models;
    }

    /**
     * @param string $class
     * @return void
     */
    private function instantiate($class)
    {
        if (isset($this->models[$class]) || isset($this->skipped[$class]))
        {
            return;
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract())
        {
            return;
        }
        try
        {
            $model = $reflection->newInstance($this->pdo);
        }
        catch (\Throwable $e)
        {
            // A model whose constructor wants more than a PDO is the consumer's
            // business, and the reason is more use to them than a stack trace.
            $this->skipped[$class] = 'could not be constructed: ' . $e->getMessage();
            return;
        }
        $this->models[$class] = $model;
    }

    /**
     * @param string $directory
     * @return string[] Absolute paths
     */
    private function phpFiles($directory)
    {
        $files = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file)
        {
            if ($file->isFile() && \strtolower($file->getExtension()) === 'php')
            {
                $files[] = $file->getPathname();
            }
        }
        \sort($files);
        return $files;
    }
}
