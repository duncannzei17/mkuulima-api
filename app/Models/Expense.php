<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Expense extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'expense_date',
        'expense_time',
        'amount',
        'description',
        'category_id',
        'subcategory',
        'crop_cycle_id',
        'bed_id',
        'task_id',
        'created_by',
        'approved_by',
        'paid_to',
        'status',
        'rejection_reason',
        'approved_at',
        'rejected_at',
        'payment_method',
        'payment_reference',
        'payment_details',
        'receipt_photos',
        'receipt_number',
        'supplier_name',
        'supplier_phone',
        'metadata',
        'quantity',
        'unit',
        'unit_price',
        'tax_amount',
        'transport_cost',
        'handling_fee',
        'latitude',
        'longitude',
        'location_name',
        'is_recurring',
        'recurrence_pattern',
        'parent_expense_id',
        'is_planned',
        'budget_item_id',
        'variance_from_budget',
        'edit_history',
        'is_deleted',
        'deleted_by',
        'deleted_at',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'expense_time' => 'string',
        'amount' => 'decimal:2',
        'receipt_photos' => 'array',
        'metadata' => 'array',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'handling_fee' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_recurring' => 'boolean',
        'is_planned' => 'boolean',
        'variance_from_budget' => 'decimal:2',
        'edit_history' => 'array',
        'is_deleted' => 'boolean',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'approved',
        'payment_method' => 'cash',
        'tax_amount' => 0,
        'transport_cost' => 0,
        'handling_fee' => 0,
        'is_recurring' => false,
        'is_planned' => false,
        'is_deleted' => false,
    ];

    // Relationships
    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function cropCycle()
    {
        return $this->belongsTo(CropCycle::class, 'crop_cycle_id');
    }

    public function task()
    {
        return $this->belongsTo(CropTask::class, 'task_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function attachments()
    {
        return $this->hasMany(ExpenseAttachment::class);
    }

    public function parentExpense()
    {
        return $this->belongsTo(Expense::class, 'parent_expense_id');
    }

    public function recurringExpenses()
    {
        return $this->hasMany(Expense::class, 'parent_expense_id');
    }

    // Scopes
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByCrop($query, $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    public function scopeByDateRange($query, $startDate, $endDate = null)
    {
        $query->where('expense_date', '>=', $startDate);
        if ($endDate) {
            $query->where('expense_date', '<=', $endDate);
        }
        return $query;
    }

    public function scopeByAmount($query, $minAmount = null, $maxAmount = null)
    {
        if ($minAmount) {
            $query->where('amount', '>=', $minAmount);
        }
        if ($maxAmount) {
            $query->where('amount', '<=', $maxAmount);
        }
        return $query;
    }

    public function scopeByPaymentMethod($query, $method)
    {
        return $query->where('payment_method', $method);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    public function scopeToday($query)
    {
        return $query->where('expense_date', now()->toDateString());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('expense_date', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('expense_date', now()->month)
                    ->whereYear('expense_date', now()->year);
    }

    public function scopeRecentlyCreated($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeAwaitingApproval($query)
    {
        return $query->where('status', 'pending')
                    ->orderBy('created_at', 'asc');
    }

    // Accessors
    public function getTotalAmountAttribute()
    {
        return $this->amount + $this->tax_amount + $this->transport_cost + $this->handling_fee;
    }

    public function getFormattedAmountAttribute()
    {
        return 'KES ' . number_format($this->amount, 2);
    }

    public function getFormattedTotalAmountAttribute()
    {
        return 'KES ' . number_format($this->total_amount, 2);
    }

    public function getStatusBadgeAttribute()
    {
        $badges = [
            'approved' => ['text' => 'Approved', 'color' => 'green'],
            'pending' => ['text' => 'Pending', 'color' => 'yellow'],
            'rejected' => ['text' => 'Rejected', 'color' => 'red'],
            'draft' => ['text' => 'Draft', 'color' => 'gray'],
        ];
        
        return $badges[$this->status] ?? ['text' => 'Unknown', 'color' => 'gray'];
    }

    public function getDaysAgoAttribute()
    {
        return $this->expense_date->diffInDays(now());
    }

    public function getIsRecentAttribute()
    {
        return $this->expense_date->isAfter(now()->subDays(7));
    }

    public function getCanBeEditedAttribute()
    {
        return $this->currentUserCanManageExpense();
    }

    public function getCanBeDeletedAttribute()
    {
        return $this->currentUserCanManageExpense();
    }

    protected function currentUserCanManageExpense(): bool
    {
        $user = auth()->user();
        $farmId = request()->header('X-Tenant-ID')
            ?: request()->header('X-Farm-ID')
            ?: request()->input('farm_id')
            ?: request()->input('farmId');
        $role = $user && $farmId ? $user->getRoleOnFarm($farmId) : null;

        return in_array($role, ['owner', 'manager'], true);
    }

    public function getHasReceiptAttribute()
    {
        return !empty($this->receipt_photos) || $this->attachments()->count() > 0;
    }

    public function getUnitCostAttribute()
    {
        return $this->quantity && $this->quantity > 0 ? 
               round($this->amount / $this->quantity, 2) : null;
    }

    public function getLocationDisplayAttribute()
    {
        if ($this->location_name) {
            return $this->location_name;
        }
        
        if ($this->latitude && $this->longitude) {
            return "GPS: {$this->latitude}, {$this->longitude}";
        }
        
        return 'Location not recorded';
    }

    // Business Logic Methods
    public function approve($approverId = null)
    {
        if ($this->status !== 'pending') {
            throw new \Exception('Only pending expenses can be approved');
        }

        $this->status = 'approved';
        $this->approved_by = $approverId ?: auth()->id();
        $this->approved_at = now();
        $this->save();

        // Update category statistics
        $this->category->updateUsageStats();

        // Create recurring expenses if applicable
        if ($this->is_recurring) {
            $this->createNextRecurringExpense();
        }

        return $this;
    }

    public function reject($reason = null, $rejectedBy = null)
    {
        if ($this->status !== 'pending') {
            throw new \Exception('Only pending expenses can be rejected');
        }

        $this->status = 'rejected';
        $this->rejection_reason = $reason;
        $this->rejected_at = now();
        $this->save();

        return $this;
    }

    public function submitForApproval()
    {
        if ($this->status !== 'draft') {
            throw new \Exception('Only draft expenses can be submitted for approval');
        }

        $this->status = 'pending';
        $this->save();

        return $this;
    }

    public function duplicate($newDate = null)
    {
        $newExpense = $this->replicate(['id', 'created_at', 'updated_at', 'approved_at', 'approved_by']);
        $newExpense->expense_date = $newDate ?: now()->toDateString();
        $newExpense->status = 'draft';
        $newExpense->parent_expense_id = $this->id;
        $newExpense->save();

        return $newExpense;
    }

    public function addEditHistory($changes, $editedBy = null)
    {
        $history = $this->edit_history ?? [];
        $history[] = [
            'timestamp' => now()->toISOString(),
            'edited_by' => $editedBy ?: auth()->id(),
            'changes' => $changes,
        ];
        
        $this->edit_history = $history;
        $this->save();
    }

    protected function createNextRecurringExpense()
    {
        if (!$this->is_recurring || !$this->recurrence_pattern) {
            return null;
        }

        $nextDate = $this->calculateNextRecurrenceDate();
        if (!$nextDate) return null;

        $nextExpense = $this->duplicate($nextDate);
        $nextExpense->status = 'draft'; // Recurring expenses start as draft
        $nextExpense->save();

        return $nextExpense;
    }

    protected function calculateNextRecurrenceDate()
    {
        $currentDate = Carbon::parse($this->expense_date);
        
        switch ($this->recurrence_pattern) {
            case 'weekly':
                return $currentDate->addWeek()->toDateString();
            case 'monthly':
                return $currentDate->addMonth()->toDateString();
            case 'quarterly':
                return $currentDate->addQuarter()->toDateString();
            case 'yearly':
                return $currentDate->addYear()->toDateString();
            default:
                // Parse custom patterns like "every_2_weeks"
                if (preg_match('/^every_(\d+)_(weeks?|months?|days?)$/', $this->recurrence_pattern, $matches)) {
                    $number = $matches[1];
                    $unit = $matches[2];
                    
                    switch (rtrim($unit, 's')) { // Remove plural 's'
                        case 'day':
                            return $currentDate->addDays($number)->toDateString();
                        case 'week':
                            return $currentDate->addWeeks($number)->toDateString();
                        case 'month':
                            return $currentDate->addMonths($number)->toDateString();
                    }
                }
                return null;
        }
    }

    public function attachReceipt($filePath, $attachmentType = 'receipt_photo', $description = null)
    {
        return $this->attachments()->create([
            'filename' => basename($filePath),
            'original_filename' => basename($filePath),
            'file_path' => $filePath,
            'file_type' => mime_content_type($filePath),
            'file_size' => filesize($filePath),
            'attachment_type' => $attachmentType,
            'description' => $description,
            'uploaded_by' => auth()->id(),
        ]);
    }

    public function linkToCrop($cropCycleId)
    {
        $this->crop_cycle_id = $cropCycleId;
        $this->save();

        // Update crop cycle cost
        $cropCycle = $this->cropCycle;
        if ($cropCycle) {
            $cropCycle->actual_cost += $this->amount;
            $cropCycle->save();
        }
    }

    public function unlinkFromCrop()
    {
        $oldCropCycle = $this->cropCycle;
        
        $this->crop_cycle_id = null;
        $this->save();

        // Update old crop cycle cost
        if ($oldCropCycle) {
            $oldCropCycle->actual_cost -= $this->amount;
            $oldCropCycle->save();
        }
    }

    // Static utility methods
    public static function getMonthlyTrend($months = 12)
    {
        $startDate = now()->subMonths($months)->startOfMonth();
        
        return self::approved()
                  ->where('expense_date', '>=', $startDate)
                  ->selectRaw('YEAR(expense_date) as year, MONTH(expense_date) as month, SUM(amount) as total')
                  ->groupBy('year', 'month')
                  ->orderBy('year')
                  ->orderBy('month')
                  ->get()
                  ->map(function($item) {
                      return [
                          'period' => Carbon::create($item->year, $item->month)->format('M Y'),
                          'total' => $item->total,
                      ];
                  });
    }

    public static function getTopExpenses($limit = 10, $period = '30_days')
    {
        $dateFilter = match($period) {
            'today' => now()->toDateString(),
            'week' => now()->subWeek(),
            '30_days' => now()->subDays(30),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->subDays(30)
        };

        $query = self::approved()->with(['category', 'creator']);
        
        if ($period === 'today') {
            $query->where('expense_date', $dateFilter);
        } else {
            $query->where('expense_date', '>=', $dateFilter);
        }

        return $query->orderBy('amount', 'desc')
                    ->limit($limit)
                    ->get();
    }

    public static function getDashboardStats($period = '30_days')
    {
        $dateFilter = match($period) {
            'today' => now()->toDateString(),
            'week' => now()->subWeek(),
            '30_days' => now()->subDays(30),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->subDays(30)
        };

        $query = self::query();
        
        if ($period === 'today') {
            $query->where('expense_date', $dateFilter);
        } else {
            $query->where('expense_date', '>=', $dateFilter);
        }

        return [
            'total_approved' => $query->clone()->approved()->sum('amount'),
            'total_pending' => $query->clone()->pending()->sum('amount'),
            'expense_count' => $query->clone()->approved()->count(),
            'pending_count' => $query->clone()->pending()->count(),
            'average_amount' => $query->clone()->approved()->avg('amount'),
            'categories_used' => $query->clone()->approved()->distinct('category_id')->count(),
        ];
    }

    public static function getPaymentMethodDistribution()
    {
        return self::approved()
                  ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                  ->groupBy('payment_method')
                  ->orderBy('total', 'desc')
                  ->get();
    }
}
