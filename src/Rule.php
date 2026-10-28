<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

use Closure;
use InvalidArgumentException;

/**
 * What masking does with one column.
 *
 * Build one with a named constructor:
 *
 *     $plan->table('customers')
 *         ->column('name', Rule::fake(Rule::FULL_NAME))
 *         ->column('email', Rule::fake(Rule::EMAIL))
 *         ->column('api_key', Rule::secret());
 *
 * A null or empty value stays as it is under every rule but `constant()`:
 * masking must not invent data where there was none.
 */
final class Rule
{
    /** A first name. */
    public const FIRST_NAME = 'first_name';
    /** A last name. */
    public const LAST_NAME = 'last_name';
    /** A first and a last name. */
    public const FULL_NAME = 'full_name';
    /** An email address on a reserved test domain, unique per original. */
    public const EMAIL = 'email';
    /** A mobile number, unique per original, in E.164 form. */
    public const PHONE = 'phone';
    /** A street and a house number. */
    public const STREET_ADDRESS = 'street_address';
    /** A postal code. */
    public const POSTAL_CODE = 'postal_code';
    /** A city. */
    public const CITY = 'city';
    /** A company name. */
    public const COMPANY = 'company';
    /** Some sentences, about as long as the original. */
    public const TEXT = 'text';
    /** A string of the original's length, unique per original: identifiers such as a plate number or an IBAN. */
    public const IDENTIFIER = 'identifier';

    /**
     * Every fake kind, for checking a declaration.
     *
     * @var array<int, string>
     */
    public const FAKE_KINDS = [
        self::FIRST_NAME,
        self::LAST_NAME,
        self::FULL_NAME,
        self::EMAIL,
        self::PHONE,
        self::STREET_ADDRESS,
        self::POSTAL_CODE,
        self::CITY,
        self::COMPANY,
        self::TEXT,
        self::IDENTIFIER,
    ];

    /**
     * @param string $action One of keep, unreviewed, null, constant, fake, secret, custom, expression.
     * @param mixed $argument The constant, the fake kind, the closure or the SQL expression.
     * @param \Closure|array<string|int, mixed>|null $conditions Only rows matching these are rewritten.
     */
    private function __construct(
        public readonly string $action,
        public readonly mixed $argument = null,
        public readonly Closure|array|null $conditions = null,
    ) {
    }

    /**
     * Leave the value as it is: ids, amounts, dates, codes.
     *
     * @return self
     */
    public static function keep(): self
    {
        return new self('keep');
    }

    /**
     * Kept for now, but nobody has decided yet whether it can hold personal
     * data. Behaves as `keep()`; `DbSanitizer::unreviewedColumns()` lists them,
     * so an application can make sure the list only shrinks.
     *
     * @return self
     */
    public static function unreviewed(): self
    {
        return new self('unreviewed');
    }

    /**
     * Set the value to the result of an SQL expression over the row, in one
     * UPDATE for the whole table: for tables too big to rewrite row by row.
     * The expression is written by the application, never from data. Rows
     * whose value is null or empty are left as they are.
     *
     *     Rule::expression("CONCAT('contact', id, '@example.test')")
     *
     * @param string $sql The expression.
     * @return self
     */
    public static function expression(string $sql): self
    {
        if (trim($sql) === '') {
            throw new InvalidArgumentException('The expression is empty.');
        }

        return new self('expression', $sql);
    }

    /**
     * This rule, applied only to the rows matching the conditions: a CakePHP
     * conditions array (values are bound), or a closure that gets the
     * connection and returns one, for a condition with a subquery.
     *
     * @param \Closure(\Cake\Database\Connection): array<string|int, mixed>|array<string|int, mixed> $conditions The conditions.
     * @return self
     */
    public function onlyWhere(Closure|array $conditions): self
    {
        return new self($this->action, $this->argument, $conditions);
    }

    /**
     * Set the value to null. The column must allow it.
     *
     * @return self
     */
    public static function null(): self
    {
        return new self('null');
    }

    /**
     * Set every row to the same value, null and empty included.
     *
     * @param mixed $value The value.
     * @return self
     */
    public static function constant(mixed $value): self
    {
        return new self('constant', $value);
    }

    /**
     * A credential: removed (null), and listed as such, so `rehearse` removes
     * it too while keeping everything else.
     *
     * @return self
     */
    public static function secret(): self
    {
        return new self('secret');
    }

    /**
     * A realistic fake of one kind. The same original always gives the same
     * fake, in every table and every run with the same key, so links between
     * tables, searches and duplicates still behave.
     *
     * @param string $kind One of the kind constants.
     * @return self
     */
    public static function fake(string $kind): self
    {
        if (!in_array($kind, self::FAKE_KINDS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a fake kind.', $kind));
        }

        return new self('fake', $kind);
    }

    /**
     * Anything else: the closure gets the original value, the whole row and
     * the fake generator, and returns the new value.
     *
     * @param \Closure(mixed, array<string, mixed>, \RockSoftware\DbSanitizer\Faker\DeterministicFaker): mixed $mask The mask.
     * @return self
     */
    public static function custom(Closure $mask): self
    {
        return new self('custom', $mask);
    }

    /**
     * Whether `mask` leaves the column alone.
     *
     * @return bool
     */
    public function isKeep(): bool
    {
        return $this->action === 'keep' || $this->action === 'unreviewed';
    }

    /**
     * Whether the column is kept only until somebody reviews it.
     *
     * @return bool
     */
    public function isUnreviewed(): bool
    {
        return $this->action === 'unreviewed';
    }

    /**
     * Whether the rule is applied by one UPDATE rather than row by row.
     *
     * @return bool
     */
    public function isBulk(): bool
    {
        return in_array($this->action, ['expression', 'constant', 'null', 'secret'], true);
    }

    /**
     * Whether the column holds a credential.
     *
     * @return bool
     */
    public function isSecret(): bool
    {
        return $this->action === 'secret';
    }
}
