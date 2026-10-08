<?php

namespace App\Models;

use App\Traits\HasUuids;
use App\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class MarketPrice extends Model
{
    use HasFactory, HasUuids, TenantScope;

    protected $fillable = [
        'market_id',
        'crop_name',
        'crop_variety',
        'grade',
        'unit',
        'min_price',
        'max_price',
        'average_price',
        'modal_price',
        'wholesale_price',
        'retail_price',
        'price_date',
        'demand_level',
        'supply_level',
        'quantity_available',
        'quantity_sold',
        'quality_rating',
        'weather_conditions',
        'transport_cost_per_kg',
        'is_peak_season',
        'is_market_day',
        'data_source',
        'data_confidence',
        'reported_by',
        'notes',
        'price_change_percentage',
        'seasonal_deviation',
        'days_since_last_update',
        'is_price_alert',
        'predicted_next_price',
        'prediction_date',
        'prediction_confidence',
        'price_factors',
    ];

    protected $casts = [
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'average_price' => 'decimal:2',
        'modal_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'retail_price' => 'decimal:2',
        'price_date' => 'date',
        'quantity_available' => 'decimal:2',
        'quantity_sold' => 'decimal:2',
        'transport_cost_per_kg' => 'decimal:4',
        'is_peak_season' => 'boolean',
        'is_market_day' => 'boolean',
        'data_confidence' => 'decimal:2',
        'price_change_percentage' => 'decimal:2',
        'seasonal_deviation' => 'decimal:2',
        'days_since_last_update' => 'integer',
        'is_price_alert' => 'boolean',
        'predicted_next_price' => 'decimal:2',
        'prediction_date' => 'date',
        'prediction_confidence' => 'decimal:2',
        'price_factors' => 'array',
    ];

    public const DEMAND_LEVELS = [
        'very_low' => 'Very Low',
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'very_high' => 'Very High',
    ];

    public const SUPPLY_LEVELS = [
        'very_low' => 'Very Low (Shortage)',
        'low' => 'Low Supply',
        'normal' => 'Normal Supply',
        'high' => 'High Supply',
        'very_high' => 'Oversupply',
    ];

    public const QUALITY_RATINGS = [
        'poor' => 'Poor Quality',
        'fair' => 'Fair Quality',
        'good' => 'Good Quality',
        'excellent' => 'Excellent Quality',
    ];

    public const DATA_SOURCES = [
        'farmer_report' => 'Farmer Report',
        'market_survey' => 'Market Survey',
        'buyer_feedback' => 'Buyer Feedback',
        'government_data' => 'Government Data',
        'api_feed' => 'External API Feed',
    ];

    public const GRADE_TYPES = [
        'A' => 'Grade A (Premium)',
        'B' => 'Grade B (Good)',
        'C' => 'Grade C (Standard)',
        'mixed' => 'Mixed Grades',
        'premium' => 'Premium Quality',
        'export' => 'Export Quality',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function calculatePriceChange(): float
    {
        $previousPrice = static::where('market_id', $this->market_id)
            ->where('crop_name', $this->crop_name)
            ->where('grade', $this->grade)
            ->where('price_date', '<', $this->price_date)
            ->orderBy('price_date', 'desc')
            ->first();

        if (!$previousPrice || $previousPrice->average_price == 0) {
            return 0;
        }

        $change = (($this->average_price - $previousPrice->average_price) / $previousPrice->average_price) * 100;
        
        $this->price_change_percentage = round($change, 2);
        $this->save();

        return $this->price_change_percentage;
    }

    public function calculateSeasonalDeviation(): float
    {
        // Get historical data for same period in previous years
        $month = $this->price_date->month;
        $historicalPrices = static::where('market_id', $this->market_id)
            ->where('crop_name', $this->crop_name)
            ->where('grade', $this->grade)
            ->whereMonth('price_date', $month)
            ->where('price_date', '<', $this->price_date->startOfYear())
            ->pluck('average_price');

        if ($historicalPrices->isEmpty()) {
            return 0;
        }

        $seasonalAverage = $historicalPrices->avg();
        if ($seasonalAverage == 0) {
            return 0;
        }

        $deviation = (($this->average_price - $seasonalAverage) / $seasonalAverage) * 100;
        
        $this->seasonal_deviation = round($deviation, 2);
        $this->save();

        return $this->seasonal_deviation;
    }

    public function updateDaysFromLastUpdate(): void
    {
        $lastUpdate = static::where('market_id', $this->market_id)
            ->where('crop_name', $this->crop_name)
            ->where('grade', $this->grade)
            ->where('price_date', '<', $this->price_date)
            ->orderBy('price_date', 'desc')
            ->first();

        if ($lastUpdate) {
            $this->days_since_last_update = $this->price_date->diffInDays($lastUpdate->price_date);
            $this->save();
        }
    }

    public function checkPriceAlert(): bool
    {
        $changeThreshold = 15; // 15% change triggers alert
        $absoluteChange = abs($this->price_change_percentage);
        
        $this->is_price_alert = $absoluteChange >= $changeThreshold;
        $this->save();

        return $this->is_price_alert;
    }

    public function generatePricePrediction(int $daysAhead = 7): array
    {
        // Simple prediction based on trend analysis
        $historicalData = static::where('market_id', $this->market_id)
            ->where('crop_name', $this->crop_name)
            ->where('grade', $this->grade)
            ->where('price_date', '>=', now()->subDays(30))
            ->orderBy('price_date', 'asc')
            ->pluck('average_price', 'price_date');

        if ($historicalData->count() < 5) {
            return [
                'predicted_price' => $this->average_price,
                'confidence' => 0.3,
                'trend' => 'stable',
                'factors' => ['insufficient_data'],
            ];
        }

        // Calculate trend
        $prices = $historicalData->values();
        $n = $prices->count();
        $sumX = 0; $sumY = 0; $sumXY = 0; $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumX += $i;
            $sumY += $prices[$i];
            $sumXY += $i * $prices[$i];
            $sumX2 += $i * $i;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;

        $predictedPrice = $intercept + $slope * ($n + $daysAhead);
        $confidence = $this->calculatePredictionConfidence($historicalData);

        $trend = $slope > 0.5 ? 'increasing' : ($slope < -0.5 ? 'decreasing' : 'stable');

        $factors = $this->analyzePriceFactors();

        $this->update([
            'predicted_next_price' => round($predictedPrice, 2),
            'prediction_date' => now()->addDays($daysAhead),
            'prediction_confidence' => $confidence,
            'price_factors' => $factors,
        ]);

        return [
            'predicted_price' => round($predictedPrice, 2),
            'confidence' => $confidence,
            'trend' => $trend,
            'factors' => $factors,
            'days_ahead' => $daysAhead,
        ];
    }

    private function calculatePredictionConfidence($historicalData): float
    {
        // Base confidence on data quality and consistency
        $confidence = 0.5;

        // More data points increase confidence
        $dataPoints = $historicalData->count();
        $confidence += min(0.3, $dataPoints / 30 * 0.3);

        // Recent data increases confidence
        if ($this->days_since_last_update <= 3) {
            $confidence += 0.1;
        }

        // High data confidence increases prediction confidence
        $confidence += ($this->data_confidence ?? 0.5) * 0.1;

        // Consistent supply/demand levels increase confidence
        if (in_array($this->demand_level, ['normal', 'high']) && 
            in_array($this->supply_level, ['normal', 'high'])) {
            $confidence += 0.1;
        }

        return round(min(1.0, $confidence), 2);
    }

    private function analyzePriceFactors(): array
    {
        $factors = [];

        // Demand/Supply analysis
        if ($this->demand_level === 'very_high' && $this->supply_level === 'very_low') {
            $factors[] = 'high_demand_low_supply';
        } elseif ($this->demand_level === 'very_low' && $this->supply_level === 'very_high') {
            $factors[] = 'low_demand_high_supply';
        }

        // Seasonal factors
        if ($this->is_peak_season) {
            $factors[] = 'peak_season';
        }

        // Quality factors
        if ($this->quality_rating === 'excellent') {
            $factors[] = 'premium_quality';
        } elseif ($this->quality_rating === 'poor') {
            $factors[] = 'quality_issues';
        }

        // Weather factors
        if ($this->weather_conditions && str_contains(strtolower($this->weather_conditions), 'rain')) {
            $factors[] = 'weather_impact';
        }

        // Transport cost factors
        if ($this->transport_cost_per_kg > 5) {
            $factors[] = 'high_transport_costs';
        }

        return $factors;
    }

    public function getMarketCondition(): string
    {
        $demandScore = array_search($this->demand_level, array_keys(self::DEMAND_LEVELS)) + 1;
        $supplyScore = array_search($this->supply_level, array_keys(self::SUPPLY_LEVELS)) + 1;

        if ($demandScore >= 4 && $supplyScore <= 2) {
            return 'sellers_market'; // High demand, low supply
        } elseif ($demandScore <= 2 && $supplyScore >= 4) {
            return 'buyers_market'; // Low demand, high supply
        } else {
            return 'balanced_market';
        }
    }

    public function getRecommendedSellingPrice(): array
    {
        $condition = $this->getMarketCondition();
        $basePrice = $this->average_price;

        switch ($condition) {
            case 'sellers_market':
                $recommendedPrice = $basePrice * 1.1; // 10% premium
                $confidence = 'high';
                $reason = 'High demand and low supply favor sellers';
                break;
            
            case 'buyers_market':
                $recommendedPrice = $basePrice * 0.95; // 5% below market
                $confidence = 'medium';
                $reason = 'Oversupply situation, competitive pricing needed';
                break;
            
            default:
                $recommendedPrice = $basePrice;
                $confidence = 'medium';
                $reason = 'Balanced market conditions';
        }

        return [
            'recommended_price' => round($recommendedPrice, 2),
            'price_range' => [
                'min' => round($this->min_price, 2),
                'max' => round($this->max_price, 2),
            ],
            'market_condition' => $condition,
            'confidence' => $confidence,
            'reason' => $reason,
            'last_updated' => $this->price_date->format('Y-m-d'),
        ];
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('price_date', '>=', now()->subDays($days));
    }

    public function scopeByCrop($query, string $crop)
    {
        return $query->where('crop_name', $crop);
    }

    public function scopeByGrade($query, string $grade)
    {
        return $query->where('grade', $grade);
    }

    public function scopeByMarket($query, string $marketId)
    {
        return $query->where('market_id', $marketId);
    }

    public function scopePriceAlerts($query)
    {
        return $query->where('is_price_alert', true);
    }

    public function scopeHighConfidence($query, float $threshold = 0.7)
    {
        return $query->where('data_confidence', '>=', $threshold);
    }

    public function getDemandLevelDisplayAttribute(): string
    {
        return self::DEMAND_LEVELS[$this->demand_level] ?? $this->demand_level;
    }

    public function getSupplyLevelDisplayAttribute(): string
    {
        return self::SUPPLY_LEVELS[$this->supply_level] ?? $this->supply_level;
    }

    public function getQualityDisplayAttribute(): string
    {
        return self::QUALITY_RATINGS[$this->quality_rating] ?? $this->quality_rating;
    }

    public function getDataSourceDisplayAttribute(): string
    {
        return self::DATA_SOURCES[$this->data_source] ?? $this->data_source;
    }

    public function getGradeDisplayAttribute(): string
    {
        return self::GRADE_TYPES[$this->grade] ?? $this->grade;
    }
}