<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

use Closure;
use InvalidArgumentException;

/**
 * What masking does with one table: keep it, empty it, or mask some of its
 * columns. A column of a masked table that is not declared is kept.
 */
final class TablePlan
{
    public const KEEP = 'keep';
    public const EMPTY = 'empty';
    /** Emptied by `rehearse` too: live logins, tokens, links that open data without a login. */
    public const CREDENTIALS = 'credentials';
    public const MASK = 'mask';

    /**
     * @var array<string, \RockSoftware\DbSanitizer\Rule>
     */
    private array $columns = [];

    /**
     * Columns read along with the masked ones, for custom rules.
     *
     * @var array<int, string>
     */
    private array $using = [];

    /**
     * Only these rows get the row-by-row rules (fake, custom).
     *
     * @var \Closure|array<string|int, mixed>|null
     */
    private Closure|array|null $conditions = null;

    private bool $isProductionOnly = false;

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
     * Read these columns too, so a custom rule finds them in its row.
     *
     * @param string ...$columns The columns.
     * @return $this
     */
    public function using(string ...$columns)
    {
        $this->using = array_values(array_unique(array_merge($this->using, $columns)));

        return $this;
    }

    /**
     * Apply the row-by-row rules (fake, custom) only to the rows matching the
     * conditions: a CakePHP conditions array, or a closure that gets the
     * connection and returns one. Bulk rules take their own `onlyWhere()`.
     *
     * @param \Closure(\Cake\Database\Connection): array<string|int, mixed>|array<string|int, mixed> $conditions The conditions.
     * @return $this
     */
    public function where(Closure|array $conditions)
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * The table exists in production but no migration creates it any more:
     * masked when a dump carries it, and not reported missing by the coverage
     * check.
     *
     * @return $this
     */
    public function productionOnly()
    {
        $this->isProductionOnly = true;

        return $this;
    }

    /**
     * @return bool
     */
    public function isProductionOnly(): bool
    {
        return $this->isProductionOnly;
    }

    /**
     * @return array<int, string>
     */
    public function usingColumns(): array
    {
        return $this->using;
    }

    /**
     * @return \Closure|array<string|int, mixed>|null
     */
    public function conditions(): Closure|array|null
    {
        return $this->conditions;
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
