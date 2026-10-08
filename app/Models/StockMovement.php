<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockMovement extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'farm_id',
        'inventory_item_id',
        'movement_type',
        'quantity',
        'cost_per_unit',
        'movement_date',
        'total_cost',
        'balance_after',
        'batch_number',
        'notes',
        'crop_cycle_id',
        'bed_id',
        'labour_task_id',
        'worker_id',
        'expense_id',
        'status',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'id' => 'string',
        'farm_id' => 'string',
        'inventory_item_id' => 'string',
        'crop_cycle_id' => 'string',
        'bed_id' => 'string',
        'labour_task_id' => 'string',
        'worker_id' => 'string',
        'expense_id' => 'string',
        'created_by' => 'string',
        'quantity' => 'decimal:3',
        'cost_per_unit' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'balance_after' => 'decimal:3',
        'movement_date' => 'date',
        'metadata' => 'array',
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

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function labourTask(): BelongsTo
    {
        return $this->belongsTo(CropTask::class, 'labour_task_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeStockIn($query)
    {
        return $query->where('movement_type', 'in');
    }

    public function scopeStockOut($query)
    {
        return $query->where('movement_type', 'out');
    }

    public function scopeAdjustments($query)
    {
        return $query->where('movement_type', 'adjustment');
    }

    public function scopeExpiredRemovals($query)
    {
        return $query->where('movement_type', 'expired_removal');
    }

    public function scopeByItem($query, $itemId)
    {
        return $query->where('inventory_item_id', $itemId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    public function scopeByCrop($query, $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    public function scopeByWorker($query, $workerId)
    {
        return $query->where('worker_id', $workerId);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function getIsInboundAttribute()
    {
        return $this->movement_type === 'in';
    }

    public function getIsOutboundAttribute()
    {
        return in_array($this->movement_type, ['out', 'expired_removal']);
    }

    public function getFormattedQuantityAttribute()
    {
        return number_format($this->quantity, 3) . ' ' . ($this->inventoryItem->unit ?? 'units');
    }

    public function getMovementDescriptionAttribute()
    {
        $descriptions = [
            'in' => 'Stock Added',
            'out' => 'Stock Used',
            'adjustment' => 'Stock Adjusted',
            'expired_removal' => 'Expired Stock Removed',
        ];

        return $descriptions[$this->movement_type] ?? 'Unknown Movement';
    }

    public function confirm()
    {
        $this->status = 'confirmed';
        $this->save();
    }

    public function cancel()
    {
        if ($this->status !== 'pending') {
            throw new \Exception('Only pending movements can be cancelled');
        }

        $this->status = 'cancelled';
        $this->save();

        $item = $this->inventoryItem;
        if ($this->is_inbound) {
            $item->current_quantity -= $this->quantity;
            $item->total_quantity_in -= $this->quantity;
        } else {
            $item->current_quantity += $this->quantity;
            $item->total_quantity_out -= $this->quantity;
        }
        
        if ($this->total_cost) {
            if ($this->is_inbound) {
                $item->total_value -= $this->total_cost;
            }
        }
        
        $item->save();
        $item->checkAndCreateAlerts();
    }

    public static function getMovementSummary($startDate = null, $endDate = null)
    {
        $query = self::confirmed();

        if ($startDate) {
            $query->where('movement_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('movement_date', '<=', $endDate);
        }

        $movements = $query->with('inventoryItem')->get();

        return [
            'total_movements' => $movements->count(),
            'stock_in_movements' => $movements->where('movement_type', 'in')->count(),
            'stock_out_movements' => $movements->where('movement_type', 'out')->count(),
            'total_in_value' => $movements->where('movement_type', 'in')->sum('total_cost'),
            'total_out_quantity' => $movements->where('movement_type', 'out')->sum('quantity'),
            'by_category' => $movements->groupBy('inventoryItem.category')->map(function($group) {
                return [
                    'movements' => $group->count(),
                    'in_quantity' => $group->where('movement_type', 'in')->sum('quantity'),
                    'out_quantity' => $group->where('movement_type', 'out')->sum('quantity'),
                    'total_value' => $group->where('movement_type', 'in')->sum('total_cost'),
                ];
            }),
        ];
    }

    public static function getDailyMovements($days = 30)
    {
        return self::confirmed()
                  ->selectRaw('DATE(movement_date) as date, movement_type, COUNT(*) as movement_count, SUM(quantity) as total_quantity')
                  ->where('movement_date', '>=', now()->subDays($days))
                  ->groupBy(['date', 'movement_type'])
                  ->orderBy('date', 'desc')
                  ->get();
    }

    public static function getTopItems($type = 'out', $limit = 10)
    {
        return self::confirmed()
                  ->where('movement_type', $type)
                  ->with('inventoryItem')
                  ->selectRaw('inventory_item_id, SUM(quantity) as total_quantity, COUNT(*) as movement_count')
                  ->groupBy('inventory_item_id')
                  ->orderBy('total_quantity', 'desc')
                  ->limit($limit)
                  ->get();
    }

    public static function getCropUsage($cropCycleId)
    {
        return self::confirmed()
                  ->where('movement_type', 'out')
                  ->where('crop_cycle_id', $cropCycleId)
                  ->with('inventoryItem')
                  ->selectRaw('inventory_item_id, SUM(quantity) as total_quantity, COUNT(*) as usage_count')
                  ->groupBy('inventory_item_id')
                  ->get();
    }

    public static function getWorkerUsage($workerId, $startDate = null, $endDate = null)
    {
        $query = self::confirmed()
                    ->where('movement_type', 'out')
                    ->where('worker_id', $workerId)
                    ->with('inventoryItem');

        if ($startDate) {
            $query->where('movement_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('movement_date', '<=', $endDate);
        }

        return $query->selectRaw('inventory_item_id, SUM(quantity) as total_quantity, COUNT(*) as usage_count')
                    ->groupBy('inventory_item_id')
                    ->get();
    }
}