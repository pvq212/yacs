<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', fn (Blueprint $table) => $table->string('storage_disk', 64)->nullable());
        // migration owner 讀取既有環境的原磁碟名稱；不搬移、不上傳物件。
        DB::table('files')->whereNull('storage_disk')->update(['storage_disk' => (string) config('yacs.files.disk', 'private')]);
    }

    public function down(): void
    {
        Schema::table('files', fn (Blueprint $table) => $table->dropColumn('storage_disk'));
    }
};
