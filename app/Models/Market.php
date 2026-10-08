<?php

namespace App\Models;

use App\Traits\HasUuids;
use App\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Market extends Model
{
    use HasFactory, HasUuids, TenantScope, SoftDeletes;

    protected $fillable = [
        'name',
        'market_type',
        'description',
        'county',
        'ward',
        'location',
        'latitude',
        'longitude',
        'distance_from_farm',
        'common_crops',
        'operating_days',
        'opening_time',
        'closing_time',
        'average_daily_volume',
        'vendor_count',
        'contact_phone',
        'contact_person',
        'has_cold_storage',
        'has_processing_facility',
        'has_transport_access',
        'payment_methods',
        'average_markup_percentage',
        'dominant_buyer_type',
        'price_volatility',
        'market_rating',
        'total_reviews',
        'is_active',
        'is_verified',
        'verification_status',
        'verified_by',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'common_crops' => 'array',
        'operating_days' => 'array',
        'opening_time' => 'datetime:H:i',
        'closing_time' => 'datetime:H:i',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'distance_from_farm' => 'decimal:2',
        'average_daily_volume' => 'decimal:2',
        'vendor_count' => 'integer',
        'has_cold_storage' => 'boolean',
        'has_processing_facility' => 'boolean',
        'has_transport_access' => 'boolean',
        'average_markup_percentage' => 'decimal:2',
        'price_volatility' => 'string',
        'market_rating' => 'decimal:2',
        'total_reviews' => 'integer',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public const MARKET_TYPES = [
        'local' => 'Local Market',
        'wholesale' => 'Wholesale Market',
        'retail' => 'Retail Market',
        'export' => 'Export Market',
        'farmers_market' => 'Farmers Market',
        'supermarket' => 'Supermarket',
        'processing' => 'Processing Center',
    ];

    public const BUYER_TYPES = [
        'retailer' => 'Retailer',
        'wholesaler' => 'Wholesaler',
        'processor' => 'Processor',
        'export_company' => 'Export Company',
        'restaurant' => 'Restaurant',
        'individual' => 'Individual Consumer',
    ];

    public const VOLATILITY_LEVELS = [
        'low' => 'Low Volatility',
        'medium' => 'Medium Volatility',
        'high' => 'High Volatility',
    ];

    public function marketPrices(): HasMany
    {
        return $this->hasMany(MarketPrice::class);
    }

    public function sellOrders(): HasMany
    {
        return $this->hasMany(SellOrder::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(MarketInsight::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function calculateDistance($farmLatitude, $farmLongitude): float
    {
        if (!$this->latitude || !$this->longitude) {
            return 0;
        }

        // Haversine formula to calculate distance
        $earthRadius = 6371; // km

        $latFrom = deg2rad($farmLatitude);
        $lonFrom = deg2rad($farmLongitude);
        $latTo = deg2rad($this->latitude);
        $lonTo = deg2rad($this->longitude);

        $latDiff = $latTo - $latFrom;
        $lonDiff = $lonTo - $lonFrom;

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos($latFrom) * cos($latTo) * 
             sin($lonDiff / 2) * sin($lonDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    public function updateDistance($farmLatitude, $farmLongitude): void
    {
        $this->distance_from_farm = $this->calculateDistance($farmLatitude, $farmLongitude);
        $this->save();
    }

    public function getCurrentPrices(string $cropName = null): array
    {
        $query = $this->marketPrices()
            ->where('price_date', '>=', now()->subDays(7))
            ->orderBy('price_date', 'desc');

        if ($cropName) {
            $query->where('crop_name', $cropName);
        }

        return $query->get()
            ->groupBy('crop_name')
            ->map(function ($prices) {
                $latest = $prices->first();
                return [
                    'crop_name' => $latest->crop_name,
                    'current_price' => $latest->average_price,
                    'price_range' => [
                        'min' => $latest->min_price,
                        'max' => $latest->max_price,
                    ],
                    'last_updated' => $latest->price_date,
                    'demand_level' => $latest->demand_level,
                    'supply_level' => $latest->supply_level,
                    'quality_rating' => $latest->quality_rating,
                ];
            })
            ->values()
            ->toArray();
    }

    public function getPriceHistory(string $cropName, int $days = 30): array
    {
        return $this->marketPrices()
            ->where('crop_name', $cropName)
            ->where('price_date', '>=', now()->subDays($days))
            ->orderBy('price_date', 'asc')
            ->get()
            ->map(function ($price) {
                return [
                    'date' => $price->price_date,
                    'price' => $price->average_price,
                    'demand' => $price->demand_level,
                    'supply' => $price->supply_level,
                    'change_percentage' => $price->price_change_percentage,
                ];
            })
            ->toArray();
    }

    public function getBestPriceCrops(int $limit = 5): array
    {
        return $this->marketPrices()
            ->where('price_date', '>=', now()->subDays(3))
            ->orderBy('average_price', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($price) {
                return [
                    'crop_name' => $price->crop_name,
                    'price' => $price->average_price,
                    'demand_level' => $price->demand_level,
                    'quality_required' => $price->grade,
                    'last_updated' => $price->price_date,
                ];
            })
            ->toArray();
    }

    public function isOperatingToday(): bool
    {
        $today = strtolower(now()->format('l')); // Monday, Tuesday, etc.
        $todayShort = strtolower(now()->format('D')); // Mon, Tue, etc.
        
        $operatingDays = array_map('strtolower', $this->operating_days ?? []);
        
        return in_array($today, $operatingDays) || in_array($todayShort, $operatingDays);
    }

    public function isOpenNow(): bool
    {
        if (!$this->isOperatingToday()) {
            return false;
        }

        if (!$this->opening_time || !$this->closing_time) {
            return true; // Assume open if no specific times
        }

        $now = now()->format('H:i');
        $openTime = $this->opening_time->format('H:i');
        $closeTime = $this->closing_time->format('H:i');

        return $now >= $openTime && $now <= $closeTime;
    }

    public function updateRating(float $rating): void
    {
        $currentRating = $this->market_rating ?? 0;
        $currentCount = $this->total_reviews ?? 0;
        
        $newCount = $currentCount + 1;
        $newRating = (($currentRating * $currentCount) + $rating) / $newCount;
        
        $this->update([
            'market_rating' => round($newRating, 2),
            'total_reviews' => $newCount,
        ]);
    }

    public function verify(User $verifier, string $notes = null): bool
    {
        $this->update([
            'is_verified' => true,
            'verification_status' => 'verified',
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'verification_notes' => $notes,
        ]);

        return true;
    }

    public function calculateMarketScore(): float
    {
        $score = 0;
        $maxScore = 100;

        // Base score from market rating (40%)
        if ($this->market_rating) {
            $score += ($this->market_rating / 5) * 40;
        }

        // Distance factor (20% - closer is better)
        if ($this->distance_from_farm) {
            $distanceScore = max(0, 20 - ($this->distance_from_farm / 10));
            $score += min(20, $distanceScore);
        } else {
            $score += 15; // Neutral if no distance data
        }

        // Facilities and services (20%)
        $facilityScore = 0;
        if ($this->has_cold_storage) $facilityScore += 7;
        if ($this->has_processing_facility) $facilityScore += 6;
        if ($this->has_transport_access) $facilityScore += 7;
        $score += $facilityScore;

        // Verification and activity (20%)
        if ($this->is_verified) $score += 10;
        if ($this->is_active) $score += 10;

        return round(min($maxScore, $score), 2);
    }

    public function getMarketIntelligence(): array
    {
        $recentPrices = $this->marketPrices()
            ->where('price_date', '>=', now()->subDays(30))
            ->orderBy('price_date', 'desc')
            ->get();

        $avgPrice = $recentPrices->avg('average_price') ?? 0;
        $priceVolatility = $recentPrices->isEmpty() ? 0 : 
            $recentPrices->map(fn($p) => $p->price_change_percentage)->filter()->avg();

        return [
            'market_score' => $this->calculateMarketScore(),
            'average_price_30d' => round($avgPrice, 2),
            'price_volatility' => round($priceVolatility ?? 0, 2),
            'active_crops' => $recentPrices->pluck('crop_name')->unique()->count(),
            'last_price_update' => $recentPrices->first()?->price_date,
            'is_open_now' => $this->isOpenNow(),
            'distance_km' => $this->distance_from_farm,
            'facilities' => [
                'cold_storage' => $this->has_cold_storage,
                'processing' => $this->has_processing_facility,
                'transport' => $this->has_transport_access,
            ],
            'dominant_buyer_type' => $this->dominant_buyer_type,
            'vendor_count' => $this->vendor_count,
        ];
    }

    public function scopeNearby($query, $latitude, $longitude, $radius = 50)
    {
        return $query->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw("
                *, (
                    6371 * acos(
                        cos(radians(?)) * 
                        cos(radians(latitude)) * 
                        cos(radians(longitude) - radians(?)) + 
                        sin(radians(?)) * 
                        sin(radians(latitude))
                    )
                ) AS distance
            ", [$latitude, $longitude, $latitude])
            ->having('distance', '<=', $radius)
            ->orderBy('distance', 'asc');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('market_type', $type);
    }

    public function scopeWithCrop($query, string $crop)
    {
        return $query->whereJsonContains('common_crops', $crop);
    }

    public function scopeOpenToday($query)
    {
        $today = strtolower(now()->format('D'));
        return $query->whereJsonContains('operating_days', $today);
    }

    public function getMarketTypeDisplayAttribute(): string
    {
        return self::MARKET_TYPES[$this->market_type] ?? $this->market_type;
    }

    public function getVolatilityDisplayAttribute(): string
    {
        return self::VOLATILITY_LEVELS[$this->price_volatility] ?? $this->price_volatility;
    }

    public function getStatusDisplayAttribute(): string
    {
        if (!$this->is_active) return 'Inactive';
        if (!$this->is_verified) return 'Unverified';
        return 'Active';
    }
}