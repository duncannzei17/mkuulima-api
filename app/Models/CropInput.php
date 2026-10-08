<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Carbon;

class CropInput extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'crop_cycle_id',
        'input_name',
        'input_type',
        'brand',
        'active_ingredient',
        'planned_quantity',
        'planned_unit',
        'planned_cost_per_unit',
        'planned_total_cost',
        'planned_application_date',
        'actual_quantity',
        'actual_cost_per_unit',
        'actual_total_cost',
        'actual_application_date',
        'application_method',
        'application_equipment',
        'applied_by',
        'effectiveness',
        'effectiveness_notes',
        'pre_harvest_interval_days',
        'safe_harvest_date',
        'organic_approved',
        'certification_body',
        'supplier',
        'batch_number',
        'expiry_date',
        'weather_condition',
        'wind_speed',
        'temperature',
        'humidity',
        'status',
        'planning_notes',
        'application_notes',
        'applied_at',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:2',
        'planned_cost_per_unit' => 'decimal:2',
        'planned_total_cost' => 'decimal:2',
        'planned_application_date' => 'date',
        'actual_quantity' => 'decimal:2',
        'actual_cost_per_unit' => 'decimal:2',
        'actual_total_cost' => 'decimal:2',
        'actual_application_date' => 'date',
        'pre_harvest_interval_days' => 'integer',
        'safe_harvest_date' => 'date',
        'organic_approved' => 'boolean',
        'expiry_date' => 'date',
        'wind_speed' => 'decimal:2',
        'temperature' => 'decimal:2',
        'humidity' => 'decimal:2',
        'applied_at' => 'datetime',
    ];

    // Relationships
    public function cropCycle()
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function appliedByUser()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    // Scopes
    public function scopePlanned($query)
    {
        return $query->where('status', 'planned');
    }

    public function scopeApplied($query)
    {
        return $query->where('status', 'applied');
    }

    public function scopePurchased($query)
    {
        return $query->where('status', 'purchased');
    }

    public function scopeByType($query, $inputType)
    {
        return $query->where('input_type', $inputType);
    }

    public function scopeChemicals($query)
    {
        return $query->whereIn('input_type', ['pesticide', 'herbicide', 'fungicide']);
    }

    public function scopeFertilizers($query)
    {
        return $query->whereIn('input_type', ['fertilizer', 'manure', 'compost']);
    }

    public function scopeDueForApplication($query, $days = 7)
    {
        return $query->where('status', '!=', 'applied')
                    ->where('planned_application_date', '<=', now()->addDays($days));
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->whereNotNull('expiry_date')
                    ->where('expiry_date', '<=', now()->addDays($days))
                    ->where('expiry_date', '>=', now());
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')
                    ->where('expiry_date', '<', now());
    }

    public function scopeOrganicOnly($query)
    {
        return $query->where('organic_approved', true);
    }

    public function scopeForCrop($query, $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    // Accessors
    public function getIsExpiredAttribute()
    {
        return $this->expiry_date && $this->expiry_date < now();
    }

    public function getIsExpiringSoonAttribute($days = 30)
    {
        if (!$this->expiry_date) return false;
        return $this->expiry_date <= now()->addDays($days) && $this->expiry_date >= now();
    }

    public function getDaysUntilApplicationAttribute()
    {
        if (!$this->planned_application_date || $this->status === 'applied') return null;
        return now()->diffInDays($this->planned_application_date, false);
    }

    public function getIsOverdueAttribute()
    {
        return $this->status !== 'applied' && 
               $this->planned_application_date && 
               $this->planned_application_date < now();
    }

    public function getCostVarianceAttribute()
    {
        if (!$this->planned_total_cost || !$this->actual_total_cost) return null;
        
        $variance = (($this->actual_total_cost - $this->planned_total_cost) / $this->planned_total_cost) * 100;
        return round($variance, 2);
    }

    public function getQuantityVarianceAttribute()
    {
        if (!$this->planned_quantity || !$this->actual_quantity) return null;
        
        $variance = (($this->actual_quantity - $this->planned_quantity) / $this->planned_quantity) * 100;
        return round($variance, 2);
    }

    public function getEffectivenessBadgeAttribute()
    {
        $badges = [
            'excellent' => ['text' => 'Excellent', 'color' => 'green'],
            'good' => ['text' => 'Good', 'color' => 'blue'],
            'fair' => ['text' => 'Fair', 'color' => 'yellow'],
            'poor' => ['text' => 'Poor', 'color' => 'orange'],
            'none' => ['text' => 'No Effect', 'color' => 'red'],
        ];
        
        return $badges[$this->effectiveness] ?? ['text' => 'Unknown', 'color' => 'gray'];
    }

    public function getSafetyStatusAttribute()
    {
        if (!$this->safe_harvest_date) return 'safe';
        
        return $this->safe_harvest_date > now() ? 'restricted' : 'safe';
    }

    public function getDaysUntilSafeHarvestAttribute()
    {
        if (!$this->safe_harvest_date) return 0;
        return max(0, now()->diffInDays($this->safe_harvest_date, false));
    }

    public function getFormattedCostAttribute()
    {
        $cost = $this->actual_total_cost ?: $this->planned_total_cost;
        return $cost ? 'KES ' . number_format($cost, 2) : 'Not set';
    }

    public function getInputTypeIconAttribute()
    {
        $icons = [
            'seed' => '🌱',
            'seedling' => '🌿',
            'fertilizer' => '💚',
            'pesticide' => '🐛',
            'herbicide' => '🌾',
            'fungicide' => '🍄',
            'manure' => '💩',
            'compost' => '🍂',
            'water' => '💧',
            'fuel' => '⛽',
        ];
        
        return $icons[$this->input_type] ?? '📦';
    }

    // Business Logic Methods
    public function markAsPurchased($actualCostPerUnit = null, $supplier = null, $batchNumber = null)
    {
        $this->status = 'purchased';
        
        if ($actualCostPerUnit) {
            $this->actual_cost_per_unit = $actualCostPerUnit;
            $this->actual_total_cost = $actualCostPerUnit * $this->planned_quantity;
        }
        
        if ($supplier) {
            $this->supplier = $supplier;
        }
        
        if ($batchNumber) {
            $this->batch_number = $batchNumber;
        }
        
        $this->save();
    }

    public function recordApplication(array $applicationData)
    {
        $this->fill($applicationData);
        $this->status = 'applied';
        $this->actual_application_date = $applicationData['actual_application_date'] ?? now()->toDateString();
        $this->applied_at = now();
        
        // Calculate safe harvest date for chemicals
        if (in_array($this->input_type, ['pesticide', 'herbicide', 'fungicide']) && $this->pre_harvest_interval_days) {
            $this->safe_harvest_date = Carbon::parse($this->actual_application_date)->addDays($this->pre_harvest_interval_days);
        }
        
        $this->save();
        
        // Update crop cycle with input application
        $this->updateCropCycleStatus();
    }

    protected function updateCropCycleStatus()
    {
        $crop = $this->cropCycle;
        
        // Add cost to crop cycle
        $crop->actual_cost += $this->actual_total_cost ?? $this->planned_total_cost;
        $crop->save();
    }

    public function updateEffectiveness($effectiveness, $notes = null)
    {
        $this->effectiveness = $effectiveness;
        $this->effectiveness_notes = $notes;
        $this->save();
    }

    public function cancel($reason = null)
    {
        $this->status = 'cancelled';
        $this->application_notes = $reason ? "Cancelled: {$reason}" : 'Input application cancelled';
        $this->save();
    }

    public function reschedule($newDate, $reason = null)
    {
        $this->planned_application_date = $newDate;
        $this->planning_notes = ($this->planning_notes ? $this->planning_notes . "\n" : '') .
                               "Rescheduled to {$newDate}" . ($reason ? ": {$reason}" : '');
        $this->save();
    }

    public function checkSafetyCompliance()
    {
        $issues = [];
        
        // Check expiry date
        if ($this->is_expired) {
            $issues[] = 'Product has expired';
        }
        
        // Check pre-harvest interval
        if ($this->safety_status === 'restricted') {
            $days = $this->days_until_safe_harvest;
            $issues[] = "Pre-harvest interval: {$days} days remaining";
        }
        
        // Check organic certification
        if (!$this->organic_approved && $this->cropCycle->organic_certified) {
            $issues[] = 'Not approved for organic farming';
        }
        
        return $issues;
    }

    public function getWeatherSuitability()
    {
        $suitable = true;
        $reasons = [];
        
        if ($this->wind_speed > 15) {
            $suitable = false;
            $reasons[] = 'Wind speed too high for spraying';
        }
        
        if ($this->temperature > 30) {
            $suitable = false;
            $reasons[] = 'Temperature too high for optimal application';
        }
        
        if ($this->weather_condition === 'rainy') {
            $suitable = false;
            $reasons[] = 'Rain will wash away application';
        }
        
        return [
            'suitable' => $suitable,
            'reasons' => $reasons,
        ];
    }

    // Static utility methods
    public static function getInputTypes()
    {
        return [
            'seed' => 'Seeds',
            'seedling' => 'Seedlings',
            'fertilizer' => 'Fertilizer',
            'pesticide' => 'Pesticide',
            'herbicide' => 'Herbicide',
            'fungicide' => 'Fungicide',
            'manure' => 'Manure',
            'compost' => 'Compost',
            'water' => 'Water/Irrigation',
            'fuel' => 'Fuel',
            'other' => 'Other',
        ];
    }

    public static function getApplicationMethods()
    {
        return [
            'spray' => 'Spray Application',
            'broadcast' => 'Broadcast',
            'drip' => 'Drip Application',
            'foliar' => 'Foliar Spray',
            'soil_drench' => 'Soil Drench',
            'granular' => 'Granular Application',
            'injection' => 'Soil Injection',
        ];
    }

    public static function getUnits()
    {
        return [
            'kg' => 'Kilograms',
            'g' => 'Grams',
            'l' => 'Liters',
            'ml' => 'Milliliters',
            'bags' => 'Bags',
            'packets' => 'Packets',
            'bottles' => 'Bottles',
            'pieces' => 'Pieces',
        ];
    }

    public static function getStatusLabels()
    {
        return [
            'planned' => 'Planned',
            'purchased' => 'Purchased',
            'applied' => 'Applied',
            'cancelled' => 'Cancelled',
        ];
    }
}