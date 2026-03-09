<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasStatus = Schema::hasColumn('products', 'status');
        $hasFeatured = Schema::hasColumn('products', 'is_featured');
        $hasStockStatus = Schema::hasColumn('products', 'stock_status');

        Schema::table('products', function (Blueprint $table) use ($hasFeatured, $hasStatus, $hasStockStatus): void {
            if (! $hasStatus) {
                $table->enum('status', ['active', 'inactive', 'draft'])->default('active')->after('product_status');
                $table->index('status');
            }

            if (! $hasFeatured) {
                $table->boolean('is_featured')->default(false)->after('status');
                $table->index('is_featured');
            }

            if (! $hasStockStatus) {
                $table->enum('stock_status', ['in_stock', 'out_of_stock', 'preorder'])
                    ->default('in_stock')
                    ->after('is_featured');
                $table->index('stock_status');
            }
        });

        $columns = ['id', 'product_status', 'stock', 'inventory', 'status', 'is_featured', 'stock_status'];
        DB::table('products')
            ->select($columns)
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $legacyStatus = strtolower(trim((string) ($row->product_status ?? '')));
                    $mappedStatus = match ($legacyStatus) {
                        'active' => 'active',
                        'inactive' => 'inactive',
                        default => 'draft',
                    };

                    $inventory = [];
                    if (is_array($row->inventory)) {
                        $inventory = $row->inventory;
                    } elseif (is_string($row->inventory)) {
                        $decoded = json_decode($row->inventory, true);
                        if (is_array($decoded)) {
                            $inventory = $decoded;
                        }
                    }

                    $stockValue = is_numeric($row->stock ?? null)
                        ? (int) $row->stock
                        : (int) ($inventory['total_stock'] ?? 0);

                    $mappedStockStatus = $stockValue <= 0 ? 'out_of_stock' : 'in_stock';

                    DB::table('products')
                        ->where('id', $row->id)
                        ->update([
                            'status' => in_array($row->status ?? null, ['active', 'inactive', 'draft'], true)
                                ? $row->status
                                : $mappedStatus,
                            'is_featured' => (bool) ($row->is_featured ?? false),
                            'stock_status' => in_array($row->stock_status ?? null, ['in_stock', 'out_of_stock', 'preorder'], true)
                                ? $row->stock_status
                                : $mappedStockStatus,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'stock_status')) {
                $table->dropIndex(['stock_status']);
                $table->dropColumn('stock_status');
            }

            if (Schema::hasColumn('products', 'is_featured')) {
                $table->dropIndex(['is_featured']);
                $table->dropColumn('is_featured');
            }

            if (Schema::hasColumn('products', 'status')) {
                $table->dropIndex(['status']);
                $table->dropColumn('status');
            }
        });
    }
};

