<?php

namespace App\Events;

use App\Models\ProfitabilitySnapshot;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeasonHealthScoreUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProfitabilitySnapshot $snapshot,
        public float $previousScore
    ) {}

    public function toKafka(): array
    {
        $scoreChange = $this->snapshot->season_health_score - $this->previousScore;
        $trendDirection = match(true) {
            $scoreChange > 5 => 'improving',
            $scoreChange > 0 => 'slightly_improving',
            $scoreChange < -5 => 'declining',
            $scoreChange < 0 => 'slightly_declining',
            default => 'stable'
        };

        return [
            'event_type' => 'profitability.season_health_score_updated',
            'event_id' => $this->snapshot->id,
            'timestamp' => now()->toISOString(),
            'tenant_id' => $this->snapshot->farm->tenant_id ?? null,
            'farm_id' => $this->snapshot->farm_id,
            'crop_cycle_id' => $this->snapshot->crop_cycle_id,
            'health_score_data' => [
                'current_score' => $this->snapshot->season_health_score,
                'previous_score' => $this->previousScore,
                'score_change' => $scoreChange,
                'trend_direction' => $trendDirection,
                'score_category' => $this->getScoreCategory($this->snapshot->season_health_score),
                'alert_level' => $this->getAlertLevel($this->snapshot->season_health_score, $scoreChange)
            ],
            'contributing_factors' => [
                'yield_efficiency' => $this->snapshot->yield_efficiency,
                'profit_margin' => $this->snapshot->profit_margin,
                'cost_efficiency_score' => $this->snapshot->cost_efficiency_score,
                'roi_percentage' => $this->snapshot->roi_percentage
            ],
            'performance_indicators' => [
                'efficiency_score' => $this->snapshot->efficiency_score,
                'yield_performance_score' => $this->snapshot->yield_performance_score,
                'season_health_score' => $this->snapshot->season_health_score
            ],
            'crop_context' => [
                'crop_type' => $this->snapshot->cropCycle->crop_type ?? 'unknown',
                'crop_duration_days' => $this->snapshot->crop_duration_days,
                'cycle_stage' => $this->determineCycleStage(),
                'planting_date' => $this->snapshot->cycle_start_date?->toDateString(),
                'expected_harvest' => $this->snapshot->cycle_end_date?->toDateString()
            ],
            'recommendations' => $this->generateHealthRecommendations($scoreChange, $trendDirection),
            'farm_info' => [
                'farm_name' => $this->snapshot->farm->name ?? 'Unknown',
                'total_cycles_this_season' => $this->getTotalSeasonCycles()
            ]
        ];
    }

    private function getScoreCategory(float $score): string
    {
        return match(true) {
            $score >= 85 => 'excellent',
            $score >= 75 => 'good',
            $score >= 60 => 'fair',
            $score >= 40 => 'poor',
            default => 'critical'
        };
    }

    private function getAlertLevel(float $currentScore, float $scoreChange): string
    {
        if ($currentScore < 40) {
            return 'critical';
        }
        
        if ($scoreChange < -10) {
            return 'high';
        }
        
        if ($currentScore < 60 && $scoreChange < -5) {
            return 'medium';
        }
        
        if ($scoreChange > 10) {
            return 'positive';
        }
        
        return 'normal';
    }

    private function determineCycleStage(): string
    {
        if (!$this->snapshot->cycle_start_date || !$this->snapshot->cycle_end_date) {
            return 'unknown';
        }

        $totalDays = $this->snapshot->cycle_start_date->diffInDays($this->snapshot->cycle_end_date);
        $elapsedDays = $this->snapshot->cycle_start_date->diffInDays(now());
        
        if ($totalDays <= 0) return 'unknown';
        
        $progress = ($elapsedDays / $totalDays) * 100;
        
        return match(true) {
            $progress < 25 => 'planting',
            $progress < 50 => 'growth',
            $progress < 75 => 'development',
            $progress < 90 => 'maturation',
            default => 'harvest'
        };
    }

    private function generateHealthRecommendations(float $scoreChange, string $trendDirection): array
    {
        $recommendations = [];

        if ($this->snapshot->season_health_score < 60) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Review cost management and yield optimization strategies',
                'reason' => 'Season health score below acceptable threshold'
            ];
        }

        if ($trendDirection === 'declining') {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'Analyze recent changes in farming practices or market conditions',
                'reason' => 'Declining trend in season health score'
            ];
        }

        if ($this->snapshot->yield_efficiency < 70) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'Focus on yield improvement techniques',
                'reason' => 'Low yield efficiency affecting overall health score'
            ];
        }

        if ($this->snapshot->profit_margin < 20) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Review pricing strategy and cost reduction opportunities',
                'reason' => 'Low profit margin impacting season health'
            ];
        }

        return $recommendations;
    }

    private function getTotalSeasonCycles(): int
    {
        // Get count of crop cycles in current season for this farm
        $currentYear = now()->year;
        return \App\Models\CropCycle::where('farm_id', $this->snapshot->farm_id)
            ->whereYear('planting_date', $currentYear)
            ->count();
    }
}