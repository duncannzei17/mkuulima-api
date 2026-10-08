<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Crop extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'plot_id',
        'name',
        'variety',
        'crop_type',
        'planting_date',
        'expected_harvest_date',
        'actual_harvest_date',
        'area_planted',
        'seeds_used',
        'seed_cost',
        'expected_yield',
        'actual_yield',
        'yield_per_hectare',
        'quality_grade',
        'status',
        'growth_stage',
        'irrigation_method',
        'organic_practice',
        'pest_management',
        'fertilizer_used',
        'weather_conditions',
        'notes',
        'metadata'
    ];

    protected $casts = [
        'planting_date' => 'date',
        'expected_harvest_date' => 'date',
        'actual_harvest_date' => 'date',
        'area_planted' => 'decimal:2',
        'seeds_used' => 'decimal:2',
        'seed_cost' => 'decimal:2',
        'expected_yield' => 'decimal:2',
        'actual_yield' => 'decimal:2',
        'yield_per_hectare' => 'decimal:2',
        'organic_practice' => 'boolean',
        'weather_conditions' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'planned',
        'growth_stage' => 'planning',
        'organic_practice' => false,
    ];

    // Define constants for status and growth stages
    public const STATUSES = [
        'planned' => 'Planned',
        'planted' => 'Planted',
        'growing' => 'Growing',
        'ready_to_harvest' => 'Ready to Harvest',
        'harvested' => 'Harvested',
        'sold' => 'Sold',
        'failed' => 'Failed',
    ];

    public const GROWTH_STAGES = [
        'planning' => 'Planning',
        'soil_preparation' => 'Soil Preparation',
        'planting' => 'Planting',
        'germination' => 'Germination',
        'vegetative' => 'Vegetative',
        'flowering' => 'Flowering',
        'fruiting' => 'Fruiting/Grain Filling',
        'maturation' => 'Maturation',
        'harvest_ready' => 'Harvest Ready',
        'harvested' => 'Harvested',
    ];

    public const CROP_TYPES = [
        'cereals' => 'Cereals',
        'legumes' => 'Legumes',
        'vegetables' => 'Vegetables',
        'fruits' => 'Fruits',
        'cash_crops' => 'Cash Crops',
        'herbs_spices' => 'Herbs & Spices',
        'root_tubers' => 'Root & Tubers',
    ];

    // Relationships
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function plot()
    {
        return $this->belongsTo(Plot::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function labourSessions()
    {
        return $this->hasMany(LabourSession::class);
    }

    public function fieldObservations()
    {
        return $this->hasMany(FieldObservation::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function weatherData()
    {
        return $this->hasMany(WeatherData::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['harvested', 'sold', 'failed']);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('crop_type', $type);
    }

    public function scopeCurrentSeason($query)
    {
        return $query->whereYear('planting_date', now()->year);
    }

    public function scopeReadyForHarvest($query)
    {
        return $query->where('status', 'ready_to_harvest')
            ->orWhere(function ($q) {
                $q->where('expected_harvest_date', '<=', now()->addDays(7))
                  ->where('status', 'growing');
            });
    }

    public function scopeOrganic($query)
    {
        return $query->where('organic_practice', true);
    }

    // Accessors
    public function getFormattedAreaAttribute()
    {
        return number_format($this->area_planted, 2) . ' hectares';
    }

    public function getCropCycleDaysAttribute()
    {
        if (!$this->planting_date) {
            return null;
        }

        $endDate = $this->actual_harvest_date ?? $this->expected_harvest_date ?? now();
        return $this->planting_date->diffInDays($endDate);
    }

    public function getYieldEfficiencyAttribute()
    {
        if (!$this->expected_yield || !$this->actual_yield) {
            return null;
        }

        return ($this->actual_yield / $this->expected_yield) * 100;
    }

    public function getRevenuePerHectareAttribute()
    {
        if (!$this->area_planted || $this->area_planted == 0) {
            return 0;
        }

        $totalRevenue = $this->sales()->sum('total_amount');
        return $totalRevenue / $this->area_planted;
    }

    public function getProfitabilityAttribute()
    {
        $revenue = $this->sales()->sum('total_amount');
        $expenses = $this->expenses()->sum('amount');
        $labourCost = $this->labourSessions()->sum('cost');

        $totalCosts = $expenses + $labourCost + ($this->seed_cost ?? 0);

        return [
            'revenue' => $revenue,
            'expenses' => $totalCosts,
            'profit' => $revenue - $totalCosts,
            'margin' => $revenue > 0 ? (($revenue - $totalCosts) / $revenue) * 100 : 0,
        ];
    }

    // Business Logic Methods
    public function updateGrowthStage($stage)
    {
        $this->growth_stage = $stage;
        
        // Auto-update status based on growth stage
        if ($stage === 'harvest_ready') {
            $this->status = 'ready_to_harvest';
        } elseif ($stage === 'harvested') {
            $this->status = 'harvested';
            if (!$this->actual_harvest_date) {
                $this->actual_harvest_date = now();
            }
        }

        $this->save();
    }

    public function recordHarvest($yield, $quality = 'good')
    {
        $this->actual_yield = $yield;
        $this->yield_per_hectare = $this->area_planted > 0 ? $yield / $this->area_planted : 0;
        $this->quality_grade = $quality;
        $this->actual_harvest_date = now();
        $this->status = 'harvested';
        $this->growth_stage = 'harvested';
        
        $this->save();

        // Trigger ecosystem event
        event(new \App\Events\CropHarvested($this));
    }

    public function getDaysToHarvest()
    {
        if (!$this->expected_harvest_date) {
            return null;
        }

        return now()->diffInDays($this->expected_harvest_date, false);
    }

    public function isOverdue()
    {
        return $this->expected_harvest_date && 
               $this->expected_harvest_date->isPast() && 
               $this->status !== 'harvested';
    }

    public function getWeatherSummary()
    {
        return [
            'total_rainfall' => $this->weatherData()->sum('rainfall'),
            'average_temperature' => $this->weatherData()->avg('temperature'),
            'sunny_days' => $this->weatherData()->where('condition', 'sunny')->count(),
            'rainy_days' => $this->weatherData()->where('rainfall', '>', 0)->count(),
        ];
    }
}