<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Database\Seeder;

class DemoRecordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException(
                'Registos de demonstracao apenas para uso local.'
            );
        }

        Property::updateOrCreate(
            ['name' => 'Apartamento da Foz'],
            [
                'address' => 'Rua Exemplo 25',
                'postal_code' => '4150-001',
                'city' => 'Porto',
                'access_instructions' => 'Chave no cofre junto à porta.',
                'is_active' => true,
            ],
        );

        $providers = [
            ['prestador1@example.test', '910000001'],
            ['prestador2@example.test', '910000002'],
        ];

        foreach ($providers as [$email, $phone]) {
            $user = User::where('email', $email)->firstOrFail();

            ServiceProvider::updateOrCreate(
                ['user_id' => $user->id],
                ['phone' => $phone],
            );
        }
    }
}
