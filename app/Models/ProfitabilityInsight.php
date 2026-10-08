<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasUuids;
use App\Events\ProfitabilityInsightGenerated;
use Carbon\Carbon;

class ProfitabilityInsight extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'farm_id',
        'crop_cycle_id',
        'insight_type',
        'priority',
        'category',
        'title',
        'message',
        'recommendation',
        'data_points',
        'potential_impact_amount',
        'impact_type',
        'confidence_score',
        'is_actionable',
        'suggested_actions',
        'action_deadline',
        'status',
        'viewed_at',
        'acknowledged_at',
        'acted_upon_at',
        'action_notes',
        'crop_type',
        'season',
        'comparison_data',
        'trend_data'
    ];

    protected $casts = [
        'data_points' => 'array',
        'potential_impact_amount' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'is_actionable' => 'boolean',
        'suggested_actions' => 'array',
        'action_deadline' => 'date',
        'viewed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'acted_upon_at' => 'datetime',
        'comparison_data' => 'array',
        'trend_data' => 'array'
    ];

    // Relationships
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    // Status management
    public function markAsViewed(): void
    {
        if ($this->status === 'new') {
            $this->update([
                'status' => 'viewed',
                'viewed_at' => now()
            ]);
        }
    }

    public function acknowledge(string $notes = null): void
    {
        $this->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'action_notes' => $notes
        ]);
    }

    public function markAsActedUpon(string $actionNotes): void
    {
        $this->update([
            'status' => 'acted_upon',
            'acted_upon_at' => now(),
            'action_notes' => $actionNotes
        ]);
    }

    public function dismiss(): void
    {
        $this->update(['status' => 'dismissed']);
    }

    // Utility methods
    public function isOverdue(): bool
    {
        return $this->action_deadline && 
               $this->action_deadline->isPast() && 
               !in_array($this->status, ['acted_upon', 'dismissed']);
    }

    public function isHighPriority(): bool
    {
        return in_array($this->priority, ['high', 'critical']);
    }

    public function getImpactDescription(): string
    {
        if (!$this->potential_impact_amount) {
            return 'Impact amount not calculated';
        }

        $amount = number_format($this->potential_impact_amount, 2);
        $type = $this->impact_type;

        return match($type) {
            'cost_saving' => "Potential cost saving: KES {$amount}",
            'revenue_increase' => "Potential revenue increase: KES {$amount}",
            'efficiency_gain' => "Potential efficiency gain: {$amount}%",
            default => "Potential impact: KES {$amount}"
        };
    }

    // Scopes
    public function scopeForFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    public function scopeForCropCycle($query, $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('insight_type', $type);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActionable($query)
    {
        return $query->where('is_actionable', true);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'critical']);
    }

    public function scopeUnread($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeOverdue($query)
    {
        return $query->where('action_deadline', '<', now())
                    ->whereNotIn('status', ['acted_upon', 'dismissed']);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // Static factory methods
    public static function createCostAlert(
        string $farmId, 
        string $cropCycleId, 
        string $costType, 
        float $percentage, 
        float $threshold = 40
    ): self {
        $insight = static::create([
            'farm_id' => $farmId,
            'crop_cycle_id' => $cropCycleId,
            'insight_type' => 'cost_alert',
            'priority' => $percentage > $threshold * 1.5 ? 'high' : 'medium',
            'category' => 'cost_management',
            'title' => "High {$costType} Costs Detected",
            'message' => "{$costType} costs represent {$percentage}% of total production costs, which is above the recommended threshold of {$threshold}%.",
            'recommendation' => "Review {$costType} allocation and consider optimization strategies to reduce costs.",
            'data_points' => [
                'cost_type' => $costType,
                'current_percentage' => $percentage,
                'threshold' => $threshold,
                'variance' => $percentage - $threshold
            ],
            'confidence_score' => 95.0,
            'is_actionable' => true,
            'suggested_actions' => [
                "Analyze {$costType} breakdown in detail",
                "Compare with industry benchmarks",
                "Identify optimization opportunities",
                "Implement cost reduction strategies"
            ],
            'action_deadline' => now()->addWeeks(2)
        ]);

        event(new ProfitabilityInsightGenerated($insight));
        return $insight;
    }

    public static function createYieldPerformanceInsight(
        string $farmId,
        string $cropCycleId,
        float $yieldEfficiency,
        float $expectedYield,
        float $actualYield
    ): self {
        $priority = match(true) {
            $yieldEfficiency < 50 => 'critical',
            $yieldEfficiency < 70 => 'high',
            $yieldEfficiency < 85 => 'medium',
            default => 'low'
        };

        $insight = static::create([
            'farm_id' => $farmId,
            'crop_cycle_id' => $cropCycleId,
            'insight_type' => 'yield_performance',
            'priority' => $priority,
            'category' => 'yield_optimization',
            'title' => "Yield Performance Analysis",
            'message' => "Current yield efficiency is {$yieldEfficiency}%. You achieved {$actualYield}kg out of expected {$expectedYield}kg.",
            'recommendation' => $yieldEfficiency < 70 
                ? "Review farming practices, soil health, and input quality to improve yields."
                : "Good yield performance. Consider optimizing further for maximum efficiency.",
            'data_points' => [
                'yield_efficiency' => $yieldEfficiency,
                'expected_yield' => $expectedYield,
                'actual_yield' => $actualYield,
                'yield_gap' => $expectedYield - $actualYield
            ],
            'potential_impact_amount' => ($expectedYield - $actualYield) * 50, // Assume 50 KES per kg
            'impact_type' => 'revenue_increase',
            'confidence_score' => 90.0,
            'is_actionable' => $yieldEfficiency < 85,
            'suggested_actions' => $yieldEfficiency < 70 ? [
                "Conduct soil testing",
                "Review irrigation practices",
                "Check input quality and timing",
                "Consider pest and disease management"
            ] : [
                "Monitor current practices",
                "Consider yield optimization techniques",
                "Benchmark against similar farms"
            ],
            'action_deadline' => now()->addWeeks($priority === 'critical' ? 1 : 3)
        ]);

        event(new ProfitabilityInsightGenerated($insight));
        return $insight;
    }

    public static function createProfitWarning(
        string $farmId,
        string $cropCycleId,
        float $profitMargin,
        float $netProfit
    ): self {
        $priority = match(true) {
            $netProfit < 0 => 'critical',
            $profitMargin < 10 => 'high',
            $profitMargin < 20 => 'medium',
            default => 'low'
        };

        $insight = static::create([
            'farm_id' => $farmId,
            'crop_cycle_id' => $cropCycleId,
            'insight_type' => 'profit_warning',
            'priority' => $priority,
            'category' => 'revenue_enhancement',
            'title' => $netProfit < 0 ? "Loss Detected" : "Low Profit Margin",
            'message' => $netProfit < 0 
                ? "This crop cycle is showing a loss of KES " . number_format(abs($netProfit), 2)
                : "Profit margin is {$profitMargin}%, which is below optimal levels.",
            'recommendation' => $netProfit < 0
                ? "Urgent review needed. Analyze cost structure and market prices to identify improvement areas."
                : "Focus on cost reduction strategies or explore premium market opportunities.",
            'data_points' => [
                'profit_margin' => $profitMargin,
                'net_profit' => $netProfit,
                'status' => $netProfit < 0 ? 'loss' : 'low_margin'
            ],
            'potential_impact_amount' => abs($netProfit * 0.5), // Potential 50% improvement
            'impact_type' => $netProfit < 0 ? 'cost_saving' : 'revenue_increase',
            'confidence_score' => 85.0,
            'is_actionable' => true,
            'suggested_actions' => [
                "Review cost breakdown",
                "Analyze market prices",
                "Consider value-added opportunities",
                "Optimize sales channels"
            ],
            'action_deadline' => now()->addWeeks($priority === 'critical' ? 1 : 2)
        ]);

        event(new ProfitabilityInsightGenerated($insight));
        return $insight;
    }

    public static function createMarketOpportunity(
        string $farmId,
        string $cropType,
        float $currentPrice,
        float $marketPrice,
        array $marketData
    ): self {
        $priceGap = $marketPrice - $currentPrice;
        $percentageGain = ($priceGap / $currentPrice) * 100;

        $insight = static::create([
            'farm_id' => $farmId,
            'insight_type' => 'market_opportunity',
            'priority' => $percentageGain > 20 ? 'high' : 'medium',
            'category' => 'market_intelligence',
            'title' => "Market Price Opportunity",
            'message' => "Current {$cropType} prices are KES {$currentPrice}/kg, but market average is KES {$marketPrice}/kg - a potential {$percentageGain}% increase.",
            'recommendation' => "Consider adjusting pricing strategy or exploring premium markets to capture higher value.",
            'data_points' => [
                'crop_type' => $cropType,
                'current_price' => $currentPrice,
                'market_price' => $marketPrice,
                'price_gap' => $priceGap,
                'percentage_gain' => $percentageGain,
                'market_data' => $marketData
            ],
            'potential_impact_amount' => $priceGap * 1000, // Assume 1000kg production
            'impact_type' => 'revenue_increase',
            'confidence_score' => 80.0,
            'is_actionable' => true,
            'suggested_actions' => [
                "Research premium market channels",
                "Improve product quality/packaging",
                "Negotiate better prices with current buyers",
                "Explore direct-to-consumer sales"
            ],
            'crop_type' => $cropType,
            'action_deadline' => now()->addWeeks(4)
        ]);

        event(new ProfitabilityInsightGenerated($insight));
        return $insight;
    }
}
