<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_roles_exist(): void
    {
        foreach (['admin', 'supervisor', 'prestador'] as $name) {
            $this->assertDatabaseHas('roles', [
                'name' => $name,
            ]);
        }
    }

    public function test_user_belongs_to_a_role(): void
    {
        $role = Role::where('name', 'admin')->firstOrFail();

        $user = User::factory()->create([
            'role_id' => $role->id,
        ]);

        $this->assertTrue($user->fresh()->role->is($role));
        $this->assertTrue($role->users()->whereKey($user->id)->exists());
    }

    public function test_database_rejects_a_missing_role(): void
    {
        $user = User::factory()->create();
        $missingRoleId = ((int) Role::max('id')) + 1;

        $this->expectException(QueryException::class);

        DB::table('users')
            ->where('id', $user->id)
            ->update(['role_id' => $missingRoleId]);
    }

    public function test_database_rejects_a_null_role(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')
            ->where('id', $user->id)
            ->update(['role_id' => null]);
    }

    public function test_assigned_role_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('roles')
            ->where('id', $user->role_id)
            ->delete();
    }

    public function test_role_names_are_unique(): void
    {
        $this->expectException(QueryException::class);

        DB::table('roles')->insert([
            'name' => 'admin',
        ]);
    }

    public function test_demo_users_receive_the_expected_roles(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoUserSeeder::class);

        $expected = [
            'admin@example.test' => 'admin',
            'supervisor@example.test' => 'supervisor',
            'prestador1@example.test' => 'prestador',
            'prestador2@example.test' => 'prestador',
        ];

        foreach ($expected as $email => $roleName) {
            $this->assertSame(
                1,
                User::where('email', $email)->count(),
            );

            $user = User::where('email', $email)->firstOrFail();

            $this->assertSame($roleName, $user->role->name);
        }
    }
}
