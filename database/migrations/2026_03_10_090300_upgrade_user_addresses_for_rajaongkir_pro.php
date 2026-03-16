<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->renameColumn('label', 'address_label');
            $table->renameColumn('phone_number', 'recipient_phone');
            $table->renameColumn('address_line', 'address_detail');
            $table->renameColumn('is_main', 'is_default');
        });

        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->char('province_id', 2)->nullable()->after('address_label');
            $table->char('city_id', 4)->nullable()->after('province_id');
            $table->char('district_id', 7)->nullable()->after('city_id');
            $table->string('location_note')->nullable()->after('address_detail');
            $table->decimal('latitude', 10, 8)->nullable()->after('recipient_phone');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->boolean('is_active')->default(true)->after('is_default');

            $table->index(['user_id', 'is_default'], 'user_addresses_idx_default');
            $table->index('district_id', 'user_addresses_idx_district');
            $table->index('postal_code', 'user_addresses_idx_postal');
        });

        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->foreign('province_id')
                ->references('id')
                ->on('provinces')
                ->nullOnDelete();

            $table->foreign('city_id')
                ->references('id')
                ->on('cities')
                ->nullOnDelete();

            $table->foreign('district_id')
                ->references('id')
                ->on('districts')
                ->nullOnDelete();
        });

        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->dropColumn(['city', 'province']);
        });
    }

    public function down(): void
    {
        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->string('city')->nullable()->after('address_detail');
            $table->string('province')->nullable()->after('city');
        });

        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->dropForeign(['province_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['district_id']);

            $table->dropIndex('user_addresses_idx_default');
            $table->dropIndex('user_addresses_idx_district');
            $table->dropIndex('user_addresses_idx_postal');

            $table->dropColumn([
                'province_id',
                'city_id',
                'district_id',
                'location_note',
                'latitude',
                'longitude',
                'is_active',
            ]);
        });

        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->renameColumn('address_label', 'label');
            $table->renameColumn('recipient_phone', 'phone_number');
            $table->renameColumn('address_detail', 'address_line');
            $table->renameColumn('is_default', 'is_main');
        });
    }
};

