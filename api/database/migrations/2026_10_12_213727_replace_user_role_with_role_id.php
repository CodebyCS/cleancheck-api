<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $roles = DB::table('roles')->pluck('id', 'name');

        $hasInvalidRoles = DB::table('users')
            ->where(function ($query) use ($roles) {
                $query->whereNotIn('role', $roles->keys()->all())
                    ->orWhereNull('role');
            })
            ->exists();

        if ($hasInvalidRoles) {
            throw new RuntimeException(
                'Existem utilizadores com perfis invalidos'.'Corrige esses dados antes de continuar'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->nullable()
                ->constrained('roles')
                ->restrictOnDelete();
        });

        foreach ($roles as $name => $id) {
            DB::table('users')
                ->where('role', $name)
                ->update(['role_id' => $id]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')
                ->nullable(false)
                ->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('prestador');
        });

        $roles = DB::table('roles')->pluck('name', 'id');

        foreach ($roles as $id => $name) {
            DB::table('users')
                ->where('role_id', $id)
                ->update(['role' => $name]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
