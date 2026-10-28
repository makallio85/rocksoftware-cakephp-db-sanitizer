<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Expression\QueryExpression;
use Cake\I18n\DateTime;
use Cake\Utility\Security;
use Closure;
use InvalidArgumentException;
use RockSoftware\DbSanitizer\Faker\DeterministicFaker;
use RuntimeException;

/**
 * Masks or rehearses a copy of a production database, and checks that every
 * table and every text column has a declaration.
 *
 * Never point it at production: `mask` rewrites every personal field and
 * empties whole tables. The command refuses to run without
 * `DB_SANITIZER_THROWAWAY=yes`, which only the script that builds a throwaway
 * copy sets.
 */
class DbSanitizer
{
    /** The marker table; a dump that holds a `mask` row in it is masked. */
    public const RUNS_TABLE = 'db_sanitizer_runs';

    public const TIER_MASK = 'mask';
    public const TIER_REHEARSE = 'rehearse';

    /** Rows read and written per round trip. */
    public const CHUNK = 500;

    private DeterministicFaker $faker;

    /**
     * @param \RockSoftware\DbSanitizer\SanitizerPlan|null $plan The plan; read from configuration when null.
     * @param \RockSoftware\DbSanitizer\Faker\DeterministicFaker|null $faker The fake generator.
     */
    public function __construct(private ?SanitizerPlan $plan = null, ?DeterministicFaker $faker = null)
    {
        $this->plan ??= self::planFromConfig();
        $this->faker = $faker ?? new DeterministicFaker(
            (string)(Configure::read('DbSanitizer.key') ?: Security::getSalt()),
            (string)(Configure::read('DbSanitizer.locale') ?: 'fi_FI'),
        );
    }

    /**
     * The plan the application and its plugins declare.
     *
     * @return \RockSoftware\DbSanitizer\SanitizerPlan
     */
    public static function planFromConfig(): SanitizerPlan
    {
        $plan = new SanitizerPlan();
        foreach ((array)Configure::read('DbSanitizer.declarations', []) as $declarations) {
            if (is_string($declarations)) {
                $declarations = new $declarations();
            }
            if (!$declarations instanceof DeclarationsInterface) {
                throw new InvalidArgumentException(
                    'DbSanitizer.declarations lists something that is not a DeclarationsInterface.',
                );
            }
            $declarations->declare($plan);
        }

        return $plan;
    }

    /**
     * @return \RockSoftware\DbSanitizer\SanitizerPlan
     */
    public function plan(): SanitizerPlan
    {
        /** @var \RockSoftware\DbSanitizer\SanitizerPlan */
        return $this->plan;
    }

    /**
     * What the declarations miss or no longer match, one sentence each; empty
     * when every table and every text column of a masked table is declared.
     *
     * @param \Cake\Database\Connection $connection The database.
     * @return array<int, string>
     */
    public function coverageGaps(Connection $connection): array
    {
        $schema = $connection->getSchemaCollection();
        $existing = array_filter(
            $schema->listTables(),
            static fn(string $table): bool => !self::isBookkeeping($table),
        );
        $declared = $this->plan()->tables();
        $gaps = [];

        foreach ($existing as $table) {
            if (!isset($declared[$table])) {
                $gaps[] = sprintf('Table "%s" has no declaration: keep, empty or mask it.', $table);
                continue;
            }
            if ($declared[$table]->mode() !== TablePlan::MASK) {
                continue;
            }
            $describe = $schema->describe($table);
            $rules = $declared[$table]->columns();
            $safeTypes = $this->plan()->safeTypeList();
            $primaryKey = $describe->getPrimaryKey();
            foreach ($describe->columns() as $column) {
                $type = (string)$describe->getColumnType($column);
                $needsRule = !in_array($type, $safeTypes, true) && !in_array($column, $primaryKey, true);
                if (!isset($rules[$column]) && $needsRule) {
                    $gaps[] = sprintf('Column "%s.%s" (%s) has no rule.', $table, $column, $type);
                }
            }
            foreach (array_keys($rules) as $column) {
                if (!$describe->hasColumn($column)) {
                    $gaps[] = sprintf('Column "%s.%s" is declared but does not exist.', $table, $column);
                }
            }
        }
        foreach ($declared as $table => $plan) {
            if (!in_array($table, $existing, true) && !$plan->isProductionOnly()) {
                $gaps[] = sprintf('Table "%s" is declared but does not exist.', $table);
            }
        }

        return $gaps;
    }

