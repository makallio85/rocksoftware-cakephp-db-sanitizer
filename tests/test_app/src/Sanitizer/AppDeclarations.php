<?php
declare(strict_types=1);

namespace TestApp\Sanitizer;

use RockSoftware\DbSanitizer\DeclarationsInterface;
use RockSoftware\DbSanitizer\Rule;
use RockSoftware\DbSanitizer\SanitizerPlan;

/**
 * A fictional application's declarations: customers with personal data, their
 * orders, a session table, a type table and a sending switch.
 */
class AppDeclarations implements DeclarationsInterface
{
    /**
     * @param \RockSoftware\DbSanitizer\SanitizerPlan $plan The plan.
     * @return void
     */
    public function declare(SanitizerPlan $plan): void
    {
        $plan->table('customers')
            ->column('name', Rule::fake(Rule::FULL_NAME))
            ->column('email', Rule::fake(Rule::EMAIL))
            ->column('phone', Rule::fake(Rule::PHONE))
            ->column('street', Rule::fake(Rule::STREET_ADDRESS))
            ->column('notes', Rule::fake(Rule::TEXT))
            ->column('plate_number', Rule::fake(Rule::IDENTIFIER))
            ->column('api_key', Rule::secret())
            ->keep('customer_number');

        $plan->table('orders')
            ->column('customer_email', Rule::fake(Rule::EMAIL))
            ->column('internal_note', Rule::null())
            ->keep('status');

        $plan->emptyTables('sessions');
        $plan->keepTables('customer_types', 'app_settings');

        $plan->outbound(new SendingSwitch());
    }
}
