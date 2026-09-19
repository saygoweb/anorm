<?php
namespace Anorm\Tools;

use Anorm\Schema\Finding;

/**
 * What one `anorm schema:diff` run found: the findings per table, the models that
 * could not be used, the rendered report, and the exit code CI reads.
 */
class SchemaDiffResult
{
    /** @var array Table name => ['class' => string, 'findings' => Finding[]] */
    public $tables = array();

    /** @var array Class or file name => why it was not usable */
    public $skipped = array();

    /** @var string The rendered report */
    public $output = '';

    /** @var int */
    public $errors = 0;

    /** @var int */
    public $warnings = 0;

    /** @var int */
    public $infos = 0;

    /**
     * @param string $class Model class name
     * @param string $table Table the model maps to
     * @param Finding[] $findings
     * @return void
     */
    public function add($class, $table, array $findings)
    {
        // Worst first: the reader is looking for what cannot work, not for a catalogue.
        $order = array(Finding::ERROR => 0, Finding::WARNING => 1, Finding::INFO => 2);
        \usort($findings, function ($a, $b) use ($order) {
            if ($order[$a->severity] !== $order[$b->severity])
            {
                return $order[$a->severity] - $order[$b->severity];
            }
            return \strcmp((string) $a->column, (string) $b->column);
        });
        foreach ($findings as $finding)
        {
            if ($finding->severity === Finding::ERROR)
            {
                ++$this->errors;
            }
            elseif ($finding->severity === Finding::WARNING)
            {
                ++$this->warnings;
            }
            else
            {
                ++$this->infos;
            }
        }
        $this->tables[$table] = array('class' => $class, 'findings' => $findings);
    }

    /**
     * Every finding, from every table.
     * @return Finding[]
     */
    public function findings()
    {
        $all = array();
        foreach ($this->tables as $entry)
        {
            foreach ($entry['findings'] as $finding)
            {
                $all[] = $finding;
            }
        }
        return $all;
    }

    /**
     * Non-zero when the schema cannot hold what a model puts in it, so that a CI step
     * can run this and stop on it.
     * @return int
     */
    public function exitCode()
    {
        return $this->errors > 0 ? 1 : 0;
    }

    /**
     * @return string
     */
    public function summary()
    {
        return \sprintf(
            'Anorm schema:diff — %d table(s), %d error(s), %d warning(s), %d informational',
            \count($this->tables),
            $this->errors,
            $this->warnings,
            $this->infos
        );
    }
}
