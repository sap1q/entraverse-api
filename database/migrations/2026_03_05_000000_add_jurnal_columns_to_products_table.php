<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('jurnal_id')->nullable()->unique();
            $table->jsonb('jurnal_metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_jurnal_id_unique');
            $table->dropColumn(['jurnal_id', 'jurnal_metadata', 'last_synced_at']);
        });
    }
};
