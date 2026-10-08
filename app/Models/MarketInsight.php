<?php

namespace App\Models;

use App\Traits\HasUuids;
use App\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class MarketInsight extends Model
{
    use HasFactory, HasUuids, TenantScope, SoftDeletes;

    protected $fillable = [
        'insight_type',
        'title',
        'description',
        'recommendation',
        'crop_name',
        'market_id',
        'location_scope',
        'severity',
        'urgency',
        'is_actionable',
        'action_deadline',
        'price_change_percentage',
        'current_price',
        'predicted_price',
        'confidence_score',
        'supporting_data',
        'affected_metrics',
        'insight_date',
        'valid_from',
        'valid_until',
        'is_time_sensitive',
        'days_ahead_forecast',
        'data_source',
        'generated_by',
        'algorithm_version',
        'generation_parameters',
        'potential_impact',
        'estimated_financial_impact',
        'farmers_affected',
        'market_share_affected',
        'views_count',
        'shares_count',
        'farmers_acted',
        'average_rating',
        'ratings_count',
        'status',
        'is_featured',
        'send_notification',
        'published_at',
        'superseded_by',
        'farmer_feedback',
        'was_accurate',
        'accuracy_score',
        'accuracy_notes',
        'validated_at',
    ];

    protected $casts = [
        'is_actionable' => 'boolean',
        'action_deadline' => 'date',
        'price_change_percentage' => 'decimal:2',
        'current_price' => 'decimal:2',
        'predicted_price' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'supporting_data' => 'array',
        'affected_metrics' => 'array',
        'insight_date' => 'date',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_time_sensitive' => 'boolean',
        'days_ahead_forecast' => 'integer',
        'generation_parameters' => 'array',
        'estimated_financial_impact' => 'decimal:2',
        'farmers_affected' => 'integer',
        'market_share_affected' => 'decimal:2',
        'views_count' => 'integer',
        'shares_count' => 'integer',
        'farmers_acted' => 'integer',
        'average_rating' => 'decimal:2',
        'ratings_count' => 'integer',
        'is_featured' => 'boolean',
        'send_notification' => 'boolean',
        'published_at' => 'datetime',
        'was_accurate' => 'boolean',
        'accuracy_score' => 'decimal:2',
        'validated_at' => 'datetime',
    ];

    public const INSIGHT_TYPES = [
        'price_alert' => 'Price Alert',
        'demand_forecast' => 'Demand Forecast',
        'supply_shortage' => 'Supply Shortage',
        'supply_oversupply' => 'Supply Oversupply',
        'weather_impact' => 'Weather Impact',
        'seasonal_trend' => 'Seasonal Trend',
        'market_opportunity' => 'Market Opportunity',
        'buyer_behavior' => 'Buyer Behavior',
        'crop_recommendation' => 'Crop Recommendation',
        'harvest_timing' => 'Harvest Timing',
        'transport_cost' => 'Transport Cost',
        'quality_alert' => 'Quality Alert',
    ];

    public const SEVERITIES = [
        'info' => 'Information',
        'warning' => 'Warning',
        'critical' => 'Critical',
    ];

    public const URGENCY_LEVELS = [
        'low' => 'Low Priority',
        'medium' => 'Medium Priority',
        'high' => 'High Priority',
        'immediate' => 'Immediate Action Required',
    ];

    public const DATA_SOURCES = [
        'price_analysis' => 'Price Analysis',
        'sales_pattern' => 'Sales Pattern',
        'weather_api' => 'Weather API',
        'market_survey' => 'Market Survey',
        'buyer_feedback' => 'Buyer Feedback',
        'farmer_reports' => 'Farmer Reports',
        'government_data' => 'Government Data',
        'ai_analysis' => 'AI Analysis',
        'manual_entry' => 'Manual Entry',
    ];

    public const POTENTIAL_IMPACTS = [
        'low' => 'Low Impact',
        'medium' => 'Medium Impact',
        'high' => 'High Impact',
        'very_high' => 'Very High Impact',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'published' => 'Published',
        'expired' => 'Expired',
        'superseded' => 'Superseded',
        'archived' => 'Archived',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(MarketInsight::class, 'superseded_by');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($insight) {
            $insight->insight_date = $insight->insight_date ?? now()->toDateString();
            $insight->published_at = now();
        });

        static::created(function ($insight) {
            if ($insight->send_notification) {
                $insight->notifyRelevantFarmers();
            }
        });
    }

    public function generatePriceAlert(MarketPrice $price): array
    {
        $changePercentage = abs($price->price_change_percentage);
        
        if ($changePercentage < 10) {
            return []; // No alert for small changes
        }

        $direction = $price->price_change_percentage > 0 ? 'increased' : 'decreased';
        $severity = $changePercentage > 25 ? 'critical' : 'warning';
        $urgency = $changePercentage > 25 ? 'high' : 'medium';

        $title = "{$price->crop_name} price {$direction} by {$changePercentage}%";
        $description = "The market price for {$price->crop_name} has {$direction} from KES {$price->min_price} to KES {$price->average_price} per {$price->unit}.";
        
        $recommendation = $this->generatePriceAlertRecommendation($price, $direction, $changePercentage);

        return [
            'insight_type' => 'price_alert',
            'title' => $title,
            'description' => $description,
            'recommendation' => $recommendation,
            'crop_name' => $price->crop_name,
            'market_id' => $price->market_id,
            'severity' => $severity,
            'urgency' => $urgency,
            'price_change_percentage' => $price->price_change_percentage,
            'current_price' => $price->average_price,
            'confidence_score' => $price->data_confidence,
            'data_source' => 'price_analysis',
            'is_actionable' => true,
            'send_notification' => $changePercentage > 15,
        ];
    }

    private function generatePriceAlertRecommendation($price, $direction, $changePercentage): string
    {
        if ($direction === 'increased') {
            if ($changePercentage > 25) {
                return "Excellent selling opportunity! Consider harvesting and selling immediately to capture the high prices. This is a {$changePercentage}% increase from recent averages.";
            } else {
                return "Good time to sell {$price->crop_name}. Prices have increased by {$changePercentage}%. Monitor closely as prices may continue to rise.";
            }
        } else {
            if ($changePercentage > 25) {
                return "Prices have dropped significantly by {$changePercentage}%. Consider delaying harvest if possible, or explore alternative markets with better prices.";
            } else {
                return "Prices have decreased by {$changePercentage}%. Evaluate if current prices are still profitable before selling.";
            }
        }
    }

    public function generateDemandForecast(string $cropName, string $location): array
    {
        // Analyze recent buying patterns and market activity
        $recentSales = Sale::where('crop_name', $cropName)
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        $recentOffers = BuyerOffer::where('crop_name', $cropName)
            ->where('status', 'active')
            ->where('created_at', '>=', now()->subDays(14))
            ->get();

        if ($recentSales->isEmpty() && $recentOffers->isEmpty()) {
            return []; // No data for forecast
        }

        $salesTrend = $this->analyzeSalesTrend($recentSales);
        $buyerInterest = $this->analyzeBuyerInterest($recentOffers);

        $demandLevel = $this->calculateDemandLevel($salesTrend, $buyerInterest);
        $confidence = min(0.8, $recentSales->count() / 20); // Max 80% confidence

        $title = "Demand forecast for {$cropName} in {$location}";
        $description = $this->generateDemandDescription($cropName, $demandLevel, $salesTrend);
        $recommendation = $this->generateDemandRecommendation($demandLevel, $cropName);

        return [
            'insight_type' => 'demand_forecast',
            'title' => $title,
            'description' => $description,
            'recommendation' => $recommendation,
            'crop_name' => $cropName,
            'location_scope' => $location,
            'severity' => $demandLevel === 'very_high' ? 'info' : 'info',
            'urgency' => $demandLevel === 'very_high' ? 'high' : 'medium',
            'confidence_score' => $confidence,
            'supporting_data' => [
                'recent_sales' => $recentSales->count(),
                'active_offers' => $recentOffers->count(),
                'sales_trend' => $salesTrend,
                'buyer_interest' => $buyerInterest,
            ],
            'data_source' => 'sales_pattern',
            'days_ahead_forecast' => 14,
        ];
    }

    private function analyzeSalesTrend($sales): string
    {
        if ($sales->count() < 5) {
            return 'stable';
        }

        $recent = $sales->where('created_at', '>=', now()->subDays(15))->count();
        $previous = $sales->where('created_at', '<', now()->subDays(15))->count();

        if ($recent > $previous * 1.5) {
            return 'increasing';
        } elseif ($recent < $previous * 0.7) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }

    private function analyzeBuyerInterest($offers): string
    {
        $totalQuantity = $offers->sum('quantity_needed');
        $averagePrice = $offers->avg('offered_price_per_unit');

        if ($offers->count() > 5 && $totalQuantity > 1000) {
            return 'very_high';
        } elseif ($offers->count() > 2) {
            return 'high';
        } else {
            return 'normal';
        }
    }

    private function calculateDemandLevel($salesTrend, $buyerInterest): string
    {
        if ($salesTrend === 'increasing' && $buyerInterest === 'very_high') {
            return 'very_high';
        } elseif ($salesTrend === 'increasing' || $buyerInterest === 'high') {
            return 'high';
        } elseif ($salesTrend === 'decreasing') {
            return 'low';
        } else {
            return 'normal';
        }
    }

    private function generateDemandDescription($cropName, $demandLevel, $salesTrend): string
    {
        $demandTexts = [
            'very_high' => 'exceptionally high',
            'high' => 'above average',
            'normal' => 'normal',
            'low' => 'below average',
        ];

        $trendTexts = [
            'increasing' => 'increasing',
            'stable' => 'stable',
            'decreasing' => 'declining',
        ];

        return "Current demand for {$cropName} is {$demandTexts[$demandLevel]} with {$trendTexts[$salesTrend]} sales activity over the past month.";
    }

    private function generateDemandRecommendation($demandLevel, $cropName): string
    {
        switch ($demandLevel) {
            case 'very_high':
                return "Excellent market conditions for {$cropName}! Consider increasing production and harvest immediately if ready. Multiple buyers are actively seeking this crop.";
            
            case 'high':
                return "Good demand for {$cropName}. Plan harvest timing to capitalize on current buyer interest. Consider negotiating premium prices.";
            
            case 'low':
                return "Lower demand detected for {$cropName}. Consider delaying harvest, exploring alternative markets, or processing for value addition.";
            
            default:
                return "Stable demand for {$cropName}. Continue with normal harvest and sales planning.";
        }
    }

    public function generateWeatherImpactInsight($weatherData, $affectedCrops): array
    {
        $severity = $this->assessWeatherSeverity($weatherData);
        $impact = $this->calculateWeatherImpact($weatherData, $affectedCrops);

        return [
            'insight_type' => 'weather_impact',
            'title' => "Weather impact alert: {$weatherData['condition']}",
            'description' => $this->generateWeatherDescription($weatherData, $affectedCrops),
            'recommendation' => $this->generateWeatherRecommendation($weatherData, $impact),
            'severity' => $severity,
            'urgency' => $severity === 'critical' ? 'immediate' : 'high',
            'supporting_data' => $weatherData,
            'affected_metrics' => $affectedCrops,
            'data_source' => 'weather_api',
            'is_time_sensitive' => true,
            'valid_until' => now()->addDays(7)->toDateString(),
            'send_notification' => true,
        ];
    }

    private function assessWeatherSeverity($weatherData): string
    {
        if (isset($weatherData['rainfall']) && $weatherData['rainfall'] > 50) {
            return 'critical'; // Heavy rainfall
        } elseif (isset($weatherData['temperature']) && $weatherData['temperature'] > 35) {
            return 'warning'; // High temperature
        } else {
            return 'info';
        }
    }

    private function calculateWeatherImpact($weatherData, $crops): array
    {
        // Calculate potential impact on different crops
        $impacts = [];
        
        foreach ($crops as $crop) {
            $impacts[$crop] = $this->getCropWeatherSensitivity($crop, $weatherData);
        }
        
        return $impacts;
    }

    private function getCropWeatherSensitivity($crop, $weatherData): string
    {
        // Define crop weather sensitivities
        $sensitiveCrops = ['lettuce', 'spinach', 'herbs'];
        $moderateCrops = ['tomatoes', 'peppers', 'beans'];
        
        if (in_array(strtolower($crop), $sensitiveCrops)) {
            return 'high_impact';
        } elseif (in_array(strtolower($crop), $moderateCrops)) {
            return 'medium_impact';
        } else {
            return 'low_impact';
        }
    }

    private function generateWeatherDescription($weatherData, $crops): string
    {
        $condition = $weatherData['condition'] ?? 'weather event';
        $affectedCropsText = implode(', ', $crops);
        
        return "Current {$condition} conditions may affect crop quality and market prices. Affected crops include: {$affectedCropsText}.";
    }

    private function generateWeatherRecommendation($weatherData, $impacts): string
    {
        $highImpactCrops = array_keys(array_filter($impacts, fn($impact) => $impact === 'high_impact'));
        
        if (!empty($highImpactCrops)) {
            $cropsText = implode(', ', $highImpactCrops);
            return "Take immediate protective action for {$cropsText}. Consider early harvest for ready produce and implement protective measures for growing crops.";
        } else {
            return "Monitor crop conditions closely and be prepared to adjust harvest timing if weather conditions persist.";
        }
    }

    public function recordView(): void
    {
        $this->increment('views_count');
    }

    public function recordShare(): void
    {
        $this->increment('shares_count');
    }

    public function recordAction(): void
    {
        $this->increment('farmers_acted');
    }

    public function addRating(float $rating): void
    {
        $currentTotal = $this->average_rating * $this->ratings_count;
        $newTotal = $currentTotal + $rating;
        $newCount = $this->ratings_count + 1;
        
        $this->update([
            'average_rating' => round($newTotal / $newCount, 2),
            'ratings_count' => $newCount,
        ]);
    }

    public function validateAccuracy(bool $wasAccurate, float $accuracyScore, string $notes = null): void
    {
        $this->update([
            'was_accurate' => $wasAccurate,
            'accuracy_score' => $accuracyScore,
            'accuracy_notes' => $notes,
            'validated_at' => now(),
        ]);
    }

    public function supersede(MarketInsight $newInsight): void
    {
        $this->update([
            'status' => 'superseded',
            'superseded_by' => $newInsight->id,
        ]);
    }

    private function notifyRelevantFarmers(): void
    {
        // Logic to notify farmers based on insight relevance
        event(new \App\Events\MarketInsightPublished($this));
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByCrop($query, string $crop)
    {
        return $query->where('crop_name', $crop);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('insight_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeActionable($query)
    {
        return $query->where('is_actionable', true);
    }

    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('valid_until')
              ->orWhere('valid_until', '>=', now());
        });
    }

    public function getInsightTypeDisplayAttribute(): string
    {
        return self::INSIGHT_TYPES[$this->insight_type] ?? $this->insight_type;
    }

    public function getSeverityDisplayAttribute(): string
    {
        return self::SEVERITIES[$this->severity] ?? $this->severity;
    }

    public function getUrgencyDisplayAttribute(): string
    {
        return self::URGENCY_LEVELS[$this->urgency] ?? $this->urgency;
    }

    public function getDataSourceDisplayAttribute(): string
    {
        return self::DATA_SOURCES[$this->data_source] ?? $this->data_source;
    }

    public function getStatusDisplayAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getIsValidAttribute(): bool
    {
        return $this->valid_until === null || now()->lte($this->valid_until);
    }
}