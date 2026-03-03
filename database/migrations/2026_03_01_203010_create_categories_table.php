<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            
            // 🔥 KEMBALIKAN KE STRING dulu (yang frontend minta)
            $table->string('icon')->nullable();  // ← INI YANG DIMINTA FRONTEND
            
            $table->json('fees')->nullable();
            $table->text('program_garansi')->nullable();
            $table->decimal('min_margin', 5, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};