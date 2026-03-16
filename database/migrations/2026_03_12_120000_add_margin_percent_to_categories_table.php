<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'margin_percent')) {
                $table->decimal('margin_percent', 5, 2)->nullable()->after('min_margin');
            }

            if (! Schema::hasColumn('categories', 'fees')) {
                $table->json('fees')->nullable()->after('margin_percent');
            }
        });

        if (Schema::hasColumn('categories', 'margin_percent') && Schema::hasColumn('categories', 'min_margin')) {
            DB::table('categories')
                ->whereNull('margin_percent')
                ->update([
                    'margin_percent' => DB::raw('COALESCE(min_margin, 0)'),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('categories') || ! Schema::hasColumn('categories', 'margin_percent')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('margin_percent');
        });
    }
};
