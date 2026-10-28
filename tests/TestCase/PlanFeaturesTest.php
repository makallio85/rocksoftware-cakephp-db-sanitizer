<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer\Test\TestCase;

use Cake\Database\Connection;
use Cake\TestSuite\TestCase;
use RockSoftware\DbSanitizer\DbSanitizer;
use RockSoftware\DbSanitizer\Rule;
use RockSoftware\DbSanitizer\SanitizerPlan;
use RockSoftware\DbSanitizer\SqlOutboundSwitch;

/**
 * Bulk expressions, conditions, extra columns, credential and
 * production-only tables, unreviewed columns, and the type list.
 */
class PlanFeaturesTest extends TestCase
{
    use SchemaTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    /**
     * The test application's plan, with whatever a test adds on top.
     *
     * @return \RockSoftware\DbSanitizer\SanitizerPlan
     */
    private function plan(): SanitizerPlan
    {
        return DbSanitizer::planFromConfig();
    }

    public function testAnExpressionRewritesTheWholeTableInOneStatementAndSkipsEmptyValues(): void
    {
        $plan = $this->plan();
        $plan->table('customers')->column('email', Rule::expression("'contact' || id || '@example.test'"));

        (new DbSanitizer($plan))->mask($this->connection());

        $this->assertSame('contact1@example.test', $this->rows('SELECT email FROM customers WHERE id = 1')[0]['email']);
        $this->assertSame('contact2@example.test', $this->rows('SELECT email FROM customers WHERE id = 2')[0]['email']);
    }

    public function testABulkRuleCanBeLimitedToSomeRowsWithASubquery(): void
    {
        $plan = $this->plan();
        $plan->table('orders')->column(
            'internal_note',
            Rule::constant('[removed]')->onlyWhere(fn(Connection $c): array => [
                'customer_id IN' => $c->selectQuery(['id'], 'customers')->where(['customer_number' => 'C-1']),
            ]),
        );
        $this->connection()->insert('orders', ['id' => 2, 'customer_id' => 2, 'internal_note' => 'Other', 'status' => 'open']);

        (new DbSanitizer($plan))->mask($this->connection());

        $this->assertSame('[removed]', $this->rows('SELECT internal_note FROM orders WHERE id = 1')[0]['internal_note']);
        $this->assertSame('Other', $this->rows('SELECT internal_note FROM orders WHERE id = 2')[0]['internal_note']);
    }

    public function testACustomRuleReadsExtraColumnsAndRespectsTheTableCondition(): void
    {
        $plan = $this->plan();
        $plan->table('customers')
            ->column('name', Rule::custom(fn($value, array $row): string => 'Customer ' . $row['customer_number']))
            ->using('customer_number')
            ->where(['customer_number !=' => 'C-2']);

        (new DbSanitizer($plan))->mask($this->connection());

        $this->assertSame('Customer C-1', $this->rows('SELECT name FROM customers WHERE id = 1')[0]['name']);
        $this->assertSame('Sam Sample', $this->rows('SELECT name FROM customers WHERE id = 2')[0]['name'], 'Left out.');
    }

    public function testCredentialTablesAreEmptiedByRehearseToo(): void
    {
        $plan = new SanitizerPlan();
        $plan->credentialTables('sessions');

        (new DbSanitizer($plan))->rehearse($this->connection());

        $this->assertSame([], $this->rows('SELECT * FROM sessions'));
        $this->assertSame('Alex Example', $this->rows('SELECT name FROM customers WHERE id = 1')[0]['name']);
    }

    public function testAProductionOnlyTableIsNotReportedMissingAndIsMaskedWhenPresent(): void
    {
        $plan = $this->plan();
        $plan->table('old_notes')->column('body', Rule::constant('[removed]'))->productionOnly();

        $sanitizer = new DbSanitizer($plan);
        $this->assertSame([], $sanitizer->coverageGaps($this->connection()));
        $sanitizer->mask($this->connection());

        $this->connection()->execute('DELETE FROM db_sanitizer_runs');
        $this->connection()->execute('CREATE TABLE old_notes (id INTEGER PRIMARY KEY, body TEXT)');
        $this->connection()->insert('old_notes', ['id' => 1, 'body' => 'Called Alex']);
        $sanitizer->mask($this->connection());
        $this->assertSame('[removed]', $this->rows('SELECT body FROM old_notes')[0]['body']);
        $this->connection()->execute('DROP TABLE old_notes');
    }

    public function testUnreviewedColumnsAreKeptAndListed(): void
    {
        $plan = $this->plan();
        $plan->table('orders')->column('status', Rule::unreviewed());

        $sanitizer = new DbSanitizer($plan);
        $sanitizer->mask($this->connection());

        $this->assertSame('open', $this->rows('SELECT status FROM orders WHERE id = 1')[0]['status']);
        $this->assertSame(['orders.status'], $sanitizer->unreviewedColumns());
    }

    public function testADateNeedsARuleUnlessItsTypeIsDeclaredSafe(): void
    {
        $this->connection()->execute('ALTER TABLE customers ADD COLUMN birth_date DATE');

        $this->assertContains(
            'Column "customers.birth_date" (date) has no rule.',
            (new DbSanitizer())->coverageGaps($this->connection()),
        );

        $plan = $this->plan();
        $plan->safeTypes(...array_merge(SanitizerPlan::DEFAULT_SAFE_TYPES, ['date']));
        $this->assertSame([], (new DbSanitizer($plan))->coverageGaps($this->connection()));
    }

    public function testStatementsAfterMaskRunOnlyInMask(): void
    {
        $plan = $this->plan();
        $plan->afterMask("UPDATE orders SET status = 'reviewed'");

        (new DbSanitizer($plan))->rehearse($this->connection());
        $this->assertSame('open', $this->rows('SELECT status FROM orders WHERE id = 1')[0]['status']);

        (new DbSanitizer($plan))->mask($this->connection());
        $this->assertSame('reviewed', $this->rows('SELECT status FROM orders WHERE id = 1')[0]['status']);
    }

    public function testAnSqlSwitchRunsItsStatements(): void
    {
        $plan = $this->plan();
        $plan->outbound(new SqlOutboundSwitch('Orders on hold', ["UPDATE orders SET status = 'held'"]));

        (new DbSanitizer($plan))->rehearse($this->connection());

        $this->assertSame('held', $this->rows('SELECT status FROM orders WHERE id = 1')[0]['status']);
    }
}
