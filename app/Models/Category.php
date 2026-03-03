<?php

// app/Models/Category.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Category extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'categories';
    
    protected $fillable = [
        'name',
        'icon',
        'icon_svg',
        'icon_url',
        'fees',
        'program_garansi',
        'min_margin'
    ];

    protected $casts = [
        'fees' => 'array',
        'program_garansi' => 'array',
        'min_margin' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $appends = [
        'icon',
        'formatted_fees'
    ];

    protected $hidden = [
        'deleted_at'
    ];

    /**
     * Accessor untuk backward compatibility
     */
    public function getIconAttribute(): ?string
    {
        return $this->attributes['icon']
            ?? $this->attributes['icon_url']
            ?? $this->attributes['icon_svg']
            ?? null;
    }

    /**
     * Accessor untuk formatted fees
     */
    public function getFormattedFeesAttribute(): array
    {
        return [
            'marketplace' => $this->getFeeMarketplace(),
            'shopee' => $this->getFeeShopee(),
            'entraverse' => $this->getFeeEntraverse(),
            'tokopedia_tiktok' => $this->getFeeTokopediaTiktok()
        ];
    }

    /**
     * Get fee marketplace
     */
    public function getFeeMarketplace(): ?array
    {
        return $this->fees['marketplace'] ?? [
            'components' => []
        ];
    }

    /**
     * Get fee shopee
     */
    public function getFeeShopee(): ?array
    {
        return $this->fees['shopee'] ?? [
            'components' => []
        ];
    }

    /**
     * Get fee entraverse
     */
    public function getFeeEntraverse(): ?array
    {
        return $this->fees['entraverse'] ?? [
            'components' => []
        ];
    }

    /**
     * Get fee tokopedia/tiktok
     */
    public function getFeeTokopediaTiktok(): ?array
    {
        return $this->fees['tokopedia_tiktok'] ?? [
            'components' => []
        ];
    }

    /**
     * Store SVG file
     */
    public function storeSvgFile($file, $path = 'categories/icons'): string
    {
        $filename = $this->id . '-' . time() . '.svg';
        $filepath = $file->storeAs($path, $filename, 'public');
        return Storage::url($filepath);
    }

    /**
     * Set icon from file
     */
    public function setIconFromFile($file): self
    {
        $this->deleteIconFile();
        $path = $this->storeSvgFile($file);
        $this->icon = $path;
        $this->icon_url = $path;
        $this->icon_svg = null;
        return $this;
    }

    /**
     * Set icon from raw SVG
     */
    public function setIconFromRaw(string $svgContent): self
    {
        $this->deleteIconFile();
        $this->icon = $svgContent;
        $this->icon_svg = $svgContent;
        $this->icon_url = null;
        return $this;
    }

    /**
     * Delete icon file
     */
    public function deleteIconFile(): bool
    {
        $icon = (string) ($this->attributes['icon'] ?? $this->attributes['icon_url'] ?? '');
        if ($icon && str_starts_with($icon, '/storage/')) {
            $path = str_replace('/storage/', '', $icon);
            if (Storage::disk('public')->exists($path)) {
                return Storage::disk('public')->delete($path);
            }
        }
        return false;
    }

    /**
     * Get SVG content
     */
    public function getSvgContent(): ?string
    {
        $icon = (string) ($this->attributes['icon'] ?? '');
        if ($icon && str_contains($icon, '<svg')) {
            return $icon;
        }

        $iconUrl = (string) ($this->attributes['icon_url'] ?? $icon);
        if ($iconUrl && str_starts_with($iconUrl, '/storage/')) {
            $path = str_replace('/storage/', '', $iconUrl);
            if (Storage::disk('public')->exists($path)) {
                return Storage::disk('public')->get($path);
            }
        }
        
        return null;
    }

    /**
     * Calculate total fees for a platform
     */
    public function calculateTotalFees(string $platform): float
    {
        $fees = $this->fees[$platform]['components'] ?? [];
        $total = 0;
        
        foreach ($fees as $component) {
            $total += $component['value'] ?? 0;
        }
        
        return $total;
    }

    /**
     * Check if category has fees
     */
    public function hasFees(): bool
    {
        return !empty($this->fees);
    }

    /**
     * Scope active (not deleted)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Scope search by name
     */
    public function scopeSearch($query, $search)
    {
        return $query->where('name', 'LIKE', "%{$search}%");
    }

    /**
     * Scope with min margin
     */
    public function scopeMinMargin($query, $margin)
    {
        return $query->where('min_margin', '>=', $margin);
    }

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (is_null($category->fees)) {
                $category->fees = [
                    'marketplace' => ['components' => []],
                    'shopee' => ['components' => []],
                    'entraverse' => ['components' => []],
                    'tokopedia_tiktok' => ['components' => []]
                ];
            }
        });

        static::deleting(function ($category) {
            $category->deleteIconFile();
        });

        static::forceDeleting(function ($category) {
            $category->deleteIconFile();
        });
    }
}
