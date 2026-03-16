<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_addresses', 'postal_code') && ! Schema::hasColumn('user_addresses', 'zip_code')) {
            Schema::table('user_addresses', function (Blueprint $table): void {
                $table->renameColumn('postal_code', 'zip_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_addresses', 'zip_code') && ! Schema::hasColumn('user_addresses', 'postal_code')) {
            Schema::table('user_addresses', function (Blueprint $table): void {
                $table->renameColumn('zip_code', 'postal_code');
            });
        }
    }
};

