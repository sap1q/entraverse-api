<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_addresses', 'subdistrict')) {
            Schema::table('user_addresses', function (Blueprint $table): void {
                $table->string('subdistrict', 100)->nullable()->after('district_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_addresses', 'subdistrict')) {
            Schema::table('user_addresses', function (Blueprint $table): void {
                $table->dropColumn('subdistrict');
            });
        }
    }
};

