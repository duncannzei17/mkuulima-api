<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InventoryAlert extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'farm_id',
        'inventory_item_id',
        'alert_type',
        'title',
        'message',
        'severity',
        'status',
        'alert_date',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'resolved_by',
        'alert_data',
    ];

    protected $casts = [
        'id' => 'string',
        'inventory_item_id' => 'string',
        'acknowledged_by' => 'string',
        'alert_date' => 'date',
        'acknowledged_at' => 'date',
        'resolved_at' => 'datetime',
        'resolved_by' => 'string',
        'alert_data' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAcknowledged($query)
    {
        return $query->where('status', 'acknowledged');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('alert_type', $type);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeHigh($query)
    {
        return $query->where('severity', 'high');
    }

    public function acknowledge($userId = null)
    {
        $this->status = 'acknowledged';
        $this->acknowledged_by = $userId ?: auth()->id();
        $this->acknowledged_at = now()->toDateString();
        $this->save();
    }

    public function resolve($userId = null)
    {
        $this->status = 'resolved';
        $this->resolved_by = $userId ?: auth()->id();
        $this->resolved_at = now();
        $this->save();
    }

    public function getIsActiveAttribute()
    {
        return $this->status === 'active';
    }

    public function getIsAcknowledgedAttribute()
    {
        return $this->status === 'acknowledged';
    }

    public function getIsResolvedAttribute()
    {
        return $this->status === 'resolved';
    }

    public function getIsCriticalAttribute()
    {
        return $this->severity === 'critical';
    }

    public function getIsHighPriorityAttribute()
    {
        return in_array($this->severity, ['high', 'critical']);
    }

    public function getDaysActiveAttribute()
    {
        return $this->alert_date->diffInDays(now());
    }

    public function getSeverityColorAttribute()
    {
        $colors = [
            'low' => 'green',
            'medium' => 'yellow',
            'high' => 'orange',
            'critical' => 'red',
        ];

        return $colors[$this->severity] ?? 'gray';
    }

    public function getSeverityIconAttribute()
    {
        $icons = [
            'low' => 'info',
            'medium' => 'warning',
            'high' => 'exclamation-triangle',
            'critical' => 'exclamation-circle',
        ];

        return $icons[$this->severity] ?? 'question';
    }

    public function getActionRequiredAttribute()
    {
        $actionRequired = [
            'low_stock' => 'Reorder stock',
            'out_of_stock' => 'Urgent reorder required',
            'expiring_soon' => 'Use soon or return to supplier',
            'expired' => 'Remove from inventory',
            'negative_stock' => 'Review stock records',
        ];

        return $actionRequired[$this->alert_type] ?? 'Review required';
    }

    public static function getActiveSummary()
    {
        $alerts = self::active()->get();

        return [
            'total' => $alerts->count(),
            'critical' => $alerts->where('severity', 'critical')->count(),
            'high' => $alerts->where('severity', 'high')->count(),
            'medium' => $alerts->where('severity', 'medium')->count(),
            'low' => $alerts->where('severity', 'low')->count(),
            'by_type' => $alerts->groupBy('alert_type')->map->count(),
            'oldest' => $alerts->sortBy('alert_date')->first(),
            'most_critical' => $alerts->where('severity', 'critical')->first(),
        ];
    }

    public static function getCriticalAlerts()
    {
        return self::active()->critical()->with('inventoryItem')->get();
    }

    public static function getHighPriorityAlerts()
    {
        return self::active()
                  ->whereIn('severity', ['high', 'critical'])
                  ->with('inventoryItem')
                  ->orderBy('severity', 'desc')
                  ->orderBy('alert_date', 'asc')
                  ->get();
    }

    public static function getAlertsForDashboard()
    {
        return [
            'critical_count' => self::active()->critical()->count(),
            'high_count' => self::active()->high()->count(),
            'total_active' => self::active()->count(),
            'recent_alerts' => self::active()
                                 ->with('inventoryItem')
                                 ->orderBy('alert_date', 'desc')
                                 ->limit(5)
                                 ->get(),
            'oldest_unresolved' => self::active()
                                     ->orderBy('alert_date', 'asc')
                                     ->with('inventoryItem')
                                     ->first(),
        ];
    }

    public static function bulkAcknowledge($alertIds, $userId = null)
    {
        return self::whereIn('id', $alertIds)
                  ->where('status', 'active')
                  ->update([
                      'status' => 'acknowledged',
                      'acknowledged_by' => $userId ?: auth()->id(),
                      'acknowledged_at' => now()->toDateString(),
                  ]);
    }

    public static function bulkResolve($alertIds, $userId = null)
    {
        return self::whereIn('id', $alertIds)
                  ->whereIn('status', ['active', 'acknowledged'])
                  ->update([
                      'status' => 'resolved',
                      'acknowledged_by' => $userId ?: auth()->id(),
                      'acknowledged_at' => now()->toDateString(),
                  ]);
    }

    public static function autoResolveAlerts()
    {
        $resolvedCount = 0;

        $lowStockAlerts = self::active()->where('alert_type', 'low_stock')->with('inventoryItem')->get();
        foreach ($lowStockAlerts as $alert) {
            if (!$alert->inventoryItem->is_low_stock) {
                $alert->resolve();
                $resolvedCount++;
            }
        }

        $outOfStockAlerts = self::active()->where('alert_type', 'out_of_stock')->with('inventoryItem')->get();
        foreach ($outOfStockAlerts as $alert) {
            if (!$alert->inventoryItem->is_out_of_stock) {
                $alert->resolve();
                $resolvedCount++;
            }
        }

        $expiringSoonAlerts = self::active()->where('alert_type', 'expiring_soon')->with('inventoryItem')->get();
        foreach ($expiringSoonAlerts as $alert) {
            if (!$alert->inventoryItem->is_expiring_soon) {
                $alert->resolve();
                $resolvedCount++;
            }
        }

        return $resolvedCount;
    }
}