    /**
     * Replace every personal field, empty what is declared empty, turn off
     * every outbound switch, and write the `mask` marker.
     *
     * @param \Cake\Database\Connection $connection The throwaway copy.
     * @return array<string, int> Rows changed per table.
     * @throws \RuntimeException When the declarations do not cover the schema.
     */
    public function mask(Connection $connection): array
    {
        $gaps = $this->coverageGaps($connection);
        if ($gaps !== []) {
            throw new RuntimeException(
                "Not masked: the declarations do not cover the schema.\n" . implode("\n", $gaps),
            );
        }

        $existing = $connection->getSchemaCollection()->listTables();
        $changed = [];
        foreach ($this->plan()->tables() as $table => $plan) {
            if (!in_array($table, $existing, true)) {
                continue;
            }
            if (in_array($plan->mode(), [TablePlan::EMPTY, TablePlan::CREDENTIALS], true)) {
                $changed[$table] = $connection->delete($table)->rowCount();
            } elseif ($plan->mode() === TablePlan::MASK) {
                $changed[$table] = $this->maskTable($connection, $plan);
            }
        }
        foreach ($this->plan()->afterMaskStatements() as $statement) {
            $connection->execute($statement);
        }
        $this->turnOffOutbound($connection);
        $this->writeMarker($connection, self::TIER_MASK);

        return $changed;
    }

    /**
     * Keep the data, remove the credentials, turn off every outbound switch,
     * and write the `rehearse` marker: a copy for a release rehearsal on the
     * production server.
     *
     * @param \Cake\Database\Connection $connection The throwaway copy.
     * @return array<string, int> Rows changed per table.
     */
    public function rehearse(Connection $connection): array
    {
        $existing = $connection->getSchemaCollection()->listTables();
        $changed = [];
        foreach ($this->plan()->tables() as $table => $plan) {
            if (!in_array($table, $existing, true)) {
                continue;
            }
            if ($plan->mode() === TablePlan::CREDENTIALS) {
                $changed[$table] = $connection->delete($table)->rowCount();
                continue;
            }
            foreach ($plan->columns() as $column => $rule) {
                if ($rule->isSecret()) {
                    $changed[$table] = ($changed[$table] ?? 0) + $this->bulk($connection, $table, $column, $rule);
                }
            }
        }
        $this->turnOffOutbound($connection);
        $this->writeMarker($connection, self::TIER_REHEARSE);

        return $changed;
    }

    /**
     * Every column kept only until somebody reviews it, as `table.column`.
     *
     * @return array<int, string>
     */
    public function unreviewedColumns(): array
    {
        $columns = [];
        foreach ($this->plan()->tables() as $table => $plan) {
            foreach ($plan->columns() as $column => $rule) {
                if ($rule->isUnreviewed()) {
                    $columns[] = $table . '.' . $column;
                }
            }
        }
        sort($columns);

        return $columns;
    }

