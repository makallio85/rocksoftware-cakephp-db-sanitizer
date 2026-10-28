<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

/**
 * Every table's declaration and every outbound switch, collected from the
 * application and its plugins (`DbSanitizer.declarations`).
 */
final class SanitizerPlan
{
    /**
     * @var array<string, \RockSoftware\DbSanitizer\TablePlan>
     */
    private array $tables = [];

    /**
     * @var array<int, \RockSoftware\DbSanitizer\OutboundSwitchInterface>
     */
    private array $switches = [];

    /**
     * SQL run by `mask` after every rule, before the switches.
     *
     * @var array<int, string>
     */
    private array $statements = [];

    /**
     * Column types that can never hold personal data, so they need no rule.
     * Every other type does, in a masked table.
     *
     * @var array<int, string>
     */
    private array $safeTypes = self::DEFAULT_SAFE_TYPES;

    /**
     * The default: numbers, booleans and moments. A plain date is not among
     * them, because a date can be a birth date.
     *
     * @var array<int, string>
     */
    public const DEFAULT_SAFE_TYPES = [
        'integer', 'biginteger', 'smallinteger', 'tinyinteger',
        'decimal', 'float', 'boolean',
        'datetime', 'datetimefractional', 'timestamp', 'timestampfractional', 'timestamptimezone', 'time',
        'uuid',
    ];

    /**
     * A table whose columns are masked; declare them on the returned plan.
     *
     * @param string $table The table.
     * @return \RockSoftware\DbSanitizer\TablePlan
     */
    public function table(string $table): TablePlan
    {
        return $this->tables[$table] ??= new TablePlan($table);
    }

    /**
     * Tables that hold no personal data and no credentials: type tables,
     * product catalogues, the schema's own bookkeeping.
     *
     * @param string ...$tables The tables.
     * @return $this
     */
    public function keepTables(string ...$tables)
    {
        foreach ($tables as $table) {
            $this->table($table)->setMode(TablePlan::KEEP);
        }

        return $this;
    }

    /**
     * Tables emptied completely: sessions, queues, logs of what was sent.
     *
     * @param string ...$tables The tables.
     * @return $this
     */
    public function emptyTables(string ...$tables)
    {
        foreach ($tables as $table) {
            $this->table($table)->setMode(TablePlan::EMPTY);
        }

        return $this;
    }

    /**
     * Tables of live credentials: sessions, API tokens, links that open data
     * without signing in. Emptied by `mask` and by `rehearse` alike.
     *
     * @param string ...$tables The tables.
     * @return $this
     */
    public function credentialTables(string ...$tables)
    {
        foreach ($tables as $table) {
            $this->table($table)->setMode(TablePlan::CREDENTIALS);
        }

        return $this;
    }

    /**
     * SQL that `mask` runs after the rules, for what a column rule cannot say:
     * opening sign-in for a test login, a row that must exist. Never run by
     * `rehearse`. Written by the application, never from data.
     *
     * @param string ...$statements The statements, run in order.
     * @return $this
     */
    public function afterMask(string ...$statements)
    {
        array_push($this->statements, ...$statements);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function afterMaskStatements(): array
    {
        return $this->statements;
    }

    /**
     * Replace the column types that need no rule.
     *
     * @param string ...$types CakePHP abstract column types.
     * @return $this
     */
    public function safeTypes(string ...$types)
    {
        $this->safeTypes = array_values($types);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function safeTypeList(): array
    {
        return $this->safeTypes;
    }

    /**
     * Something that lets the application reach the outside, turned off by
     * both `mask` and `rehearse`.
     *
     * @param \RockSoftware\DbSanitizer\OutboundSwitchInterface $switch The switch.
     * @return $this
     */
    public function outbound(OutboundSwitchInterface $switch)
    {
        $this->switches[] = $switch;

        return $this;
    }

    /**
     * @return array<string, \RockSoftware\DbSanitizer\TablePlan>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * @return array<int, \RockSoftware\DbSanitizer\OutboundSwitchInterface>
     */
    public function switches(): array
    {
        return $this->switches;
    }
}
