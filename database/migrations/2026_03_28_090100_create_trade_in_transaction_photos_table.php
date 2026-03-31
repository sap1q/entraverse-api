<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_in_transaction_photos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trade_in_transaction_id');
            $table->string('slot_id', 80)->nullable();
            $table->string('label', 120)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->text('image_url')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['trade_in_transaction_id', 'sort_order']);
            $table->index('slot_id');

            $table->foreign('trade_in_transaction_id')
                ->references('id')
                ->on('trade_in_transactions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_in_transaction_photos');
    }
};
