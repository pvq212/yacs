<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_deliveries', function (Blueprint $table): void {
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_deliveries', fn (Blueprint $table) => $table->dropColumn(['lease_token', 'lease_expires_at']));
    }
};