    /**
     * Whether a dump file holds a `mask` marker row: the check an import
     * script makes before it restores anything. Reads plain and gzipped SQL.
     *
     * @param string $path The dump.
     * @return bool
     */
    public static function isMaskedDump(string $path): bool
    {
        $isGzip = str_ends_with($path, '.gz');
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Cannot read %s.', $path));
        }
        $handle = $isGzip ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot read %s.', $path));
        }
        $pattern = '/INSERT INTO [`"]?' . self::RUNS_TABLE . '[`"]?.*[\'"]' . self::TIER_MASK . '[\'"]/i';
        try {
            while (($line = $isGzip ? gzgets($handle) : fgets($handle)) !== false) {
                if (str_contains($line, self::RUNS_TABLE) && preg_match($pattern, $line) === 1) {
                    return true;
                }
            }
        } finally {
            $isGzip ? gzclose($handle) : fclose($handle);
        }

        return false;
    }

    /**
     * Apply rules to every row of a table, a chunk at a time by primary key.
     *
     * @param \Cake\Database\Connection $connection The copy.
     * @param string $table The table.
     * @param array<string, \RockSoftware\DbSanitizer\Rule> $rules The rules to apply.
     * @return int Rows changed.
     */
    private function rewrite(Connection $connection, string $table, array $rules, ?TablePlan $plan = null): int
    {
        $primaryKey = $connection->getSchemaCollection()->describe($table)->getPrimaryKey();
        if (count($primaryKey) !== 1) {
            throw new RuntimeException(sprintf('Table "%s" needs a single-column primary key to be masked.', $table));
        }
        $key = $primaryKey[0];
        $columns = array_keys($rules);
        $read = array_values(array_unique(array_merge([$key], $columns, $plan?->usingColumns() ?? [])));
        $conditions = self::conditions($connection, $plan?->conditions());
        $changed = 0;
        $last = null;

        do {
            $query = $connection->selectQuery($read, $table)
                ->orderBy([$key => 'ASC'])
                ->limit(self::CHUNK);
            if ($conditions !== []) {
                $query->where($conditions);
            }
            if ($last !== null) {
                $query->where([$key . ' >' => $last]);
            }
            $rows = $query->execute()->fetchAll('assoc');

            foreach ($rows as $row) {
                $values = [];
                foreach ($rules as $column => $rule) {
                    $new = $this->apply($rule, $row[$column], $row);
                    if ($new !== $row[$column]) {
                        $values[$column] = $new;
                    }
                }
                if ($values !== []) {
                    $connection->update($table, $values, [$key => $row[$key]]);
                    $changed++;
                }
                $last = $row[$key];
            }
            $isFullChunk = count($rows) === self::CHUNK;
        } while ($isFullChunk);

        return $changed;
    }

    /**
     * One masked table: its bulk rules as one UPDATE each, then its
     * row-by-row rules a chunk at a time.
     *
     * @param \Cake\Database\Connection $connection The copy.
     * @param \RockSoftware\DbSanitizer\TablePlan $plan The table.
     * @return int Rows changed, counted per statement.
     */
    private function maskTable(Connection $connection, TablePlan $plan): int
    {
        $changed = 0;
        $rowRules = [];
        foreach ($plan->columns() as $column => $rule) {
            if ($rule->isKeep()) {
                continue;
            }
            if ($rule->isBulk()) {
                $changed += $this->bulk($connection, $plan->table, $column, $rule);
            } else {
                $rowRules[$column] = $rule;
            }
        }
        if ($rowRules !== []) {
            $changed += $this->rewrite($connection, $plan->table, $rowRules, $plan);
        }

        return $changed;
    }

    /**
     * One rule over a whole table in one UPDATE.
     *
     * @param \Cake\Database\Connection $connection The copy.
     * @param string $table The table.
     * @param string $column The column.
     * @param \RockSoftware\DbSanitizer\Rule $rule A bulk rule.
     * @return int Rows changed.
     */
    private function bulk(Connection $connection, string $table, string $column, Rule $rule): int
    {
        $query = $connection->updateQuery($table);
        $value = match ($rule->action) {
            'expression' => new QueryExpression((string)$rule->argument),
            'constant' => $rule->argument,
            default => null,
        };
        $query->set($column, $value);
        $conditions = self::conditions($connection, $rule->conditions);
        if ($rule->action === 'expression') {
            // Masking must not invent data where there was none.
            $conditions[] = [$column . ' IS NOT' => null, $column . ' !=' => ''];
        } elseif ($rule->action !== 'constant') {
            $conditions[] = [$column . ' IS NOT' => null];
        }
        if ($conditions !== []) {
            $query->where($conditions);
        }

        return $query->execute()->rowCount();
    }

    /**
     * @param \Cake\Database\Connection $connection The copy.
     * @param \Closure|array<string|int, mixed>|null $conditions The conditions.
     * @return array<string|int, mixed>
     */
    private static function conditions(Connection $connection, Closure|array|null $conditions): array
    {
        if ($conditions instanceof Closure) {
            return (array)$conditions($connection);
        }

        return $conditions ?? [];
    }

    /**
     * @param \RockSoftware\DbSanitizer\Rule $rule The rule.
     * @param mixed $value The original.
     * @param array<string, mixed> $row The row's masked columns and key.
     * @return mixed
     */
    private function apply(Rule $rule, mixed $value, array $row): mixed
    {
        if ($rule->action === 'constant') {
            return $rule->argument;
        }
        if ($value === null || $value === '') {
            return $value;
        }

        return match ($rule->action) {
            'keep' => $value,
            'null', 'secret' => null,
            'fake' => $this->faker->fake((string)$rule->argument, (string)$value),
            'custom' => ($rule->argument)($value, $row, $this->faker),
            default => throw new InvalidArgumentException(sprintf('Unknown rule "%s".', $rule->action)),
        };
    }

    /**
     * @param \Cake\Database\Connection $connection The copy.
     * @return void
     */
    private function turnOffOutbound(Connection $connection): void
    {
        foreach ($this->plan()->switches() as $switch) {
            $switch->turnOff($connection);
        }
    }

    /**
     * @param \Cake\Database\Connection $connection The copy.
     * @param string $tier The tier.
     * @return void
     */
    private function writeMarker(Connection $connection, string $tier): void
    {
        $now = new DateTime();
        $connection->insert(self::RUNS_TABLE, [
            'tier' => $tier,
            'ran_at' => $now,
            'created' => $now,
        ], ['ran_at' => 'datetime', 'created' => 'datetime']);
    }

    /**
     * Tables that are the schema's own bookkeeping, never data.
     *
     * @param string $table The table.
     * @return bool
     */
    private static function isBookkeeping(string $table): bool
    {
        return in_array($table, [self::RUNS_TABLE, 'cake_migrations', 'cake_seeds', 'sqlite_sequence'], true)
            || str_ends_with($table, 'phinxlog');
    }
}
