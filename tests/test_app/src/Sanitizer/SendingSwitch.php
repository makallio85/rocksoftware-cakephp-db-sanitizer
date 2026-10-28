<?php
declare(strict_types=1);

namespace TestApp\Sanitizer;

use Cake\Database\Connection;
use RockSoftware\DbSanitizer\OutboundSwitchInterface;

/**
 * The fictional application's "send email" setting.
 */
class SendingSwitch implements OutboundSwitchInterface
{
    /**
     * @return string
     */
    public function name(): string
    {
        return 'Send email';
    }

    /**
     * @param \Cake\Database\Connection $connection The copy.
     * @return void
     */
    public function turnOff(Connection $connection): void
    {
        $connection->update('app_settings', ['value' => '0'], ['setting_key' => 'is_email_enabled']);
    }
}
