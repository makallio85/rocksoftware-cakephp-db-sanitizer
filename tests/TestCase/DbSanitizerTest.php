<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer\Test\TestCase;

use Cake\TestSuite\TestCase;
use RockSoftware\DbSanitizer\DbSanitizer;
use RockSoftware\DbSanitizer\Faker\DeterministicFaker;
use RockSoftware\DbSanitizer\Rule;
use RockSoftware\DbSanitizer\SanitizerPlan;
use RockSoftware\DbSanitizer\TestSuite\DeclarationsCoverageTrait;
use RuntimeException;

class DbSanitizerTest extends TestCase
{
    use DeclarationsCoverageTrait;
    use SchemaTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    public function testTheDeclarationsCoverTheSchema(): void
    {
        $this->assertSanitizerDeclarationsCoverSchema();
    }

    public function testAnUndeclaredTableOrTextColumnIsAGap(): void
    {
        $this->connection()->execute('CREATE TABLE extra (id INTEGER PRIMARY KEY, label VARCHAR(10))');
        $this->connection()->execute('ALTER TABLE customers ADD COLUMN nickname VARCHAR(30)');
        $this->connection()->execute('ALTER TABLE customers ADD COLUMN visits INTEGER');

        $gaps = (new DbSanitizer())->coverageGaps($this->connection());

        $this->assertContains('Table "extra" has no declaration: keep, empty or mask it.', $gaps);
        $this->assertContains('Column "customers.nickname" (string) has no rule.', $gaps);
        $this->assertNotContains('Column "customers.visits" (integer) has no rule.', $gaps, 'Numbers are kept.');
    }

    public function testAStaleDeclarationIsAGap(): void
    {
        $plan = DbSanitizer::planFromConfig();
        $plan->table('customers')->column('gone', Rule::null());
        $plan->keepTables('vanished');

        $gaps = (new DbSanitizer($plan))->coverageGaps($this->connection());

        $this->assertContains('Column "customers.gone" is declared but does not exist.', $gaps);
        $this->assertContains('Table "vanished" is declared but does not exist.', $gaps);
    }

    public function testMaskReplacesPersonalDataAndKeepsTheRest(): void
    {
        (new DbSanitizer())->mask($this->connection());

        $alex = $this->rows('SELECT * FROM customers WHERE id = 1')[0];
        $this->assertNotSame('Alex Example', $alex['name']);
        $this->assertMatchesRegularExpression('/^\S+ \S+$/', (string)$alex['name']);
        $this->assertStringEndsWith('@example.test', (string)$alex['email']);
        $this->assertMatchesRegularExpression('/^\+35840\d{7}$/', (string)$alex['phone']);
        $this->assertNotSame('Testikatu 1', $alex['street']);
        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d{3}$/', (string)$alex['plate_number'], 'Same shape.');
        $this->assertNull($alex['api_key'], 'Credentials are removed.');
        $this->assertSame('C-1', $alex['customer_number'], 'Kept.');

        $sam = $this->rows('SELECT * FROM customers WHERE id = 2')[0];
        $this->assertNull($sam['phone'], 'No data is invented where there was none.');
        $this->assertSame('', $sam['street']);

        $order = $this->rows('SELECT * FROM orders WHERE id = 1')[0];
        $this->assertSame($alex['email'], $order['customer_email'], 'The same original gives the same fake everywhere.');
        $this->assertNull($order['internal_note']);
        $this->assertSame('open', $order['status']);

        $this->assertSame([], $this->rows('SELECT * FROM sessions'));
        $this->assertSame('Private', $this->rows('SELECT label FROM customer_types')[0]['label']);
        $this->assertSame('0', (string)$this->rows('SELECT value FROM app_settings')[0]['value'], 'Outbound is off.');
        $this->assertSame('mask', $this->rows('SELECT tier FROM db_sanitizer_runs')[0]['tier']);
    }

    public function testMaskingTwiceGivesTheSameFakes(): void
    {
        $faker = new DeterministicFaker('key');
        $this->assertSame(
            $faker->fake(Rule::FULL_NAME, 'Alex Example'),
            (new DeterministicFaker('key'))->fake(Rule::FULL_NAME, 'Alex Example'),
        );
        $this->assertNotSame(
            $faker->fake(Rule::EMAIL, 'alex@example.org'),
            (new DeterministicFaker('other key'))->fake(Rule::EMAIL, 'alex@example.org'),
            'Without the key, a fake cannot be matched to an original.',
        );
    }

    public function testMaskRefusesWhenTheDeclarationsHaveGaps(): void
    {
        $this->connection()->execute('CREATE TABLE extra (id INTEGER PRIMARY KEY, label VARCHAR(10))');

        try {
            (new DbSanitizer())->mask($this->connection());
            $this->fail('Masked with an undeclared table.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Table "extra" has no declaration', $e->getMessage());
        }
        $this->assertSame('Alex Example', $this->rows('SELECT name FROM customers WHERE id = 1')[0]['name']);
    }

    public function testRehearseKeepsTheDataAndRemovesCredentials(): void
    {
        (new DbSanitizer())->rehearse($this->connection());

        $alex = $this->rows('SELECT * FROM customers WHERE id = 1')[0];
        $this->assertSame('Alex Example', $alex['name']);
        $this->assertSame('alex@example.org', $alex['email']);
        $this->assertNull($alex['api_key']);
        $this->assertSame('0', (string)$this->rows('SELECT value FROM app_settings')[0]['value']);
        $this->assertSame('rehearse', $this->rows('SELECT tier FROM db_sanitizer_runs')[0]['tier']);
    }

    public function testOnlyADumpWithAMaskMarkerCountsAsMasked(): void
    {
        $masked = TMP . 'masked.sql';
        file_put_contents($masked, "INSERT INTO `customers` VALUES (1);\nINSERT INTO `db_sanitizer_runs` VALUES (1,'mask','2026-10-28 10:00:00','2026-10-28 10:00:00');\n");
        $raw = TMP . 'raw.sql';
        file_put_contents($raw, "INSERT INTO `customers` VALUES (1);\nINSERT INTO `db_sanitizer_runs` VALUES (1,'rehearse','2026-10-28 10:00:00','2026-10-28 10:00:00');\n");
        $gzipped = TMP . 'masked.sql.gz';
        file_put_contents($gzipped, (string)gzencode((string)file_get_contents($masked)));

        $this->assertTrue(DbSanitizer::isMaskedDump($masked));
        $this->assertTrue(DbSanitizer::isMaskedDump($gzipped));
        $this->assertFalse(DbSanitizer::isMaskedDump($raw), 'A rehearsal copy holds real data.');
    }

    public function testAnEmptyPlanCoversNothing(): void
    {
        $gaps = (new DbSanitizer(new SanitizerPlan()))->coverageGaps($this->connection());

        $this->assertContains('Table "customers" has no declaration: keep, empty or mask it.', $gaps);
    }
}
