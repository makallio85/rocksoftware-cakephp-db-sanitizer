<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

use InvalidArgumentException;

/**
 * What masking does with one table: keep it, empty it, or mask some of its
 * columns. A column of a masked table that is not declared is kept.
 */
final class TablePlan
{
    public const KEEP = 'keep';
    public const EMPTY = 'empty';
    public const MASK = 'mask';

    /**
     * @var array<string, \RockSoftware\DbSanitizer\Rule>
     */
    private array $columns = [];

    /**
     * @param string $table The table.
     * @param string $mode One of the mode constants.
     */
    public function __construct(public readonly string $table, private string $mode = self::MASK)
    {
    }

    /**
     * Declare what happens to one column.
     *
     * @param string $column The column.
     * @param \RockSoftware\DbSanitizer\Rule $rule The rule.
     * @return $this
     */
    public function column(string $column, Rule $rule)
    {
        if ($this->mode !== self::MASK) {
            throw new InvalidArgumentException(sprintf(
                'Table "%s" is declared %s; it has no column rules.',
                $this->table,
                $this->mode,
            ));
        }
        $this->columns[$column] = $rule;

        return $this;
    }

    /**
     * Declare several columns that are kept as they are, so the coverage check
     * knows they were looked at.
     *
     * @param string ...$columns The columns.
     * @return $this
     */
    public function keep(string ...$columns)
    {
        foreach ($columns as $column) {
            $this->column($column, Rule::keep());
        }

        return $this;
    }

    /**
     * @return string One of the mode constants.
     */
    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * @param string $mode One of the mode constants.
     * @return void
     */
    public function setMode(string $mode): void
    {
        if ($mode !== self::MASK && $this->columns !== []) {
            throw new InvalidArgumentException(sprintf(
                'Table "%s" has column rules and cannot also be declared %s.',
                $this->table,
                $mode,
            ));
        }
        $this->mode = $mode;
    }

    /**
     * @return array<string, \RockSoftware\DbSanitizer\Rule>
     */
    public function columns(): array
    {
        return $this->columns;
    }
}
