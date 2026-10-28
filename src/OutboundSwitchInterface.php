<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

use Cake\Database\Connection;

/**
 * One thing that lets the application reach the outside — a sending switch, a
 * scheduler, a webhook endpoint — and how to turn it off in a copy.
 *
 * Both `mask` and `rehearse` run every switch: a copy never sends, reads a
 * real mailbox or calls a real consumer.
 */
interface OutboundSwitchInterface
{
    /**
     * What it turns off, for the command's report.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Turn it off in the copy the connection points at.
     *
     * @param \Cake\Database\Connection $connection The copy.
     * @return void
     */
    public function turnOff(Connection $connection): void;
}
