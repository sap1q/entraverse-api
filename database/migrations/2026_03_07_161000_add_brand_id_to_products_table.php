<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->uuid('brand_id')->nullable()->after('brand');
                $table->foreign('brand_id')
                    ->references('id')
                    ->on('brands')
                    ->nullOnDelete();
                $table->index('brand_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropForeign(['brand_id']);
                $table->dropIndex(['brand_id']);
                $table->dropColumn('brand_id');
            });
        }
    }
};

