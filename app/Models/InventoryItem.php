<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InventoryItem extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'farm_id',
        'name',
        'description',
        'category',
        'unit',
        'sku',
        'barcode',
        'quantity', // For backward compatibility
        'current_quantity',
        'min_quantity',
        'max_quantity',
        'reorder_level',
        'cost_per_unit',
        'average_cost',
        'total_value',
        'total_quantity_in',
        'total_quantity_out',
        'supplier',
        'supplier_contact',
        'brand',
        'batch_number',
        'purchase_date',
        'expiry_date',
        'storage_location',
        'storage_conditions',
        'status',
        'track_expiry',
        'track_batches',
        'is_consumable',
        'last_movement_date',
        'last_stock_in_date',
        'last_stock_out_date',
        'movement_count',
        'tags',
        'custom_fields',
        'attachments',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'id' => 'string',
        'farm_id' => 'string',
        'created_by' => 'string',
        'updated_by' => 'string',
        'quantity' => 'decimal:3',
        'current_quantity' => 'decimal:3',
        'min_quantity' => 'decimal:3',
        'max_quantity' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'cost_per_unit' => 'decimal:2',
        'average_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
        'total_quantity_in' => 'decimal:3',
        'total_quantity_out' => 'decimal:3',
        'purchase_date' => 'date',
        'expiry_date' => 'date',
        'last_movement_date' => 'date',
        'last_stock_in_date' => 'date',
        'last_stock_out_date' => 'date',
        'track_expiry' => 'boolean',
        'track_batches' => 'boolean',
        'is_consumable' => 'boolean',
        'movement_count' => 'integer',
        'tags' => 'array',
        'custom_fields' => 'array',
        'attachments' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (auth()->check() && empty($model->created_by)) {
                $model->created_by = auth()->id();
            }
            // Ensure backward compatibility by syncing quantity with current_quantity
            if ($model->current_quantity === null && $model->quantity !== null) {
                $model->current_quantity = $model->quantity;
            } elseif ($model->quantity === null && $model->current_quantity !== null) {
                $model->quantity = $model->current_quantity;
            }
        });
        
        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
            // Keep quantity and current_quantity in sync
            if ($model->isDirty('current_quantity')) {
                $model->quantity = $model->current_quantity;
            } elseif ($model->isDirty('quantity')) {
                $model->current_quantity = $model->quantity;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(InventoryAlert::class);
    }


    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('quantity', '<=', 'min_quantity');
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('quantity', '<=', 0);
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        $futureDate = now()->addDays($days);
        return $query->where('expiry_date', '<=', $futureDate)
                    ->where('expiry_date', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<', now());
    }

    public function getIsLowStockAttribute()
    {
        return $this->current_quantity <= $this->min_quantity;
    }

    public function getIsOutOfStockAttribute()
    {
        return $this->current_quantity <= 0;
    }

    public function getIsExpiringSoonAttribute()
    {
        if (!$this->expiry_date) {
            return false;
        }
        return $this->expiry_date <= now()->addDays(30) && $this->expiry_date > now();
    }

    public function getIsExpiredAttribute()
    {
        if (!$this->expiry_date) {
            return false;
        }
        return $this->expiry_date < now();
    }

    public function getDaysToExpiryAttribute()
    {
        if (!$this->expiry_date) {
            return null;
        }
        return now()->diffInDays($this->expiry_date, false);
    }

    public function getInventoryTurnoverRateAttribute()
    {
        if ($this->total_quantity_in == 0) {
            return 0;
        }
        return round($this->total_quantity_out / $this->total_quantity_in * 100, 2);
    }

    public function getAverageCostAttribute()
    {
        $movements = $this->stockMovements()
                         ->where('movement_type', 'in')
                         ->whereNotNull('cost_per_unit')
                         ->get();

        if ($movements->isEmpty()) {
            return $this->cost_per_unit;
        }

        $totalCost = $movements->sum(function($movement) {
            return $movement->quantity * $movement->cost_per_unit;
        });
        
        $totalQuantity = $movements->sum('quantity');
        
        return $totalQuantity > 0 ? round($totalCost / $totalQuantity, 2) : $this->cost_per_unit;
    }

    public function stockIn($quantity, $costPerUnit = null, $data = [])
    {
        $costPerUnit = $costPerUnit ?? $this->cost_per_unit ?? 0;
        $totalCost = $quantity * $costPerUnit;
        $newQuantity = (float) $this->current_quantity + (float) $quantity;
        $newValue = (float) $this->total_value + $totalCost;

        $movement = $this->stockMovements()->create([
            'farm_id' => $this->farm_id,
            'movement_type' => 'in',
            'quantity' => $quantity,
            'cost_per_unit' => $costPerUnit,
            'total_cost' => $totalCost,
            'balance_after' => $newQuantity,
            'movement_date' => $data['movement_date'] ?? now()->toDateString(),
            'batch_number' => $data['batch_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->current_quantity = $newQuantity;
        $this->total_quantity_in += $quantity;
        $this->total_value = $newValue;
        $this->cost_per_unit = $newQuantity > 0 ? round($newValue / $newQuantity, 2) : $costPerUnit;
        $this->last_movement_date = $movement->movement_date;
        $this->last_stock_in_date = $movement->movement_date;
        $this->movement_count += 1;
        $this->save();

        $this->checkAndCreateAlerts();

        if (isset($data['create_expense']) && $data['create_expense']) {
            $this->createExpenseEntry($movement);
        }

        return $movement;
    }

    public function stockOut($quantity, $data = [])
    {
        if ($this->is_expired && !($data['override_expiry'] ?? false)) {
            throw new \DomainException('Expired stock cannot be issued without a manager override');
        }

        if ($quantity > $this->current_quantity) {
            throw new \DomainException("Insufficient stock. Available: {$this->current_quantity} {$this->unit}");
        }

        $unitCost = (float) ($this->cost_per_unit ?? 0);
        $totalCost = (float) $quantity * $unitCost;
        $movement = $this->stockMovements()->create([
            'farm_id' => $this->farm_id,
            'movement_type' => 'out',
            'quantity' => $quantity,
            'cost_per_unit' => $unitCost,
            'total_cost' => $totalCost,
            'balance_after' => $this->current_quantity - $quantity,
            'movement_date' => $data['movement_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'crop_cycle_id' => $data['crop_cycle_id'] ?? null,
            'bed_id' => $data['bed_id'] ?? null,
            'labour_task_id' => $data['labour_task_id'] ?? null,
            'worker_id' => $data['worker_id'] ?? null,
            'created_by' => auth()->id(),
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->current_quantity -= $quantity;
        $this->total_quantity_out += $quantity;
        $this->total_value = max(0, (float) $this->total_value - $totalCost);
        $this->last_movement_date = $movement->movement_date;
        $this->last_stock_out_date = $movement->movement_date;
        $this->movement_count += 1;
        $this->save();

        $this->checkAndCreateAlerts();

        return $movement;
    }

    public function adjustStock($quantity, $reason = null)
    {
        $newQuantity = (float) $this->current_quantity + (float) $quantity;
        if ($newQuantity < 0) {
            throw new \DomainException("Adjustment would create negative stock. Available: {$this->current_quantity} {$this->unit}");
        }

        $movement = $this->stockMovements()->create([
            'farm_id' => $this->farm_id,
            'movement_type' => 'adjustment',
            'quantity' => $quantity,
            'balance_after' => $newQuantity,
            'movement_date' => now()->toDateString(),
            'notes' => $reason,
            'created_by' => auth()->id(),
        ]);

        $this->current_quantity = $newQuantity;
        $this->total_value = max(0, $newQuantity * (float) $this->cost_per_unit);
        $this->last_movement_date = $movement->movement_date;
        $this->movement_count += 1;
        $this->save();

        $this->checkAndCreateAlerts();

        return $movement;
    }

    public function markExpired($quantity = null)
    {
        $removeQuantity = $quantity ?: $this->current_quantity;
        
        if ($removeQuantity > $this->current_quantity) {
            throw new \DomainException("Cannot remove more than available quantity");
        }

        $movement = $this->stockMovements()->create([
            'farm_id' => $this->farm_id,
            'movement_type' => 'expired_removal',
            'quantity' => $removeQuantity,
            'balance_after' => $this->current_quantity - $removeQuantity,
            'movement_date' => now()->toDateString(),
            'notes' => 'Expired item removal',
            'created_by' => auth()->id(),
        ]);

        $this->current_quantity -= $removeQuantity;
        $this->total_quantity_out += $removeQuantity;
        $this->total_value = max(0, (float) $this->current_quantity * (float) $this->cost_per_unit);
        $this->status = $this->current_quantity <= 0 ? 'expired' : $this->status;
        $this->last_movement_date = $movement->movement_date;
        $this->last_stock_out_date = $movement->movement_date;
        $this->movement_count += 1;
        $this->save();

        $this->checkAndCreateAlerts();

        return $movement;
    }

    public function checkAndCreateAlerts()
    {
        if (!$this->is_low_stock) {
            $this->alerts()
                ->whereIn('status', ['active', 'acknowledged'])
                ->where('alert_type', 'low_stock')
                ->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'resolved_by' => auth()->id(),
                ]);
        }

        $activeAlerts = $this->alerts()
            ->whereIn('status', ['active', 'acknowledged'])
            ->pluck('alert_type')
            ->toArray();

        if ($this->is_out_of_stock && !in_array('low_stock', $activeAlerts)) {
            $this->createAlert('low_stock', 'Out of Stock Alert', 
                "Item '{$this->name}' is out of stock", 'critical');
        }

        if ($this->is_low_stock && !$this->is_out_of_stock && !in_array('low_stock', $activeAlerts)) {
            $this->createAlert('low_stock', 'Low Stock Alert', 
                "Item '{$this->name}' is running low. Current stock: {$this->quantity} {$this->unit}", 'high');
        }

        if ($this->is_expiring_soon && !in_array('expiring', $activeAlerts)) {
            $daysToExpiry = $this->days_to_expiry;
            $this->createAlert('expiring', 'Expiring Soon Alert', 
                "Item '{$this->name}' expires in {$daysToExpiry} days", 'medium');
        }

        if ($this->is_expired && !in_array('expired', $activeAlerts)) {
            $this->createAlert('expired', 'Expired Item Alert', 
                "Item '{$this->name}' has expired", 'critical');
        }

    }

    protected function createAlert($type, $title, $message, $severity)
    {
        $this->alerts()->create([
            'farm_id' => $this->farm_id,
            'alert_type' => $type,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'alert_date' => now()->toDateString(),
            'alert_data' => [
                'current_quantity' => $this->quantity,
                'min_quantity' => $this->min_quantity,
                'expiry_date' => $this->expiry_date,
            ]
        ]);
    }

    protected function createExpenseEntry($movement)
    {
        $inventoryCategory = ExpenseCategory::firstOrCreate(
            ['slug' => 'inventory-supplies'],
            [
                'name' => 'Inventory Supplies',
                'description' => 'Inventory purchases created from stock receipts',
                'is_system_default' => true,
                'created_by' => auth()->id(),
            ]
        );

        $expense = Expense::where('metadata->stock_movement_id', $movement->id)->first();
        if (!$expense) {
            $expense = Expense::create([
                'category_id' => $inventoryCategory->id,
                'amount' => $movement->total_cost,
                'description' => "Stock in: {$this->name} ({$movement->quantity} {$this->unit})",
                'expense_date' => $movement->movement_date,
                'payment_method' => 'cash',
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'created_by' => auth()->id(),
                'supplier_name' => $this->supplier,
                'quantity' => $movement->quantity,
                'unit' => $this->unit,
                'unit_price' => $movement->cost_per_unit,
                'metadata' => [
                    'source' => 'inventory_stock_in',
                    'stock_movement_id' => $movement->id,
                    'inventory_item_id' => $this->id,
                ],
            ]);
        }

        $movement->update(['expense_id' => $expense->id]);
    }

    public function getUsageByPeriod($startDate, $endDate)
    {
        return $this->stockMovements()
                   ->where('movement_type', 'out')
                   ->whereBetween('movement_date', [$startDate, $endDate])
                   ->sum('quantity');
    }

    public function getUsageByCrop($cropCycleId)
    {
        return $this->stockMovements()
                   ->where('movement_type', 'out')
                   ->where('crop_cycle_id', $cropCycleId)
                   ->sum('quantity');
    }

    public function getCostByCrop($cropCycleId)
    {
        $usage = $this->getUsageByCrop($cropCycleId);
        return $usage * $this->average_cost;
    }

    public static function getLowStockItems()
    {
        return self::lowStock()->get();
    }

    public static function getExpiringSoonItems($days = 30)
    {
        return self::expiringSoon($days)->get();
    }

    public static function getTotalInventoryValue()
    {
        return self::selectRaw('SUM(quantity * cost_per_unit) as total_value')->value('total_value') ?? 0;
    }

    public static function getMostUsedItems($limit = 10)
    {
        return self::withSum([
            'stockMovements as usage_quantity' => fn ($query) => $query
                ->where('movement_type', 'out')
                ->where('status', 'confirmed'),
        ], 'quantity')->orderByDesc('usage_quantity')->limit($limit)->get();
    }

    public static function getFastMovingItems($limit = 10)
    {
        return self::withCount([
            'stockMovements as recent_movement_count' => fn ($query) => $query
                ->where('status', 'confirmed')
                ->where('movement_date', '>=', now()->subDays(30)->toDateString()),
        ])->orderByDesc('recent_movement_count')->limit($limit)->get();
    }

    public static function getCategoryStats()
    {
        return self::selectRaw('category, COUNT(*) as item_count, SUM(quantity * cost_per_unit) as total_value, AVG(quantity) as avg_quantity')
                  ->groupBy('category')
                  ->orderBy('total_value', 'desc')
                  ->get();
    }
}
