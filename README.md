# rocksoftware-cakephp-db-sanitizer

A CakePHP 5 plugin for copies of a production database.

- **`mask`** turns a copy into one fit for development. Every personal field gets a realistic fake, credentials are removed, and everything that reaches the outside is switched off.
- **`rehearse`** prepares a copy for a release rehearsal. Real data is kept, credentials are removed, and the outside is switched off.
- **`check`** fails when a table, or a text column of a masked table, has no declaration. A schema change therefore cannot forget the masking.
- **`verify <dump>`** succeeds only for a dump that `mask` produced. Import scripts call it before restoring anything.

Fakes are **deterministic**: the same original gives the same fake in every table and every run with the same key, so relations, searches and duplicates still behave. Without the key (the production salt, by default) a fake cannot be traced back by guessing originals.

| | Name |
|---|---|
| Composer package | `makallio85/rocksoftware-cakephp-db-sanitizer` |
| CakePHP plugin, namespace | `RockSoftware/DbSanitizer`, `RockSoftware\DbSanitizer\` |
| Command | `bin/cake db_sanitizer check\|mask\|rehearse\|verify <dump>` |
| Table | `db_sanitizer_runs` (the marker) |
| Configuration | `DbSanitizer.declarations`, `DbSanitizer.key`, `DbSanitizer.locale` |

## Installing

1. `composer require makallio85/rocksoftware-cakephp-db-sanitizer`
2. `$this->addPlugin(\RockSoftware\DbSanitizer\DbSanitizerPlugin::class);` in `Application::bootstrap()`.
3. Run `bin/cake migrations migrate -p RockSoftware/DbSanitizer` wherever the application migrates, production included. The marker table stays empty there.
4. Declare (below) and list the declarations:

   ```php
   'DbSanitizer' => [
       'declarations' => [\App\Sanitizer\AppDeclarations::class],
       // 'key' => defaults to Security::getSalt(); 'locale' => 'fi_FI'
   ],
   ```

5. Add the coverage test:

   ```php
   use RockSoftware\DbSanitizer\TestSuite\DeclarationsCoverageTrait;

   public function testEveryColumnIsDeclared(): void
   {
       $this->assertSanitizerDeclarationsCoverSchema();
   }
   ```

## Declaring

Every table needs a declaration: kept, emptied, emptied as credentials, or masked. In a masked table, every column needs a rule unless its type is safe: numbers, booleans, moments (`datetime`, `timestamp`, `time`) and `uuid` by default. A plain `date` is not safe, because it can be a birth date. `$plan->safeTypes(...)` replaces the list.

```php
use RockSoftware\DbSanitizer\DeclarationsInterface;
use RockSoftware\DbSanitizer\Rule;
use RockSoftware\DbSanitizer\SanitizerPlan;

class AppDeclarations implements DeclarationsInterface
{
    public function declare(SanitizerPlan $plan): void
    {
        $plan->table('customers')
            ->column('name', Rule::fake(Rule::FULL_NAME))
            ->column('email', Rule::fake(Rule::EMAIL))
            ->column('phone', Rule::fake(Rule::PHONE))
            ->column('notes', Rule::fake(Rule::TEXT))
            ->column('api_key', Rule::secret())
            ->keep('customer_number', 'status');

        $plan->emptyTables('sessions', 'queued_jobs');
        $plan->keepTables('customer_types');

        $plan->outbound(new EmailSwitch()); // an OutboundSwitchInterface
    }
}
```

| Rule | Does |
|---|---|
| `Rule::keep()` | Leaves the value |
| `Rule::null()` | Sets it to null |
| `Rule::constant($value)` | Sets every row to the value |
| `Rule::secret()` | Removes a credential (null); `rehearse` removes it too |
| `Rule::fake(Rule::FULL_NAME)` | A deterministic fake: `FIRST_NAME`, `LAST_NAME`, `FULL_NAME`, `EMAIL` (on `example.test`, unique), `PHONE` (unique), `STREET_ADDRESS`, `POSTAL_CODE`, `CITY`, `COMPANY`, `TEXT`, `IDENTIFIER` (same shape: letters for letters, digits for digits) |
| `Rule::custom(fn($value, $row, $faker) => …)` | Anything else; `$row` holds the primary key, the masked columns and any `->using(...)` columns |
| `Rule::expression("CONCAT('contact', id, '@example.test')")` | An SQL expression over the row, applied in one `UPDATE`: for tables too big to rewrite row by row |
| `Rule::unreviewed()` | Kept until somebody decides; listed by `DbSanitizer::unreviewedColumns()` so a test can make sure the list only shrinks |

Any bulk rule (`expression`, `constant`, `null`, `secret`) takes `->onlyWhere([...])`: a CakePHP conditions array, or `fn(Connection $c) => [...]` for a subquery. The row-by-row rules (`fake`, `custom`) follow the table's `->where([...])`.

A null or empty value stays as it is under every rule but `constant()`. A table with row-by-row rules needs a single-column primary key.

| Table declaration | Does |
|---|---|
| `$plan->keepTables(...)` | Kept whole |
| `$plan->emptyTables(...)` | Emptied by `mask` |
| `$plan->credentialTables(...)` | Emptied by `mask` and `rehearse`: sessions, API tokens, links that open data without a login |
| `$plan->table('x')->productionOnly()` | Exists in production but no migration creates it any more: masked when a dump carries it, never reported missing |

**After-mask statements** (`$plan->afterMask($sql, ...)`) run once the rules are done, in `mask` only: for what a column rule cannot say, such as opening sign-in for a test login.

**Outbound switches** (`OutboundSwitchInterface`) are whatever lets the application reach the outside: a sending setting, a scheduler, webhook endpoints. `mask` and `rehearse` both run them all. `new SqlOutboundSwitch($name, [$sql, ...])` covers the common case of a few statements.

## Safety

`mask` and `rehearse` rewrite the database they run against. They refuse to run unless `DB_SANITIZER_THROWAWAY=yes` is set, which only the script that builds a throwaway copy sets. Run them on a restored copy, never on production.

A raw dump of production carries an empty `db_sanitizer_runs`. Only `mask` writes a `mask` row, which is what `verify` looks for.
