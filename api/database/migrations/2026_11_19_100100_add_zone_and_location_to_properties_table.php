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
        Schema::table('properties', function (Blueprint $table) {
            // Zones are deactivated, not deleted, so linked properties keep their history.
            $table->foreignId('zone_id')->nullable()->after('city')->constrained('zones')->restrictOnDelete();
            // Fixed reference location of the property; no continuous GPS is stored.
            $table->decimal('latitude', 9, 6)->nullable()->after('zone_id');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
