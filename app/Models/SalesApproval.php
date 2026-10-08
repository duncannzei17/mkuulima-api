<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesApproval extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'sale_id',
        'approved_by',
        'action',
        'reason',
        'changes_made',
        'action_taken_at',
        'notes'
    ];

    protected $casts = [
        'action_taken_at' => 'timestamp',
        'changes_made' => 'array'
    ];

    public const ACTIONS = [
        'approved' => 'Approved',
        'rejected' => 'Rejected'
    ];

    // Relationships
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopeApproved($query)
    {
        return $query->where('action', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('action', 'rejected');
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByApprover($query, string $userId)
    {
        return $query->where('approved_by', $userId);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('action_taken_at', '>=', now()->subDays($days));
    }

    // Accessors
    public function getActionNameAttribute(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function getTimeSinceActionAttribute(): string
    {
        return $this->action_taken_at->diffForHumans();
    }

    public function hasApprovalChanges(): bool
    {
        return !empty($this->changes_made);
    }

    // Static utility methods
    public static function getActionOptions(): array
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::ACTIONS), self::ACTIONS);
    }

    /**
     * Get approval statistics for a date range
     */
    public static function getApprovalStats(?int $days = 30): array
    {
        $query = static::recent($days);

        $total = $query->count();
        $approved = $query->approved()->count();
        $rejected = $query->rejected()->count();
        $approvalRate = $total > 0 ? ($approved / $total) * 100 : 0;

        $avgProcessingTime = static::recent($days)
            ->join('sales', 'sales_approvals.sale_id', '=', 'sales.id')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, sales.created_at, sales_approvals.action_taken_at)) as avg_hours')
            ->first()
            ->avg_hours ?? 0;

        return [
            'total_actions' => $total,
            'approved_count' => $approved,
            'rejected_count' => $rejected,
            'approval_rate' => round($approvalRate, 2),
            'rejection_rate' => round(100 - $approvalRate, 2),
            'avg_processing_time_hours' => round($avgProcessingTime, 2)
        ];
    }

    /**
     * Get approver performance metrics
     */
    public static function getApproverMetrics(?int $days = 30): array
    {
        return static::recent($days)
            ->join('users', 'sales_approvals.approved_by', '=', 'users.id')
            ->selectRaw('
                users.id,
                users.name,
                COUNT(*) as total_actions,
                SUM(CASE WHEN action = "approved" THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN action = "rejected" THEN 1 ELSE 0 END) as rejected_count,
                AVG(CASE WHEN action = "approved" THEN 1 ELSE 0 END) * 100 as approval_rate
            ')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_actions')
            ->get()
            ->map(function($item) {
                return [
                    'user_id' => $item->id,
                    'user_name' => $item->name,
                    'total_actions' => $item->total_actions,
                    'approved_count' => $item->approved_count,
                    'rejected_count' => $item->rejected_count,
                    'approval_rate' => round($item->approval_rate, 2)
                ];
            })
            ->toArray();
    }

    /**
     * Get common rejection reasons
     */
    public static function getCommonRejectionReasons(?int $days = 90): array
    {
        return static::rejected()
            ->recent($days)
            ->whereNotNull('reason')
            ->selectRaw('reason, COUNT(*) as count')
            ->groupBy('reason')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(function($item) {
                return [
                    'reason' => $item->reason,
                    'count' => $item->count
                ];
            })
            ->toArray();
    }

    /**
     * Get approval workflow insights
     */
    public static function getWorkflowInsights(?int $days = 30): array
    {
        $stats = static::getApprovalStats($days);
        $approverMetrics = static::getApproverMetrics($days);
        $rejectionReasons = static::getCommonRejectionReasons($days * 3); // Longer period for rejection patterns

        $pendingSales = \App\Models\Sale::pending()->count();
        
        return [
            'current_pending' => $pendingSales,
            'approval_statistics' => $stats,
            'approver_performance' => $approverMetrics,
            'rejection_patterns' => $rejectionReasons,
            'workflow_health' => [
                'efficiency_score' => static::calculateEfficiencyScore($stats),
                'bottleneck_risk' => $pendingSales > 50 ? 'high' : ($pendingSales > 20 ? 'medium' : 'low'),
                'recommendations' => static::generateRecommendations($stats, $pendingSales)
            ]
        ];
    }

    /**
     * Calculate workflow efficiency score (1-100)
     */
    protected static function calculateEfficiencyScore(array $stats): int
    {
        $approvalRate = $stats['approval_rate'];
        $processingTime = $stats['avg_processing_time_hours'];
        
        // Base score from approval rate (higher is better)
        $approvalScore = ($approvalRate / 100) * 50;
        
        // Processing time score (faster is better, assume 24 hours is baseline)
        $timeScore = max(0, 50 - ($processingTime / 24) * 50);
        
        return (int) round($approvalScore + $timeScore);
    }

    /**
     * Generate workflow recommendations
     */
    protected static function generateRecommendations(array $stats, int $pendingCount): array
    {
        $recommendations = [];
        
        if ($stats['approval_rate'] < 70) {
            $recommendations[] = 'High rejection rate detected. Consider reviewing approval criteria.';
        }
        
        if ($stats['avg_processing_time_hours'] > 48) {
            $recommendations[] = 'Slow processing times. Consider adding more approvers or streamlining workflow.';
        }
        
        if ($pendingCount > 50) {
            $recommendations[] = 'Large backlog of pending sales. Immediate attention required.';
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Approval workflow is operating efficiently.';
        }
        
        return $recommendations;
    }
}
