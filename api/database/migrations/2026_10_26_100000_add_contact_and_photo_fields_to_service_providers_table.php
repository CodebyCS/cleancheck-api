<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->string('address', 200)->nullable()->after('phone');
            $table->string('postal_code', 8)->nullable()->after('address');
            $table->string('city', 80)->nullable()->after('postal_code');
            $table->string('photo_path')->nullable()->after('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->dropColumn(['address', 'postal_code', 'city', 'photo_path']);
        });
    }
};
