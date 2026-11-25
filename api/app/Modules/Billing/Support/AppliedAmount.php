<?php

namespace App\Modules\Billing\Support;

/**
 * Amount fixed for a service, with where it came from.
 */
final readonly class AppliedAmount
{
    public function __construct(
        public string $amount,
        public string $currency,
        public string $source,
        public ?int $rateId = null,
    ) {}
}
