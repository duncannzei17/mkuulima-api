<?php

namespace App\Models;

use App\Traits\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IrrigationSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_id',
        'irrigation_system_id',
        'name',
        'start_time',
        'duration_minutes',
        'days_of_week',
        'status',
        'start_date',
        'end_date',
        'last_run_at',
        'next_run_at',
        'water_amount_liters',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'water_amount_liters' => 'decimal:2',
    ];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function irrigationSystem(): BelongsTo
    {
        return $this->belongsTo(IrrigationSystem::class);
    }
}
