<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class DemoUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException(
                'Contas de demonstracao apenas para uso local.'
            );
        }

        $accounts = [
            ['Administrador', 'admin@example.test', 'admin'],
            ['Supervisor', 'supervisor@example.test', 'supervisor'],
            ['Prestador Um', 'prestador1@example.test', 'prestador'],
            ['Prestador Dois', 'prestador2@example.test', 'prestador'],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->name = $name;
            $user->role = $role;
            $user->is_active = true;
            $user->password = 'Demo-Local-2026!';
            $user->save();
        }

    }
}
