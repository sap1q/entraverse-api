<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('channel', 30)->unique();
            $table->string('status', 30)->default('disconnected')->index();
            $table->string('shop_id', 120)->nullable()->index();
            $table->string('shop_name', 255)->nullable();
            $table->string('seller_id', 120)->nullable()->index();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_inbound_sync_at')->nullable();
            $table->timestamp('last_outbound_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_connections');
    }
};
