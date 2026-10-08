<?php

namespace App\Models;

use App\Traits\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IrrigationSystem extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_id',
        'name',
        'type',
        'zone_name',
        'bed_ids',
        'status',
        'capacity_lph',
        'flow_rate_lph',
        'pressure_psi',
        'power_level',
        'temperature_celsius',
        'coverage_area',
        'water_usage_today_liters',
        'last_maintenance_at',
        'maintenance_notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'bed_ids' => 'array',
        'capacity_lph' => 'decimal:2',
        'flow_rate_lph' => 'decimal:2',
        'pressure_psi' => 'decimal:2',
        'power_level' => 'decimal:2',
        'temperature_celsius' => 'decimal:2',
        'coverage_area' => 'decimal:2',
        'water_usage_today_liters' => 'decimal:2',
        'last_maintenance_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(IrrigationSchedule::class);
    }
}
