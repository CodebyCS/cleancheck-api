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
            $table->foreignId('responsible_service_provider_id')
                ->nullable()
                ->after('access_instructions')
                ->constrained('service_providers')
                ->restrictOnDelete();
            $table->decimal('base_cleaning_amount', 10, 2)->nullable()->after('responsible_service_provider_id');
            $table->time('default_check_in_time')->nullable()->after('base_cleaning_amount');
            $table->time('default_check_out_time')->nullable()->after('default_check_in_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_service_provider_id');
            $table->dropColumn(['base_cleaning_amount', 'default_check_in_time', 'default_check_out_time']);
        });
    }
};
