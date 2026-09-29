<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // null 明確代表既有明文；不自行搬移或改寫原物件。
        Schema::table('files', fn (Blueprint $table) => $table->string('storage_encryption', 32)->nullable());
    }

    public function down(): void
    {
        Schema::table('files', fn (Blueprint $table) => $table->dropColumn('storage_encryption'));
    }
};
