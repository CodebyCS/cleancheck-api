<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use App\Modules\Properties\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo scenarios for zones and availability (local and testing only):
 * - Porto Centro: zone with team (prestador1 available, prestador2 unavailable);
 * - Gaia: zone without team, with one property and one open task;
 * - prestador3: deactivated provider in Porto Centro, never listed as candidate.
 *
 * Dates are relative to the day the seeder runs, so the scenarios stay current.
 */
class AvailabilityScenarioSeeder extends Seeder
{
    private const DEMO_TASK_NOTE = 'Demo: candidatos';

    // Far enough from the TaskSeeder tasks (up to 4 days ahead) to avoid conflicts.
    private const DEMO_TASK_DAYS_AHEAD = 6;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException(
                'Cenarios de demonstracao apenas para uso local.'
            );
        }

        $portoCentro = Zone::updateOrCreate(
            ['name' => 'Porto Centro'],
            ['description' => 'Zona com equipa (demonstração).', 'is_active' => true],
        );
        $gaia = Zone::updateOrCreate(
            ['name' => 'Gaia'],
            ['description' => 'Zona sem equipa (demonstração).', 'is_active' => true],
        );

        $foz = Property::where('name', 'Apartamento da Foz')->firstOrFail();
        $foz->update(['zone_id' => $portoCentro->id]);

        $gaiaHouse = Property::updateOrCreate(
            ['name' => 'Casa de Gaia'],
            [
                'address' => 'Rua Exemplo 40',
                'postal_code' => '4400-001',
                'city' => 'Vila Nova de Gaia',
                'zone_id' => $gaia->id,
                'access_instructions' => 'Chave com o vizinho do rés-do-chão.',
                'is_active' => true,
            ],
        );

        $first = $this->provider('prestador1@example.test');
        $second = $this->provider('prestador2@example.test');
        $deactivated = $this->deactivatedProvider();

        foreach ([$first, $second, $deactivated] as $provider) {
            $provider->zones()->sync([$portoCentro->id]);
        }

        $this->weeklyHours($first, '09:00', '18:00');
        $this->weeklyHours($second, '08:00', '16:00');
        $this->weeklyHours($deactivated, '09:00', '18:00');

        $today = Carbon::today(Property::DEFAULT_TIMEZONE);

        $second->unavailabilities()->updateOrCreate(
            ['reason' => 'Férias (demonstração)'],
            [
                'starts_at' => $today->copy()->utc(),
                'ends_at' => $today->copy()->addDays(10)->utc(),
            ],
        );

        $this->demoTask($foz, $today);
        $this->demoTask($gaiaHouse, $today);
    }

    private function provider(string $email): ServiceProvider
    {
        $user = User::where('email', $email)->firstOrFail();

        return ServiceProvider::where('user_id', $user->id)->firstOrFail();
    }

    private function deactivatedProvider(): ServiceProvider
    {
        $user = User::firstOrNew(['email' => 'prestador3@example.test']);
        $user->name = 'Prestador Três';
        $user->role()->associate(Role::where('name', 'service_provider')->firstOrFail());
        $user->is_active = false;
        $user->password = 'Demo-Local-2026!';
        $user->save();

        return ServiceProvider::updateOrCreate(
            ['user_id' => $user->id],
            ['phone' => '910000003'],
        );
    }

    // The same hours every day of the week, so the demo works on any day.
    private function weeklyHours(ServiceProvider $provider, string $start, string $end): void
    {
        $provider->availabilities()->delete();

        foreach (range(1, 7) as $weekday) {
            $provider->availabilities()->create([
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
            ]);
        }
    }

    // Open, unassigned task from 11:30 to 14:30 (property time), created only once.
    private function demoTask(Property $property, Carbon $today): void
    {
        $exists = Task::withTrashed()
            ->where('property_id', $property->id)
            ->where('notes', self::DEMO_TASK_NOTE)
            ->exists();

        if ($exists) {
            return;
        }

        $day = $today->copy()->addDays(self::DEMO_TASK_DAYS_AHEAD);

        Task::factory()->create([
            'property_id' => $property->id,
            'service_provider_id' => null,
            'status' => 'pending',
            'scheduled_start_at' => $day->copy()->setTime(11, 30)->utc(),
            'scheduled_end_at' => $day->copy()->setTime(14, 30)->utc(),
            'notes' => self::DEMO_TASK_NOTE,
        ]);
    }
}
