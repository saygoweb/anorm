<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Schema\Finding;
use Anorm\Schema\SchemaDiff;
use Anorm\Schema\SchemaInspector;
use Anorm\Test\TestEnvironment;

/**
 * The live schema against what the models imply. Every table here is deliberately
 * wrong in one way, because a diff that only agrees with itself proves nothing.
 *
 * @see https://github.com/saygoweb/anorm/issues/61
 */

class DiffClientModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'diff_clients';
        parent::__construct($pdo, $mapper);
        $this->hasMany('DiffHostingModel', 'clientId', 'id', 'hostings');
    }

    public $id;
    public ?string $name = null;
    public $tier = 3;
}

/**
 * The hasMany is declared without a property, as the relationship API allows, so it
 * stays out of the column map and the model is still clean.
 */

class DiffHostingModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'diff_hostings';
        $mapper->columnDefinitions = ['traffic_bytes' => 'BIGINT(20) NULL'];
        parent::__construct($pdo, $mapper);
        $this->belongsTo('DiffClientModel', 'clientId', 'id', 'client');
    }

    public $id;
    public ?int $clientId = null;
    public $trafficBytes;
    public ?string $note = null;
    public ?int $resellerId = null;
    public ?int $flags = null;
    public $summary = 'a summary long enough that the guess widens the column past VARCHAR(128), which the live column is not';
}

class DiffOrderModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'diff_orders';
        parent::__construct($pdo, $mapper);
        $this->belongsTo('DiffClientModel', 'clientId', 'id', 'client');
    }

    public $id;
    public ?int $clientId = null;
}

class DiffEventModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'diff_events';
        $mapper->infrastructureProperties = ['dtc'];
        parent::__construct($pdo, $mapper);
    }

    public $id;
    /** @var ?string */
    public $occurredAt;
    public $lastError = 'registrar refused the transfer on 2026-09-19';
    public $dtc;
    public $loose;
}

/**
 * The #67 story: timestamps that a null first sample typed VARCHAR(128), on a model
 * that declares them `string` — which is true, and is why nothing reports them.
 */

class DiffAuditModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'diff_audits';
        $mapper->columnDefinitions = ['reviewed_at' => 'VARCHAR(32) NULL'];
        parent::__construct($pdo, $mapper);
    }

    public $id;
    /** @var ?string */
    public $lastSyncedAt = null;
    /** @var ?string */
    public $createdAt = null;
    public $dtu;
    /** @var ?string */
    public $reviewedAt = null;
    /** @var ?string */
    public $note = null;
}

class DiffGhostModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'diff_ghosts';
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public ?int $ownerId = null;
}

