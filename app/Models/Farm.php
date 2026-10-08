<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Farm extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'user_id', // Owner
        'name',
        'location', // JSON structure: County → Ward → Village
        'latitude',
        'longitude',
        'timezone',
        'size',
        'size_unit',
        'type',
        'ownership',
        'starting_year',
        'tenant_schema_name',
        'registration_number',
        'tax_id',
        'subscription_tier',
        'status',
        'coordinates',
        'soil_type',
        'climate_zone',
        'water_source',
        'organic_certified',
        'description',
        'settings',
        'metadata'
    ];

    protected $casts = [
        'location' => 'array',
        'coordinates' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'organic_certified' => 'boolean',
        'size' => 'decimal:2',
        'settings' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'active',
        'subscription_tier' => 'basic',
        'size_unit' => 'acres',
        'organic_certified' => false,
    ];

    /**
     * Boot method to auto-generate tenant schema name
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($farm) {
            if (empty($farm->tenant_schema_name)) {
                $farm->tenant_schema_name = 'farm_' . Str::random(12);
            }
        });
    }

    // Relationships
    /**
     * Farm owner
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Users with access to this farm (workers/managers)
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'farm_user')
                    ->wherePivotNull('deleted_at')
                    ->withPivot([
                        'role', 'permissions', 'status', 'hire_date', 'invited_by',
                        'invitation_sent_at', 'invitation_accepted_at',
                    ])
                    ->withTimestamps();
    }

    /**
     * All users including owner and workers
     */
    public function allUsers()
    {
        return collect([$this->owner])->merge($this->users);
    }

    /**
     * Farm-user pivot records
     */
    public function farmUsers()
    {
        return $this->hasMany(FarmUser::class);
    }

    /**
     * Tenant registry record
     */
    public function tenantRegistry()
    {
        return $this->hasOne(TenantRegistry::class);
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    public function cropCycles(): HasMany
    {
        return $this->hasMany(CropCycle::class);
    }

    public function crops(): HasMany
    {
        return $this->cropCycles();
    }

    public function harvests(): HasManyThrough
    {
        return $this->hasManyThrough(
            Harvest::class,
            CropCycle::class,
            'farm_id',
            'crop_cycle_id'
        );
    }

    public function sales(): HasManyThrough
    {
        return $this->hasManyThrough(
            Sale::class,
            CropCycle::class,
            'farm_id',
            'crop_cycle_id'
        );
    }

    public function expenses(): HasManyThrough
    {
        return $this->hasManyThrough(
            Expense::class,
            CropCycle::class,
            'farm_id',
            'crop_cycle_id'
        );
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByTier($query, $tier)
    {
        return $query->where('subscription_tier', $tier);
    }

    public function scopeOrganic($query)
    {
        return $query->where('organic_certified', true);
    }

    // Accessors & Mutators
    public function getFormattedSizeAttribute()
    {
        return number_format($this->size_hectares, 2) . ' hectares';
    }

    public function getTotalCropsAttribute()
    {
        return $this->crops()->count();
    }

    public function getActiveCropsAttribute()
    {
        return $this->cropCycles()->active()->count();
    }

    // Business Logic Methods
    public function getCurrentSeasonRevenue()
    {
        $currentYear = now()->year;
        return $this->sales()
            ->approved()
            ->whereYear('sale_date', $currentYear)
            ->sum('net_income');
    }

    public function getCurrentSeasonExpenses()
    {
        $currentYear = now()->year;
        return $this->expenses()
            ->approved()
            ->whereYear('expense_date', $currentYear)
            ->sum('amount');
    }

    public function getProfitability()
    {
        $revenue = $this->getCurrentSeasonRevenue();
        $expenses = $this->getCurrentSeasonExpenses();
        
        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'profit' => $revenue - $expenses,
            'margin' => $revenue > 0 ? (($revenue - $expenses) / $revenue) * 100 : 0,
        ];
    }

    public function getProductivityMetrics()
    {
        return [
            'yield_per_hectare' => $this->crops()
                ->where('status', 'harvested')
                ->avg('yield_per_hectare'),
            'crops_per_season' => $this->crops()
                ->whereYear('created_at', now()->year)
                ->count(),
            'average_crop_cycle' => $this->crops()
                ->where('status', 'harvested')
                ->selectRaw('AVG(DATEDIFF(harvest_date, planting_date)) as cycle_days')
                ->value('cycle_days'),
        ];
    }
}
