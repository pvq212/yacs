<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', fn (Blueprint $t) => $t->unsignedBigInteger('version')->default(0));
        Schema::table('provider_connections', fn (Blueprint $t) => $t->uuid('egress_policy_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('provider_connections', fn (Blueprint $t) => $t->dropColumn('egress_policy_id'));
        Schema::table('knowledge_documents', fn (Blueprint $t) => $t->dropColumn('version'));
    }
};
