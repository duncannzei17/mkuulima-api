<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Events\PriceHistoryRecorded;

class PriceHistory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'price_history';

    protected $fillable = [
        'crop_name',
        'variety',
        'unit',
        'price_per_unit',
        'price_date',
        'market_location',
        'price_type',
        'sale_id',
        'quality_grade',
        'quantity_sold',
        'season',
        'market_conditions',
        'demand_level',
        'supply_level',
        'created_by'
    ];

    protected $casts = [
        'price_per_unit' => 'decimal:2',
        'quantity_sold' => 'decimal:3',
        'demand_level' => 'decimal:2',
        'supply_level' => 'decimal:2',
        'price_date' => 'date'
    ];

    protected $attributes = [
        'price_type' => 'farmgate',
        'quality_grade' => 'mixed',
        'demand_level' => 3.0,
        'supply_level' => 3.0
    ];

    public const PRICE_TYPES = [
        'wholesale' => 'Wholesale',
        'retail' => 'Retail',
        'farmgate' => 'Farm Gate'
    ];

    public const QUALITY_GRADES = [
        'A' => 'Grade A (Premium)',
        'B' => 'Grade B (Good)',
        'C' => 'Grade C (Standard)',
        'mixed' => 'Mixed Quality',
        'unknown' => 'Unknown'
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function (PriceHistory $priceHistory) {
            event(new PriceHistoryRecorded($priceHistory));
        });
    }

    public const SEASONS = [
        'dry' => 'Dry Season',
        'wet' => 'Wet Season',
        'harvest' => 'Harvest Season'
    ];

    // Relationships
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeByCrop($query, string $cropName)
    {
        return $query->where('crop_name', $cropName);
    }

    public function scopeByVariety($query, string $variety)
    {
        return $query->where('variety', $variety);
    }

    public function scopeByPriceType($query, string $priceType)
    {
        return $query->where('price_type', $priceType);
    }

    public function scopeByDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('price_date', [$startDate, $endDate]);
    }

    public function scopeBySeason($query, string $season)
    {
        return $query->where('season', $season);
    }

    public function scopeByLocation($query, string $location)
    {
        return $query->where('market_location', 'LIKE', "%{$location}%");
    }

    public function scopeByQualityGrade($query, string $grade)
    {
        return $query->where('quality_grade', $grade);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('price_date', '>=', now()->subDays($days));
    }

    // Accessors
    public function getPriceTypeNameAttribute(): string
    {
        return self::PRICE_TYPES[$this->price_type] ?? $this->price_type;
    }

    public function getQualityGradeNameAttribute(): string
    {
        return self::QUALITY_GRADES[$this->quality_grade] ?? $this->quality_grade;
    }

    public function getSeasonNameAttribute(): string
    {
        return self::SEASONS[$this->season] ?? $this->season;
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price_per_unit, 2) . ' per ' . $this->unit;
    }

    // Static analysis methods

    /**
     * Get price trends for a crop over time
     */
    public static function getPriceTrends(string $cropName, ?int $months = 12): array
    {
        $startDate = now()->subMonths($months);
        
        return static::byCrop($cropName)
            ->byDateRange($startDate, now())
            ->selectRaw('
                YEAR(price_date) as year, 
                MONTH(price_date) as month,
                AVG(price_per_unit) as avg_price,
                MIN(price_per_unit) as min_price,
                MAX(price_per_unit) as max_price,
                COUNT(*) as price_points,
                SUM(quantity_sold) as total_quantity
            ')
            ->groupByRaw('YEAR(price_date), MONTH(price_date)')
            ->orderByRaw('year, month')
            ->get()
            ->map(function($item) {
                return [
                    'period' => $item->year . '-' . str_pad($item->month, 2, '0', STR_PAD_LEFT),
                    'average_price' => round($item->avg_price, 2),
                    'min_price' => $item->min_price,
                    'max_price' => $item->max_price,
                    'price_points' => $item->price_points,
                    'total_quantity' => $item->total_quantity
                ];
            })
            ->toArray();
    }

    /**
     * Get current market prices for crops
     */
    public static function getCurrentMarketPrices(?int $days = 7): array
    {
        return static::recent($days)
            ->selectRaw('
                crop_name,
                variety,
                unit,
                price_type,
                market_location,
                AVG(price_per_unit) as avg_price,
                MIN(price_per_unit) as min_price,
                MAX(price_per_unit) as max_price,
                COUNT(*) as price_points,
                MAX(price_date) as latest_date
            ')
            ->groupBy('crop_name', 'variety', 'unit', 'price_type', 'market_location')
            ->orderBy('crop_name')
            ->orderBy('variety')
            ->get()
            ->groupBy('crop_name')
            ->map(function($cropPrices) {
                return $cropPrices->map(function($price) {
                    return [
                        'variety' => $price->variety,
                        'unit' => $price->unit,
                        'price_type' => $price->price_type,
                        'market_location' => $price->market_location,
                        'average_price' => round($price->avg_price, 2),
                        'min_price' => $price->min_price,
                        'max_price' => $price->max_price,
                        'price_points' => $price->price_points,
                        'latest_date' => $price->latest_date
                    ];
                })->toArray();
            })
            ->toArray();
    }

    /**
     * Get price comparison across markets
     */
    public static function getMarketComparison(string $cropName, ?string $variety = null): array
    {
        $query = static::byCrop($cropName)->recent(30);
        
        if ($variety) {
            $query->byVariety($variety);
        }

        return $query->selectRaw('
                market_location,
                price_type,
                AVG(price_per_unit) as avg_price,
                MIN(price_per_unit) as min_price,
                MAX(price_per_unit) as max_price,
                COUNT(*) as price_points,
                AVG(demand_level) as avg_demand,
                AVG(supply_level) as avg_supply
            ')
            ->whereNotNull('market_location')
            ->groupBy('market_location', 'price_type')
            ->orderBy('market_location')
            ->get()
            ->groupBy('market_location')
            ->map(function($marketPrices) {
                return $marketPrices->map(function($price) {
                    return [
                        'price_type' => $price->price_type,
                        'average_price' => round($price->avg_price, 2),
                        'min_price' => $price->min_price,
                        'max_price' => $price->max_price,
                        'price_points' => $price->price_points,
                        'avg_demand_level' => round($price->avg_demand, 1),
                        'avg_supply_level' => round($price->avg_supply, 1)
                    ];
                })->toArray();
            })
            ->toArray();
    }

    /**
     * Get seasonal price patterns
     */
    public static function getSeasonalPatterns(string $cropName, ?int $years = 2): array
    {
        $startDate = now()->subYears($years);
        
        return static::byCrop($cropName)
            ->byDateRange($startDate, now())
            ->selectRaw('
                season,
                AVG(price_per_unit) as avg_price,
                MIN(price_per_unit) as min_price,
                MAX(price_per_unit) as max_price,
                STDDEV(price_per_unit) as price_volatility,
                COUNT(*) as price_points,
                AVG(demand_level) as avg_demand,
                AVG(supply_level) as avg_supply
            ')
            ->groupBy('season')
            ->get()
            ->mapWithKeys(function($item) {
                return [$item->season => [
                    'season_name' => self::SEASONS[$item->season] ?? $item->season,
                    'average_price' => round($item->avg_price, 2),
                    'min_price' => $item->min_price,
                    'max_price' => $item->max_price,
                    'price_volatility' => round($item->price_volatility ?? 0, 2),
                    'price_points' => $item->price_points,
                    'avg_demand_level' => round($item->avg_demand, 1),
                    'avg_supply_level' => round($item->avg_supply, 1)
                ]];
            })
            ->toArray();
    }

    /**
     * Get price forecasting data
     */
    public static function getPriceForecastData(string $cropName): array
    {
        $historicalData = static::byCrop($cropName)
            ->recent(365)
            ->orderBy('price_date')
            ->get(['price_date', 'price_per_unit', 'demand_level', 'supply_level'])
            ->toArray();

        $trends = static::getPriceTrends($cropName, 12);
        $seasonal = static::getSeasonalPatterns($cropName);

        return [
            'historical_data' => $historicalData,
            'monthly_trends' => $trends,
            'seasonal_patterns' => $seasonal,
            'volatility_index' => static::calculateVolatilityIndex($cropName),
            'price_stability' => static::calculatePriceStability($cropName)
        ];
    }

    /**
     * Calculate price volatility index
     */
    public static function calculateVolatilityIndex(string $cropName): float
    {
        $prices = static::byCrop($cropName)
            ->recent(90)
            ->pluck('price_per_unit')
            ->toArray();

        if (count($prices) < 2) {
            return 0;
        }

        $mean = array_sum($prices) / count($prices);
        $variance = array_sum(array_map(function($price) use ($mean) {
            return pow($price - $mean, 2);
        }, $prices)) / count($prices);

        $stdDev = sqrt($variance);
        
        return $mean > 0 ? round(($stdDev / $mean) * 100, 2) : 0;
    }

    /**
     * Calculate price stability score (1-5 scale)
     */
    public static function calculatePriceStability(string $cropName): float
    {
        $volatility = static::calculateVolatilityIndex($cropName);
        
        // Convert volatility percentage to 1-5 stability scale
        if ($volatility <= 5) return 5.0;      // Very stable
        if ($volatility <= 10) return 4.0;     // Stable
        if ($volatility <= 20) return 3.0;     // Moderate
        if ($volatility <= 35) return 2.0;     // Unstable
        return 1.0;                             // Very unstable
    }
}