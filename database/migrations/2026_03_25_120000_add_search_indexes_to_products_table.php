<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_products_search_document
            ON products
            USING GIN (
                to_tsvector(
                    'simple',
                    COALESCE(name, '')
                    || ' ' || COALESCE(spu, '')
                    || ' ' || COALESCE(brand, '')
                    || ' ' || COALESCE(category, '')
                    || ' ' || COALESCE(description, '')
                )
            )
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_products_name_trgm
            ON products
            USING GIN (LOWER(name) gin_trgm_ops)
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_products_brand_trgm
            ON products
            USING GIN (LOWER(COALESCE(brand, '')) gin_trgm_ops)
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_products_category_trgm
            ON products
            USING GIN (LOWER(COALESCE(category, '')) gin_trgm_ops)
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_products_spu_trgm
            ON products
            USING GIN (LOWER(COALESCE(spu, '')) gin_trgm_ops)
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_products_search_document');
        DB::statement('DROP INDEX IF EXISTS idx_products_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_products_brand_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_products_category_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_products_spu_trgm');
    }
};
