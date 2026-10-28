<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use RockSoftware\DbSanitizer\Command\DbSanitizerCommand;

/**
 * Masks copies of a production database for development, and prepares
 * copies for a release rehearsal. See README.md.
 */
class DbSanitizerPlugin extends BasePlugin
{
    protected ?string $name = 'RockSoftware/DbSanitizer';

    protected bool $routesEnabled = false;

    protected bool $middlewareEnabled = false;

    /**
     * @param \Cake\Console\CommandCollection $commands The commands.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands->add('db_sanitizer', DbSanitizerCommand::class);
    }
}
