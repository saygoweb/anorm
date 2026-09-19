<?php
namespace Anorm\Tools;

use Anorm\Schema\Finding;
use Anorm\Schema\SchemaDiff;
use Anorm\Schema\SchemaInspector;

/**
 * The `anorm schema:diff` command: locate the models, compare each one's table with
 * what it implies, and report.
 *
 * The report is for the human doing the dump-and-correct step, so it is ordered the
 * way they read it — worst first, table by table — and hides the findings that only
 * say "nothing to compare" unless asked. The exit code is for CI: non-zero when
 * something in the schema cannot hold what a model puts in it.
 */
class SchemaDiffCommand
{
    /** @var string 'text' or 'json' */
    public $format = 'text';

    /** @var bool Show info-level findings as well as errors and warnings */
    public $showInfo = false;

    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param string $modelsDirectory Directory holding the model classes
     * @param string $namespace Namespace the models are in, '' for any
     * @param string $table Only this table, or '' for all of them
     * @return SchemaDiffResult
     */
    public function run($modelsDirectory, $namespace = '', $table = '')
    {
        $locator = new ModelLocator($this->pdo);
        $models = $locator->locate($modelsDirectory, $namespace);
        $diff = new SchemaDiff(new SchemaInspector($this->pdo), $models);

        $result = new SchemaDiffResult();
        $result->skipped = $locator->skipped;
        foreach ($models as $class => $model)
        {
            $modelTable = $model->_mapper->table;
            if ($table !== '' && $modelTable !== $table)
            {
                continue;
            }
            $result->add($class, $modelTable, $diff->forModel($model));
        }
        $result->output = $this->format === 'json' ? $this->json($result) : $this->text($result);
        return $result;
    }

    /**
     * @param SchemaDiffResult $result
     * @return string
     */
    private function text(SchemaDiffResult $result)
    {
        $lines = array();
        $lines[] = $result->summary();
        $lines[] = '';
        if (!$result->tables)
        {
            $lines[] = 'No models found. Check --models and --namespace.';
        }
        $hiddenInfo = 0;
        foreach ($result->tables as $table => $entry)
        {
            $shown = array();
            foreach ($entry['findings'] as $finding)
            {
                if ($finding->severity === Finding::INFO && !$this->showInfo)
                {
                    ++$hiddenInfo;
                    continue;
                }
                // The table is already the heading, so each line names its column only.
                $where = $finding->column === null ? '' : $finding->column . ' ';
                $shown[] = '  ' . \str_pad($finding->severity, 8) . $where . $finding->message;
            }
            $lines[] = $table . '  (' . $entry['class'] . ')';
            $lines[] = $shown ? \implode(PHP_EOL, $shown) : '  ok';
            $lines[] = '';
        }
        if ($hiddenInfo)
        {
            $lines[] = $hiddenInfo . ' informational finding(s) hidden; --all shows them.';
        }
        foreach ($result->skipped as $what => $why)
        {
            $lines[] = 'skipped  ' . $what . ': ' . $why;
        }
        return \implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param SchemaDiffResult $result
     * @return string
     */
    private function json(SchemaDiffResult $result)
    {
        $findings = array();
        foreach ($result->tables as $table => $entry)
        {
            foreach ($entry['findings'] as $finding)
            {
                $row = $finding->toArray();
                $row['model'] = $entry['class'];
                $findings[] = $row;
            }
        }
        $payload = array(
            'summary' => array(
                'tables' => \count($result->tables),
                'errors' => $result->errors,
                'warnings' => $result->warnings,
                'info' => $result->infos,
            ),
            'findings' => $findings,
            'skipped' => $result->skipped,
        );
        return \json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
