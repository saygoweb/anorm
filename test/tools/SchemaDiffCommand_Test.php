<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Test\TestEnvironment;
use Anorm\Tools\ModelLocator;
use Anorm\Tools\SchemaDiffCommand;

/**
 * `anorm schema:diff` over a directory of models: what it finds, what it prints, and
 * what it exits with.
 *
 * @see https://github.com/saygoweb/anorm/issues/61
 */
class SchemaDiffCommandTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var string */
    private $modelsDirectory;

    /** @var string */
    private $namespace = 'Anorm\Test\Fixtures\Models';

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
    }

    public function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        $this->modelsDirectory = __DIR__ . '/fixtures/models';
        $this->dropTables();
        $this->pdo->exec('CREATE TABLE cli_clients (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(128) NULL
        ) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE cli_hostings (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(128) NULL,
            loose VARCHAR(128) NULL
        ) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE cli_orders (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            client_id INT(11) NULL
        ) ENGINE=InnoDB');
    }

    public function tearDown(): void
    {
        $this->dropTables();
    }

    public function testLocator_FindsModelsAndSaysWhyItSkippedTheRest()
    {
        $locator = new ModelLocator($this->pdo);
        $models = $locator->locate($this->modelsDirectory, $this->namespace);

        $this->assertArrayHasKey($this->namespace . '\CliClientModel', $models);
        $this->assertArrayHasKey($this->namespace . '\CliHostingModel', $models);
        $this->assertArrayNotHasKey($this->namespace . '\CliNotAModel', $models, 'not a Model');

        $skipped = $locator->skipped;
        $this->assertArrayHasKey($this->namespace . '\CliAwkwardModel', $skipped);
        $this->assertStringContainsString('could not be constructed', $skipped[$this->namespace . '\CliAwkwardModel']);
    }

    public function testLocating_IsIdempotentAndSkipsAbstractBases()
    {
        $locator = new ModelLocator($this->pdo);
        $locator->locate($this->modelsDirectory, $this->namespace);
        $models = $locator->locate($this->modelsDirectory, $this->namespace);

        $this->assertArrayHasKey($this->namespace . '\CliClientModel', $models);
        $this->assertArrayNotHasKey($this->namespace . '\CliAbstractModel', $models, 'an abstract base is not a model');
        $this->assertArrayNotHasKey($this->namespace . '\CliAbstractModel', $locator->skipped, 'nor is it a problem');
    }

    public function testAFileThatCannotBeLoaded_IsSkippedRatherThanFatal()
    {
        $locator = new ModelLocator($this->pdo);
        $locator->locate(__DIR__ . '/fixtures/broken', 'Anorm\Test\Fixtures\Broken');

        $this->assertCount(1, $locator->skipped);
        $reason = reset($locator->skipped);
        $this->assertStringContainsString('could not be loaded', $reason);
        $this->assertStringContainsString('cannot be loaded', $reason, 'the file says why');
    }

    public function testADirectoryWithNoModels_SaysSo()
    {
        $result = $this->command()->run(__DIR__ . '/fixtures/none', 'Anorm\Test\Fixtures\None');

        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('No models found', $result->output);
    }

    public function testRun_ReportsTheMistypedColumnAndExitsNonZero()
    {
        $result = $this->command()->run($this->modelsDirectory, $this->namespace);

        $this->assertSame(1, $result->exitCode(), 'an unusable column is a failure');
        $this->assertGreaterThanOrEqual(1, $result->errors);
        $this->assertStringContainsString('cli_hostings', $result->output);
        $this->assertStringContainsString('client_id is VARCHAR(128), model implies INT(11) NULL', $result->output);
        $this->assertStringContainsString('error', $result->output);
    }

    public function testRun_PutsTheWorstFirst()
    {
        $result = $this->command()->run($this->modelsDirectory, $this->namespace, 'cli_hostings');
        $findings = $result->tables['cli_hostings']['findings'];

        $this->assertSame('error', $findings[0]->severity);
    }

    public function testTableArgument_NarrowsTheRun()
    {
        $result = $this->command()->run($this->modelsDirectory, $this->namespace, 'cli_orders');

        $this->assertSame(['cli_orders'], array_keys($result->tables));
    }

    public function testOnlyWarnings_ExitZero()
    {
        // cli_orders has the right column type and no constraint: worth saying, not
        // worth failing a build over.
        $result = $this->command()->run($this->modelsDirectory, $this->namespace, 'cli_orders');

        $this->assertSame(0, $result->exitCode());
        $this->assertSame(1, $result->warnings);
        $this->assertStringContainsString('no foreign key constraint', $result->output);
    }

    public function testCleanTable_SaysOk()
    {
        $result = $this->command()->run($this->modelsDirectory, $this->namespace, 'cli_clients');

        $this->assertSame(0, $result->exitCode());
        $this->assertStringContainsString('ok', $result->output);
    }

    public function testInfoFindings_AreHiddenUntilAskedFor()
    {
        $quiet = $this->command()->run($this->modelsDirectory, $this->namespace, 'cli_hostings');
        $this->assertStringContainsString('hidden; --all shows them', $quiet->output);
        $this->assertStringNotContainsString('says nothing', $quiet->output);

        $command = $this->command();
        $command->showInfo = true;
        $loud = $command->run($this->modelsDirectory, $this->namespace, 'cli_hostings');
        $this->assertStringContainsString('says nothing', $loud->output, 'the uninformative column is listed');
    }

    public function testJsonFormat_IsMachineReadable()
    {
        $command = $this->command();
        $command->format = 'json';
        $command->showInfo = true;
        $result = $command->run($this->modelsDirectory, $this->namespace, 'cli_hostings');

        $payload = json_decode($result->output, true);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['summary']['tables']);
        $this->assertGreaterThanOrEqual(1, $payload['summary']['errors']);
        $kinds = array_column($payload['findings'], 'kind');
        $this->assertContains('type_mismatch', $kinds);
        $this->assertSame($this->namespace . '\CliHostingModel', $payload['findings'][0]['model']);
    }

    public function testASkippedModel_IsReportedRatherThanLost()
    {
        $result = $this->command()->run($this->modelsDirectory, $this->namespace);

        $this->assertStringContainsString('skipped', $result->output);
        $this->assertStringContainsString('CliAwkwardModel', $result->output);
    }

    public function testAMissingModelsDirectory_Throws()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("does not exist");

        $this->command()->run(__DIR__ . '/no/such/place', $this->namespace);
    }

    private function command()
    {
        return new SchemaDiffCommand($this->pdo);
    }

    private function dropTables()
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['cli_hostings', 'cli_orders', 'cli_clients'] as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS $table");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
