<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE sales_orders
                ADD CONSTRAINT sales_orders_status_check
                CHECK (status IN ('dibayar', 'diproses', 'dikirim', 'terkirim', 'selesai', 'dibatalkan'))
            SQL);

            return;
        }

        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE sales_orders
                MODIFY status ENUM('dibayar', 'diproses', 'dikirim', 'terkirim', 'selesai', 'dibatalkan')
                NOT NULL DEFAULT 'dibayar'
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        DB::table('sales_orders')
            ->where('status', 'terkirim')
            ->update(['status' => 'dikirim']);

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE sales_orders
                ADD CONSTRAINT sales_orders_status_check
                CHECK (status IN ('dibayar', 'diproses', 'dikirim', 'selesai', 'dibatalkan'))
            SQL);

            return;
        }

        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE sales_orders
                MODIFY status ENUM('dibayar', 'diproses', 'dikirim', 'selesai', 'dibatalkan')
                NOT NULL DEFAULT 'dibayar'
            SQL);
        }
    }
};
