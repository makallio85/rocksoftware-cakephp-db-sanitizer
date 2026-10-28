<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The marker a masked or rehearsed copy carries.
 *
 * Empty in production: only the `db_sanitizer` command writes a row, and it
 * refuses to run outside a throwaway copy. A dump that holds a `mask` row is
 * therefore masked, and an import script asks exactly that
 * (`bin/cake db_sanitizer verify <dump>`).
 */
class CreateDbSanitizerRuns extends BaseMigration
{
    public bool $autoId = false;

    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('db_sanitizer_runs')
            ->addColumn('id', 'integer', ['autoIncrement' => true, 'null' => false, 'signed' => false, 'limit' => 10])
            ->addPrimaryKey(['id'])
            // A code-level discriminator nobody picks: mask or rehearse.
            ->addColumn('tier', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('ran_at', 'datetime', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('db_sanitizer_runs')->drop()->save();
    }
}
