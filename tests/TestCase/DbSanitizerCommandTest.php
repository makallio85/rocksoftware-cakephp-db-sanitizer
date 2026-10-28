<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer\Test\TestCase;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;
use RockSoftware\DbSanitizer\Command\DbSanitizerCommand;

class DbSanitizerCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use SchemaTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        putenv(DbSanitizerCommand::THROWAWAY_VAR);
        unset($_SERVER[DbSanitizerCommand::THROWAWAY_VAR], $_ENV[DbSanitizerCommand::THROWAWAY_VAR]);
        parent::tearDown();
    }

    public function testMaskRefusesOutsideAThrowawayCopy(): void
    {
        $this->exec('db_sanitizer mask');

        $this->assertExitError();
        $this->assertErrorContains('runs only in a throwaway copy');
        $this->assertSame('Alex Example', $this->rows('SELECT name FROM customers WHERE id = 1')[0]['name']);
    }

    public function testMaskRunsInAThrowawayCopy(): void
    {
        $_SERVER[DbSanitizerCommand::THROWAWAY_VAR] = 'yes';

        $this->exec('db_sanitizer mask');

        $this->assertExitSuccess();
        $this->assertOutputContains('Turned off: Send email');
        $this->assertNotSame('Alex Example', $this->rows('SELECT name FROM customers WHERE id = 1')[0]['name']);
    }

    public function testCheckReportsGaps(): void
    {
        $this->exec('db_sanitizer check');
        $this->assertExitSuccess();

        $this->connection()->execute('CREATE TABLE extra (id INTEGER PRIMARY KEY, label VARCHAR(10))');
        $this->exec('db_sanitizer check');
        $this->assertExitError();
        $this->assertErrorContains('Table "extra" has no declaration');
    }

    public function testVerifyRefusesARawDump(): void
    {
        $raw = TMP . 'raw-command.sql';
        file_put_contents($raw, "INSERT INTO `customers` VALUES (1);\n");

        $this->exec('db_sanitizer verify ' . $raw);

        $this->assertExitError();
        $this->assertErrorContains('is not a masked copy');
    }
}
