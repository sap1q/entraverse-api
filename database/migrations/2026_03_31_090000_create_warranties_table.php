<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('customer_name', 255);
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('invoice_number', 80);
            $table->string('serial_number', 120);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->unique(['invoice_number', 'serial_number'], 'warranties_invoice_serial_unique');
            $table->index('invoice_number');
            $table->index('serial_number');
            $table->index('product_id');
            $table->index('start_date');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
