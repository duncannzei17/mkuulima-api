<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WeatherAlert extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'farm_id',
        'user_id',
        'alert_type',
        'severity',
        'title',
        'message',
        'weather_data',
        'conditions_met',
        'threshold_values',
        'recommended_actions',
        'status',
        'triggered_at',
        'acknowledged_at',
        'expires_at',
        'metadata'
    ];

    protected $casts = [
        'weather_data' => 'array',
        'conditions_met' => 'array',
        'threshold_values' => 'array',
        'recommended_actions' => 'array',
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'active',
        'severity' => 'medium'
    ];

    // Relationships
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('alert_type', $type);
    }

    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    // Methods
    public function acknowledge()
    {
        $this->update([
            'acknowledged_at' => now(),
            'status' => 'acknowledged'
        ]);
    }

    public function dismiss()
    {
        $this->update(['status' => 'dismissed']);
    }

    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isAcknowledged()
    {
        return !is_null($this->acknowledged_at);
    }

    public function getSeverityColorAttribute()
    {
        return match($this->severity) {
            'low' => 'green',
            'medium' => 'yellow',
            'high' => 'orange',
            'critical' => 'red',
            default => 'gray'
        };
    }

    public function getSeverityIconAttribute()
    {
        return match($this->alert_type) {
            'heavy_rain' => '🌧️',
            'drought' => '☀️',
            'extreme_temperature' => '🌡️',
            'strong_wind' => '💨',
            'frost' => '❄️',
            'humidity' => '💧',
            'storm' => '⛈️',
            default => '⚠️'
        };
    }
}