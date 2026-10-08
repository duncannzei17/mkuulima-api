<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

class ExpenseCategory extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'icon',
        'is_system_default',
        'is_active',
        'created_by',
        'monthly_budget',
        'seasonal_budget',
        'total_spent',
        'expense_count',
        'last_used_date',
    ];

    protected $casts = [
        'is_system_default' => 'boolean',
        'is_active' => 'boolean',
        'monthly_budget' => 'decimal:2',
        'seasonal_budget' => 'decimal:2',
        'total_spent' => 'decimal:2',
        'expense_count' => 'integer',
        'last_used_date' => 'date',
    ];

    protected $attributes = [
        'is_system_default' => false,
        'is_active' => true,
        'color' => '#6B7280',
        'total_spent' => 0,
        'expense_count' => 0,
    ];

    // Relationships
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    public function budgets()
    {
        return $this->hasMany(ExpenseBudget::class, 'category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSystemDefaults($query)
    {
        return $query->where('is_system_default', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_system_default', false);
    }

    public function scopeMostUsed($query, $limit = 10)
    {
        return $query->where('expense_count', '>', 0)
                    ->orderBy('expense_count', 'desc')
                    ->limit($limit);
    }

    public function scopeWithBudget($query)
    {
        return $query->where(function($q) {
            $q->whereNotNull('monthly_budget')
              ->orWhereNotNull('seasonal_budget');
        });
    }

    // Accessors
    public function getBudgetUtilizationAttribute()
    {
        $budget = $this->monthly_budget ?: $this->seasonal_budget;
        if (!$budget || $budget <= 0) return null;
        
        return round(($this->total_spent / $budget) * 100, 2);
    }

    public function getRemainingBudgetAttribute()
    {
        $budget = $this->monthly_budget ?: $this->seasonal_budget;
        if (!$budget) return null;
        
        return max(0, $budget - $this->total_spent);
    }

    public function getIsOverBudgetAttribute()
    {
        $budget = $this->monthly_budget ?: $this->seasonal_budget;
        if (!$budget) return false;
        
        return $this->total_spent > $budget;
    }

    public function getAverageExpenseAmountAttribute()
    {
        return $this->expense_count > 0 ? 
               round($this->total_spent / $this->expense_count, 2) : 0;
    }

    public function getDaysSinceLastUsedAttribute()
    {
        return $this->last_used_date ? 
               now()->diffInDays($this->last_used_date) : null;
    }

    // Mutators
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = $value;
        $this->attributes['slug'] = Str::slug($value);
    }

    // Business Logic Methods
    public function updateUsageStats()
    {
        $this->total_spent = $this->expenses()
                                 ->where('status', 'approved')
                                 ->sum('amount');
        
        $this->expense_count = $this->expenses()
                                   ->where('status', 'approved')
                                   ->count();
        
        $this->last_used_date = $this->expenses()
                                    ->where('status', 'approved')
                                    ->max('expense_date');
        
        $this->save();
    }

    public function canBeDeleted()
    {
        // System categories cannot be deleted
        if ($this->is_system_default) return false;
        
        // Categories with expenses cannot be deleted
        if ($this->expense_count > 0) return false;
        
        return true;
    }

    public function getExpensesTrend($days = 30)
    {
        return $this->expenses()
                   ->where('status', 'approved')
                   ->where('expense_date', '>=', now()->subDays($days))
                   ->selectRaw('DATE(expense_date) as date, SUM(amount) as total')
                   ->groupBy('date')
                   ->orderBy('date')
                   ->get();
    }

    public function getMonthlySpending($year = null)
    {
        $year = $year ?: now()->year;
        
        return $this->expenses()
                   ->where('status', 'approved')
                   ->whereYear('expense_date', $year)
                   ->selectRaw('MONTH(expense_date) as month, SUM(amount) as total')
                   ->groupBy('month')
                   ->orderBy('month')
                   ->pluck('total', 'month');
    }

    public function setBudget($amount, $type = 'monthly')
    {
        if ($type === 'monthly') {
            $this->monthly_budget = $amount;
        } else {
            $this->seasonal_budget = $amount;
        }
        $this->save();
    }

    public function checkBudgetAlert()
    {
        $budget = $this->monthly_budget ?: $this->seasonal_budget;
        if (!$budget) return null;
        
        $utilizationPercent = $this->budget_utilization;
        
        if ($utilizationPercent >= 100) {
            return [
                'level' => 'critical',
                'message' => "Category '{$this->name}' is over budget by " . 
                           number_format($this->total_spent - $budget, 2) . " KES"
            ];
        } elseif ($utilizationPercent >= 90) {
            return [
                'level' => 'warning',
                'message' => "Category '{$this->name}' is at {$utilizationPercent}% of budget"
            ];
        } elseif ($utilizationPercent >= 75) {
            return [
                'level' => 'info',
                'message' => "Category '{$this->name}' is at {$utilizationPercent}% of budget"
            ];
        }
        
        return null;
    }

    // Static utility methods
    public static function createSystemDefaults($createdBy)
    {
        $defaultCategories = [
            [
                'name' => 'Labour',
                'description' => 'Farm worker wages and labour payments',
                'color' => '#10B981',
                'icon' => 'user-group',
            ],
            [
                'name' => 'Seeds & Seedlings',
                'description' => 'Seeds, seedlings, and planting materials',
                'color' => '#059669',
                'icon' => 'leaf',
            ],
            [
                'name' => 'Fertilizers & Chemicals',
                'description' => 'Fertilizers, pesticides, herbicides, and chemicals',
                'color' => '#DC2626',
                'icon' => 'beaker',
            ],
            [
                'name' => 'Tools & Equipment',
                'description' => 'Farm tools, equipment, and machinery',
                'color' => '#7C2D12',
                'icon' => 'wrench',
            ],
            [
                'name' => 'Transport & Fuel',
                'description' => 'Transport costs, fuel, and logistics',
                'color' => '#EA580C',
                'icon' => 'truck',
            ],
            [
                'name' => 'Utilities',
                'description' => 'Electricity, water, internet, airtime',
                'color' => '#2563EB',
                'icon' => 'lightning-bolt',
            ],
            [
                'name' => 'Feed & Nutrition',
                'description' => 'Animal feed, supplements, and nutrition',
                'color' => '#7C3AED',
                'icon' => 'heart',
            ],
            [
                'name' => 'Veterinary & Health',
                'description' => 'Veterinary services, medicines, and health supplies',
                'color' => '#DB2777',
                'icon' => 'medical-bag',
            ],
            [
                'name' => 'Infrastructure',
                'description' => 'Construction, repairs, and infrastructure development',
                'color' => '#6B7280',
                'icon' => 'home',
            ],
            [
                'name' => 'Miscellaneous',
                'description' => 'Other farm-related expenses',
                'color' => '#374151',
                'icon' => 'dots-horizontal',
            ],
        ];

        foreach ($defaultCategories as $categoryData) {
            $categoryData['is_system_default'] = true;
            $categoryData['created_by'] = $createdBy;
            self::create($categoryData);
        }
    }

    public static function getMostUsedCategories($limit = 5)
    {
        return self::active()
                  ->where('expense_count', '>', 0)
                  ->orderBy('expense_count', 'desc')
                  ->limit($limit)
                  ->get();
    }

    public static function getCategoriesOverBudget()
    {
        return self::active()
                  ->whereNotNull('monthly_budget')
                  ->orWhereNotNull('seasonal_budget')
                  ->get()
                  ->filter(function($category) {
                      return $category->is_over_budget;
                  });
    }

    public static function getSpendingByCategory($period = '30_days')
    {
        $dateFilter = match($period) {
            'today' => now()->toDateString(),
            'week' => now()->subWeek(),
            '30_days' => now()->subDays(30),
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => now()->subDays(30)
        };

        return self::active()
                  ->withCount(['expenses' => function($query) use ($dateFilter, $period) {
                      $query->where('status', 'approved');
                      if ($period === 'today') {
                          $query->where('expense_date', $dateFilter);
                      } else {
                          $query->where('expense_date', '>=', $dateFilter);
                      }
                  }])
                  ->with(['expenses' => function($query) use ($dateFilter, $period) {
                      $query->where('status', 'approved');
                      if ($period === 'today') {
                          $query->where('expense_date', $dateFilter);
                      } else {
                          $query->where('expense_date', '>=', $dateFilter);
                      }
                  }])
                  ->get()
                  ->map(function($category) {
                      return [
                          'id' => $category->id,
                          'name' => $category->name,
                          'color' => $category->color,
                          'icon' => $category->icon,
                          'total_amount' => $category->expenses->sum('amount'),
                          'expense_count' => $category->expenses_count,
                          'average_amount' => $category->expenses_count > 0 ? 
                                           round($category->expenses->sum('amount') / $category->expenses_count, 2) : 0,
                      ];
                  })
                  ->sortByDesc('total_amount')
                  ->values();
    }
}