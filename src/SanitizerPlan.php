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
