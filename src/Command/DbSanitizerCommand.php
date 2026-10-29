<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use RockSoftware\DbSanitizer\DbSanitizer;
use RuntimeException;

/**
 *   bin/cake db_sanitizer check            — exit 1 when a table or text column has no declaration
 *   bin/cake db_sanitizer mask             — mask this database (a throwaway copy only)
 *   bin/cake db_sanitizer rehearse         — prepare this database for a rehearsal (a throwaway copy only)
 *   bin/cake db_sanitizer verify <dump>    — exit 0 only when the dump file is masked
 */
class DbSanitizerCommand extends Command
{
    /** The variable a throwaway copy sets; `mask` and `rehearse` refuse without it. */
    public const THROWAWAY_VAR = 'DB_SANITIZER_THROWAWAY';

    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'db_sanitizer';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser.
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Mask or rehearse a copy of a production database, check the declarations, verify a dump.')
            ->addArgument('action', [
                'help' => 'check, mask, rehearse or verify.',
                'required' => true,
                'choices' => ['check', 'mask', 'rehearse', 'verify'],
            ])
            ->addArgument('dump', ['help' => 'verify: the dump file.'])
            ->addOption('connection', ['help' => 'The connection.', 'default' => 'default']);
    }

    /**
     * @param \Cake\Console\Arguments $args The arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $action = (string)$args->getArgument('action');

        if ($action === 'verify') {
            $dump = (string)$args->getArgument('dump');
            if ($dump === '') {
                $io->err('Name the dump file.');

                return self::CODE_ERROR;
            }
            if (!DbSanitizer::isMaskedDump($dump)) {
                $io->err(sprintf(
                    '%s is not a masked copy. Only a dump masked on the production server may be imported.',
                    $dump,
                ));

                return self::CODE_ERROR;
            }
            $io->out(sprintf('%s is masked.', $dump));

            return self::CODE_SUCCESS;
        }

        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get((string)$args->getOption('connection'));
        $sanitizer = new DbSanitizer();

        if ($action === 'check') {
            $gaps = $sanitizer->coverageGaps($connection);
            foreach ($gaps as $gap) {
                $io->err($gap);
            }
            if ($gaps !== []) {
                return self::CODE_ERROR;
            }
            $io->out('Every table and every text column is declared.');

            return self::CODE_SUCCESS;
        }

        if (env(self::THROWAWAY_VAR) !== 'yes') {
            $io->err(sprintf(
                'Refused: %s rewrites the database. It runs only in a throwaway copy, where %s=yes is set.',
                $action,
                self::THROWAWAY_VAR,
            ));

            return self::CODE_ERROR;
        }

        try {
            $changed = $this->rewriteCopy($sanitizer, $connection, $action);
        } catch (RuntimeException $e) {
            $io->err($e->getMessage());

            return self::CODE_ERROR;
        }
        foreach ($changed as $table => $count) {
            $io->out(sprintf('%s: %d rows', $table, $count));
        }
        foreach ($sanitizer->plan()->switches() as $switch) {
            $io->out(sprintf('Turned off: %s', $switch->name()));
        }
        // The copy is masked; what it lacked or carried beyond the
        // declarations still needs a declaration or a migration.
        foreach ($sanitizer->warnings() as $warning) {
            $io->warning('Schema drift: ' . $warning);
        }
        if ($sanitizer->warnings() !== []) {
            $io->warning(sprintf(
                '%d schema drift warnings: add the declarations, or a migration that drops'
                . ' what production no longer needs.',
                count($sanitizer->warnings()),
            ));
        }
        $io->success(sprintf('Done: %s.', $action));

        return self::CODE_SUCCESS;
    }

    /**
     * @param \RockSoftware\DbSanitizer\DbSanitizer $sanitizer The sanitizer.
     * @param \Cake\Database\Connection $connection The copy.
     * @param string $action mask or rehearse.
     * @return array<string, int>
     */
    private function rewriteCopy(DbSanitizer $sanitizer, Connection $connection, string $action): array
    {
        return $connection->transactional(
            fn(): array => $action === 'mask' ? $sanitizer->mask($connection) : $sanitizer->rehearse($connection),
        );
    }
}
