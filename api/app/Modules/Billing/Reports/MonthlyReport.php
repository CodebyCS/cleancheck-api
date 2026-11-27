<?php

namespace App\Modules\Billing\Reports;

use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Support\Cents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monthly settlement per provider: services and amounts from the service entries of the month.
 * It is a calculation ("apurado"), not a payment record: nothing here says whether anyone was paid.
 */
class MonthlyReport
{
    public const STATUS = 'apurado';

    /**
     * @return array<string, mixed>
     */
    public function build(string $month, ?int $serviceProviderId = null): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->addMonth();

        $entries = ServiceEntry::query()
            ->with(['serviceProvider.user:id,name', 'property:id,name', 'adjustments'])
            ->when($serviceProviderId !== null, fn ($query) => $query->where('service_provider_id', $serviceProviderId))
            // Half-open range: service_date may be stored with a time part (e.g. SQLite).
            ->where('service_date', '>=', $start->toDateString())
            ->where('service_date', '<', $end->toDateString())
            ->orderBy('service_date')
            ->orderBy('id')
            ->get();

        $providers = $entries
            ->groupBy('service_provider_id')
            ->map(fn (Collection $group) => $this->providerSection($group))
            ->sortBy(fn (array $provider) => mb_strtolower((string) $provider['name']))
            ->values()
            ->all();

        return [
            'month' => $month,
            'period' => [
                'from' => $start->toDateString(),
                'to' => $end->copy()->subDay()->toDateString(),
            ],
            'status' => self::STATUS,
            'totals' => $this->totals($entries),
            'providers' => $providers,
        ];
    }

    /**
     * @param  Collection<int, ServiceEntry>  $entries
     * @return array<string, mixed>
     */
    private function providerSection(Collection $entries): array
    {
        /** @var ServiceEntry $first */
        $first = $entries->first();

        return [
            'service_provider_id' => $first->service_provider_id,
            'name' => $first->serviceProvider?->user?->name,
            'totals' => $this->totals($entries),
            'entries' => $entries->map(fn (ServiceEntry $entry) => [
                'id' => $entry->id,
                'task_id' => $entry->task_id,
                'service_date' => $entry->service_date?->toDateString(),
                'property_id' => $entry->property_id,
                'property_name' => $entry->property?->name,
                'task_type' => $entry->task_type,
                'amount_source' => $entry->amount_source,
                'amount' => $entry->amount,
                'adjustments_total' => $entry->adjustmentsTotal(),
                'final_amount' => $entry->finalAmount(),
                'currency' => $entry->currency,
            ])->values()->all(),
        ];
    }

    /**
     * Totals per currency. Different currencies are never added together.
     *
     * @param  Collection<int, ServiceEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function totals(Collection $entries): array
    {
        return $entries
            ->groupBy('currency')
            ->sortKeys()
            ->map(function (Collection $group, string $currency): array {
                $original = $group->sum(fn (ServiceEntry $entry) => Cents::from($entry->amount));
                $adjustments = $group->sum(fn (ServiceEntry $entry) => Cents::from($entry->adjustmentsTotal()));

                return [
                    'currency' => $currency,
                    'services' => $group->count(),
                    'original_total' => Cents::format($original),
                    'adjustments_total' => Cents::format($adjustments),
                    'total' => Cents::format($original + $adjustments),
                ];
            })
            ->values()
            ->all();
    }
}