class SchemaDiff_Test extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var SchemaInspector */
    private $inspector;

    /** @var SchemaDiff */
    private $diff;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
    }

    public function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        $this->dropTables();
        $this->pdo->exec('CREATE TABLE diff_clients (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(128) NULL,
            tier INT(11) NULL
        ) ENGINE=InnoDB');
        // client_id is the #61 story: a lookup typed it before the first write.
        $this->pdo->exec('CREATE TABLE diff_hostings (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(128) NULL,
            traffic_bytes INT(11) NULL,
            note VARCHAR(64) NULL,
            flags TINYINT(1) NULL,
            summary VARCHAR(64) NULL,
            legacy_flag VARCHAR(10) NULL
        ) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE diff_audits (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            last_synced_at VARCHAR(128) NULL,
            created_at VARCHAR(128) NULL,
            dtu VARCHAR(128) NULL,
            reviewed_at VARCHAR(32) NULL,
            note VARCHAR(128) NULL
        ) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE diff_orders (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            client_id INT(11) NULL
        ) ENGINE=InnoDB');
        // last_error is #58: one message containing a date typed the column DATETIME.
        $this->pdo->exec('CREATE TABLE diff_events (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            occurred_at DATETIME NULL,
            last_error DATETIME NULL,
            dtc DATETIME NULL,
            loose VARCHAR(128) NULL
        ) ENGINE=InnoDB');

        $this->inspector = new SchemaInspector($this->pdo);
        $this->diff = new SchemaDiff($this->inspector, ['DiffClientModel' => new DiffClientModel($this->pdo)]);
    }

    public function tearDown(): void
    {
        $this->dropTables();
    }

    public function testAColumnTypedAsTheWrongKindOfThing_IsAnError()
    {
        $finding = $this->findingFor(new DiffHostingModel($this->pdo), 'client_id', 'type_mismatch');

        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString('VARCHAR(128)', $finding->message);
        $this->assertStringContainsString('INT(11)', $finding->message);
        $this->assertSame('diff_hostings.client_id — is VARCHAR(128), model implies INT(11) NULL', $finding->describe());
    }

    public function testAPinnedColumnWiderThanTheLiveOne_IsAnError()
    {
        $finding = $this->findingFor(new DiffHostingModel($this->pdo), 'traffic_bytes', 'type_mismatch');

        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString('too narrow', $finding->message);
    }

    public function testADeclaredStringAgainstANarrowerColumn_IsNotReported()
    {
        // `?string` asks for a string, not for 128 characters. VARCHAR(128) is the
        // guess's default, and reporting it as drift would bury the real findings.
        $this->assertNull($this->findFor($this->findings(new DiffHostingModel($this->pdo)), 'note'));
    }

    public function testAValueTheModelWritesThatWouldNotFit_IsAWarning()
    {
        // The sample is over 128 characters, so the guess widens to VARCHAR(256) and
        // the live VARCHAR(64) would truncate it. That width the model does assert.
        $finding = $this->findingFor(new DiffHostingModel($this->pdo), 'summary', 'type_mismatch');

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('would not fit', $finding->message);
    }

    public function testACompatibleButDifferentKindOfColumn_IsAWarningNotAnError()
    {
        // An INT property in a TINYINT(1) column works until it does not, which is a
        // difference worth a human's eye rather than a failed build.
        $finding = $this->findingFor(new DiffHostingModel($this->pdo), 'flags', 'type_mismatch');

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('TINYINT(1)', $finding->message);
        $this->assertStringContainsString('holds small numbers', $finding->message);
    }

    public function testAHasMany_IsNotAForeignKeyOnThisTable()
    {
        // The constraint for a hasMany belongs on the *other* table, so it is not a
        // missing foreign key here. DiffClientModel declares one and stays clean.
        $findings = $this->findings(new DiffClientModel($this->pdo));

        $this->assertSame([], $findings);
    }

    public function testAMappedColumnThatDoesNotExist_IsAnError()
    {
        $finding = $this->findingFor(new DiffHostingModel($this->pdo), 'reseller_id', 'missing_column');

        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString('INT(11) NULL', $finding->message);
    }

    public function testAColumnTheModelDoesNotKnowAbout_IsInfo()
    {
        $finding = $this->findingFor(new DiffHostingModel($this->pdo), 'legacy_flag', 'extra_column');

        $this->assertSame(Finding::INFO, $finding->severity);
    }

    public function testAModelThatMatchesItsTable_HasNothingToSay()
    {
        // `name` matches by declaration and `tier` by the value it holds: two
        // different routes to the same silence.
        $this->assertSame([], $this->findings(new DiffClientModel($this->pdo)));
    }

    public function testAMissingTable_IsOneError()
    {
        $findings = $this->findings(new DiffGhostModel($this->pdo));

        $this->assertCount(1, $findings, 'no point listing every column of a table that is not there');
        $this->assertSame('missing_table', $findings[0]->kind);
        $this->assertSame(Finding::ERROR, $findings[0]->severity);
    }

    public function testAForeignKeyColumnThatCannotTakeAConstraint_IsAnError()
    {
        $finding = $this->findingFor(new DiffHostingModel($this->pdo), 'client_id', 'foreign_key_type_mismatch');

        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString('`diff_clients`.`id`', $finding->message);
        $this->assertStringContainsString('INT(11)', $finding->message);
    }

    public function testADeclaredRelationshipWithNoConstraint_IsAWarning()
    {
        $finding = $this->findingFor(new DiffOrderModel($this->pdo), 'client_id', 'missing_foreign_key');

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('`client`', $finding->message);
        // The name the writer would create, which is spelled with the column. Naming
        // the property here would send the reader looking for a constraint that no
        // version of Anorm has ever created.
        $this->assertStringContainsString('fk_diff_orders_client_id', $finding->message);
    }

    public function testAConstraintThatExists_IsNotReported()
    {
        $this->pdo->exec('ALTER TABLE diff_orders ADD CONSTRAINT fk_diff_orders_client_id
            FOREIGN KEY (client_id) REFERENCES diff_clients(id)');
        $this->inspector->clearCache();

        $this->assertSame([], $this->findings(new DiffOrderModel($this->pdo)));
    }

    public function testAnUnknownRelatedModel_IsReportedAsUnchecked()
    {
        // Nothing is guessed about a model the caller did not hand over.
        $diff = new SchemaDiff($this->inspector);
        $finding = $this->findFor($diff->forModel(new DiffOrderModel($this->pdo)), 'client_id');

        $this->assertSame('foreign_key_unchecked', $finding->kind);
        $this->assertSame(Finding::INFO, $finding->severity);
    }

    public function testADeclaredStringAgainstADateColumn_IsNotReported()
    {
        // Everything PDO returns is a string, and `anorm make` documents a DATETIME
        // column as `?string`. Reporting that would fire on every date column there is.
        $this->assertNull($this->findFor($this->findings(new DiffEventModel($this->pdo)), 'occurred_at'));
    }

    public function testTextTheModelActuallyWritesIntoADateColumn_IsAWarning()
    {
        $finding = $this->findingFor(new DiffEventModel($this->pdo), 'last_error', 'type_mismatch');

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('not a date will be rejected', $finding->message);
    }

    public function testInfrastructureProperties_AreNotReported()
    {
        $this->assertNull($this->findFor($this->findings(new DiffEventModel($this->pdo)), 'dtc'));
    }

    public function testAColumnTheModelSaysNothingAbout_IsInfo()
    {
        $finding = $this->findingFor(new DiffEventModel($this->pdo), 'loose', 'no_information');

        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertStringContainsString('says nothing', $finding->message);
    }

    public function testThePrimaryKey_IsNotReported()
    {
        // `public $id` declares nothing and holds nothing, but the column is Anorm's
        // own and there is no useful thing to say about it.
        $this->assertNull($this->findFor($this->findings(new DiffClientModel($this->pdo)), 'id'));
    }

    /**
     * @param Model $model
     * @return Finding[]
     */
    private function findings(Model $model)
    {
        return $this->diff->forModel($model);
    }

    private function findingFor(Model $model, $column, $kind)
    {
        foreach ($this->findings($model) as $finding) {
            if ($finding->column === $column && $finding->kind === $kind) {
                return $finding;
            }
        }
        $this->fail("No '$kind' finding for column '$column'");
    }

    private function findFor(array $findings, $column)
    {
        foreach ($findings as $finding) {
            if ($finding->column === $column) {
                return $finding;
            }
        }
        return null;
    }

    // #67: a timestamp that a null first sample typed VARCHAR, on a model that
    // honestly declares `string`. Nothing else in the diff can see these, because the
    // declaration and the column agree.

    public function testATextColumnNamedAsADate_IsReported()
    {
        $finding = $this->findingFor(new DiffAuditModel($this->pdo), 'last_synced_at', 'temporal_column_as_text');

        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertStringContainsString('VARCHAR(128)', $finding->message);
        $this->assertStringContainsString('sorts', $finding->message);
    }

    public function testATextColumnNamedAsADate_SaysWhatWouldSettleIt()
    {
        // The finding is the on-ramp to the transformer, so it should name it. A
        // reader who has to go and work out the remedy often does not.
        $finding = $this->findingFor(new DiffAuditModel($this->pdo), 'last_synced_at', 'temporal_column_as_text');

        $this->assertStringContainsString('transformer', $finding->message);
    }

    public function testATextColumnNamedCreatedAt_IsReported()
    {
        $finding = $this->findingFor(new DiffAuditModel($this->pdo), 'created_at', 'temporal_column_as_text');

        $this->assertSame(Finding::INFO, $finding->severity);
    }

    public function testAnAnormInfrastructureTimestampColumn_IsReported()
    {
        $finding = $this->findingFor(new DiffAuditModel($this->pdo), 'dtu', 'temporal_column_as_text');

        $this->assertSame(Finding::INFO, $finding->severity);
    }

    public function testAPinnedTextColumnNamedAsADate_IsNotReported()
    {
        // The author has said outright what the column is. A guess from its name does
        // not get to argue with that.
        $this->assertNull($this->findingOrNull(new DiffAuditModel($this->pdo), 'reviewed_at', 'temporal_column_as_text'));
    }

    public function testATextColumnNotNamedAsADate_IsNotReported()
    {
        $this->assertNull($this->findingOrNull(new DiffAuditModel($this->pdo), 'note', 'temporal_column_as_text'));
    }

    public function testADateColumnNamedAsADate_IsNotReported()
    {
        // diff_events.occurred_at is a real DATETIME. There is nothing to say.
        $this->assertNull($this->findingOrNull(new DiffEventModel($this->pdo), 'occurred_at', 'temporal_column_as_text'));
    }

    private function findingOrNull(Model $model, $column, $kind)
    {
        foreach ($this->findings($model) as $finding) {
            if ($finding->column === $column && $finding->kind === $kind) {
                return $finding;
            }
        }
        return null;
    }

    private function dropTables()
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['diff_hostings', 'diff_orders', 'diff_events', 'diff_audits', 'diff_clients'] as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS $table");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
