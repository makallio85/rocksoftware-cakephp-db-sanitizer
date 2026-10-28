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

Every table needs a declaration: kept, emptied or masked. In a masked table, every text column (`string`, `char`, `text`, `json`) needs a rule. Numbers, dates and booleans are kept unless a rule says otherwise.

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
| `Rule::custom(fn($value, $row, $faker) => …)` | Anything else |

A null or empty value stays as it is under every rule but `constant()`. A masked table needs a single-column primary key.

**Outbound switches** (`OutboundSwitchInterface`) are whatever lets the application reach the outside: a sending setting, a scheduler, webhook endpoints. `mask` and `rehearse` both run them all.

## Safety

`mask` and `rehearse` rewrite the database they run against. They refuse to run unless `DB_SANITIZER_THROWAWAY=yes` is set, which only the script that builds a throwaway copy sets. Run them on a restored copy, never on production.

A raw dump of production carries an empty `db_sanitizer_runs`. Only `mask` writes a `mask` row, which is what `verify` looks for.
