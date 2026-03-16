<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 20)->nullable()->after('phone');
            }

            if (! Schema::hasColumn('users', 'country')) {
                $table->string('country', 100)->nullable()->after('gender');
            }

            if (! Schema::hasColumn('users', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('country');
            }

            if (! Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable()->after('date_of_birth');
            }

            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'phone') ? 'phone' : null,
                Schema::hasColumn('users', 'gender') ? 'gender' : null,
                Schema::hasColumn('users', 'country') ? 'country' : null,
                Schema::hasColumn('users', 'date_of_birth') ? 'date_of_birth' : null,
                Schema::hasColumn('users', 'address') ? 'address' : null,
                Schema::hasColumn('users', 'avatar_path') ? 'avatar_path' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
