<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Models\ServiceEntryAdjustment;
use App\Modules\Billing\Support\Cents;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordServiceEntryAdjustment
{
    /**
     * Adds a correction to a service entry. The entry itself is never changed.
     */
    public function handle(int $serviceEntryId, string $amount, string $reason, User $author): ServiceEntryAdjustment
    {
        return DB::transaction(function () use ($serviceEntryId, $amount, $reason, $author): ServiceEntryAdjustment {
            // Lock the entry so two concurrent adjustments cannot both pass the check below.
            $entry = ServiceEntry::query()->whereKey($serviceEntryId)->lockForUpdate()->firstOrFail();

            if (Cents::from($entry->finalAmount()) + Cents::from($amount) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O ajuste deixaria o valor final do lançamento negativo.',
                ]);
            }

            return $entry->adjustments()->create([
                'amount' => $amount,
                'reason' => $reason,
                'created_by_user_id' => $author->id,
            ]);
        });
    }
}
