<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

use Cake\Database\Connection;

/**
 * An outbound switch made of SQL statements the application writes: turn a
 * setting off, pause a queue, delete what was about to be sent.
 *
 *     $plan->outbound(new SqlOutboundSwitch('Scheduler', [
 *         "UPDATE settings SET value = '0' WHERE setting_key = 'scheduler_enabled'",
 *     ]));
 *
 * The statements come from code, never from data.
 */
final class SqlOutboundSwitch implements OutboundSwitchInterface
{
    /**
     * @param string $name What it turns off.
     * @param array<int, string> $statements The statements, run in order.
     */
    public function __construct(private string $name, private array $statements)
    {
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @param \Cake\Database\Connection $connection The copy.
     * @return void
     */
    public function turnOff(Connection $connection): void
    {
        foreach ($this->statements as $statement) {
            $connection->execute($statement);
        }
    }
}
