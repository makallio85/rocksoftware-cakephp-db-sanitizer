<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer\TestSuite;

use Cake\Datasource\ConnectionManager;
use RockSoftware\DbSanitizer\DbSanitizer;

/**
 * For an application's test: fails when a table or a text column of a masked
 * table has no declaration, so a schema change cannot forget the masking.
 *
 *     public function testEveryColumnIsDeclared(): void
 *     {
 *         $this->assertSanitizerDeclarationsCoverSchema();
 *     }
 */
trait DeclarationsCoverageTrait
{
    /**
     * @param string $connectionName The connection the migrated test schema is on.
     * @return void
     */
    protected function assertSanitizerDeclarationsCoverSchema(string $connectionName = 'test'): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get($connectionName);
        $gaps = (new DbSanitizer())->coverageGaps($connection);

        $this->assertSame(
            [],
            $gaps,
            "Declare these in the application's DbSanitizer declarations:\n" . implode("\n", $gaps),
        );
    }
}
