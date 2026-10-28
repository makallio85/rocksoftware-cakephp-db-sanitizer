<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer;

/**
 * Declares what masking does with the tables one owner — the application or a
 * plugin — has, and what it must turn off. Listed in `DbSanitizer.declarations`.
 */
interface DeclarationsInterface
{
    /**
     * @param \RockSoftware\DbSanitizer\SanitizerPlan $plan The plan to add to.
     * @return void
     */
    public function declare(SanitizerPlan $plan): void;
}
