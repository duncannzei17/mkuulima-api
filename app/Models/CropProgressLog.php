<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CropProgressLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'crop_cycle_id',
        'log_date',
        'log_time',
        'logged_by',
        'health_rating',
        'growth_stage',
        'plant_height',
        'plant_count',
        'coverage_percentage',
        'pest_issues',
        'disease_issues',
        'nutrient_deficiencies',
        'weed_pressure',
        'weed_level',
        'weather_condition',
        'temperature',
        'humidity',
        'rainfall',
        'irrigated',
        'irrigation_amount',
        'activities_done',
        'inputs_applied',
        'photos',
        'observations',
        'recommendations',
        'next_actions',
        'yield_harvested',
        'quality_grade',
        'harvest_details',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'log_date' => 'date',
        'log_time' => 'time',
        'plant_height' => 'decimal:2',
        'plant_count' => 'integer',
        'coverage_percentage' => 'decimal:2',
        'pest_issues' => 'array',
        'disease_issues' => 'array',
        'nutrient_deficiencies' => 'array',
        'weed_pressure' => 'boolean',
        'temperature' => 'decimal:2',
        'humidity' => 'decimal:2',
        'rainfall' => 'decimal:2',
        'irrigated' => 'boolean',
        'irrigation_amount' => 'decimal:2',
        'activities_done' => 'array',
        'inputs_applied' => 'array',
        'photos' => 'array',
        'yield_harvested' => 'decimal:2',
        'quality_grade' => 'decimal:1',
        'harvest_details' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    // Relationships
    public function cropCycle()
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function logger()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    // Scopes
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('log_date', '>=', now()->subDays($days));
    }

    public function scopeByHealthRating($query, $rating)
    {
        return $query->where('health_rating', $rating);
    }

    public function scopeByGrowthStage($query, $stage)
    {
        return $query->where('growth_stage', $stage);
    }

    public function scopeWithPestIssues($query)
    {
        return $query->whereNotNull('pest_issues')
                    ->where('pest_issues', '!=', '[]');
    }

    public function scopeWithDiseaseIssues($query)
    {
        return $query->whereNotNull('disease_issues')
                    ->where('disease_issues', '!=', '[]');
    }

    public function scopeWithPhotos($query)
    {
        return $query->whereNotNull('photos')
                    ->where('photos', '!=', '[]');
    }

    public function scopeHarvestLogs($query)
    {
        return $query->whereNotNull('yield_harvested')
                    ->where('yield_harvested', '>', 0);
    }

    public function scopeForCrop($query, $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    public function scopeByDate($query, $startDate, $endDate = null)
    {
        $query->where('log_date', '>=', $startDate);
        
        if ($endDate) {
            $query->where('log_date', '<=', $endDate);
        }
        
        return $query;
    }

    // Accessors
    public function getHealthScoreAttribute()
    {
        $healthScores = [
            'excellent' => 100,
            'good' => 80,
            'fair' => 60,
            'poor' => 40,
            'critical' => 20,
        ];
        
        return $healthScores[$this->health_rating] ?? 60;
    }

    public function getHasIssuesAttribute()
    {
        return !empty($this->pest_issues) || 
               !empty($this->disease_issues) || 
               !empty($this->nutrient_deficiencies) ||
               $this->weed_pressure;
    }

    public function getTotalIssuesCountAttribute()
    {
        $count = 0;
        $count += count($this->pest_issues ?? []);
        $count += count($this->disease_issues ?? []);
        $count += count($this->nutrient_deficiencies ?? []);
        $count += $this->weed_pressure ? 1 : 0;
        
        return $count;
    }

    public function getFormattedWeatherAttribute()
    {
        $weather = $this->weather_condition;
        
        if ($this->temperature) {
            $weather .= ", {$this->temperature}°C";
        }
        
        if ($this->humidity) {
            $weather .= ", {$this->humidity}% humidity";
        }
        
        if ($this->rainfall > 0) {
            $weather .= ", {$this->rainfall}mm rain";
        }
        
        return $weather;
    }

    public function getGrowthStageProgressAttribute()
    {
        $stages = [
            'germination' => 10,
            'seedling' => 20,
            'vegetative' => 40,
            'flowering' => 60,
            'fruiting' => 80,
            'maturing' => 90,
            'harvest_ready' => 100,
        ];
        
        return $stages[$this->growth_stage] ?? 0;
    }

    public function getActivitySummaryAttribute()
    {
        $activities = $this->activities_done ?? [];
        $inputs = $this->inputs_applied ?? [];
        
        $summary = [];
        
        if (!empty($activities)) {
            $summary[] = count($activities) . ' activities completed';
        }
        
        if (!empty($inputs)) {
            $summary[] = count($inputs) . ' inputs applied';
        }
        
        if ($this->irrigated) {
            $amount = $this->irrigation_amount ? " ({$this->irrigation_amount}L)" : '';
            $summary[] = "Irrigated{$amount}";
        }
        
        return implode(', ', $summary) ?: 'No activities recorded';
    }

    // Business Logic Methods
    public function addPhoto($photoPath, $description = null)
    {
        $photos = $this->photos ?? [];
        $photos[] = [
            'path' => $photoPath,
            'description' => $description,
            'uploaded_at' => now()->toISOString(),
        ];
        
        $this->photos = $photos;
        $this->save();
    }

    public function recordPestIssue($pestName, $severity = 'moderate', $notes = null)
    {
        $pests = $this->pest_issues ?? [];
        $pests[] = [
            'name' => $pestName,
            'severity' => $severity,
            'notes' => $notes,
            'recorded_at' => now()->toISOString(),
        ];
        
        $this->pest_issues = $pests;
        $this->save();
    }

    public function recordDiseaseIssue($diseaseName, $severity = 'moderate', $notes = null)
    {
        $diseases = $this->disease_issues ?? [];
        $diseases[] = [
            'name' => $diseaseName,
            'severity' => $severity,
            'notes' => $notes,
            'recorded_at' => now()->toISOString(),
        ];
        
        $this->disease_issues = $diseases;
        $this->save();
    }

    public function recordActivity($activity, $details = null)
    {
        $activities = $this->activities_done ?? [];
        $activities[] = [
            'activity' => $activity,
            'details' => $details,
            'recorded_at' => now()->toISOString(),
        ];
        
        $this->activities_done = $activities;
        $this->save();
    }

    public function recordInputApplication($inputName, $quantity, $unit, $method = null)
    {
        $inputs = $this->inputs_applied ?? [];
        $inputs[] = [
            'name' => $inputName,
            'quantity' => $quantity,
            'unit' => $unit,
            'method' => $method,
            'applied_at' => now()->toISOString(),
        ];
        
        $this->inputs_applied = $inputs;
        $this->save();
    }

    public function getWeatherScore()
    {
        $score = 50; // Base score
        
        // Temperature scoring
        if ($this->temperature) {
            if ($this->temperature >= 20 && $this->temperature <= 30) {
                $score += 20;
            } elseif ($this->temperature >= 15 && $this->temperature <= 35) {
                $score += 10;
            }
        }
        
        // Humidity scoring
        if ($this->humidity) {
            if ($this->humidity >= 60 && $this->humidity <= 80) {
                $score += 15;
            } elseif ($this->humidity >= 40 && $this->humidity <= 90) {
                $score += 10;
            }
        }
        
        // Rainfall scoring
        if ($this->rainfall > 0) {
            if ($this->rainfall <= 10) {
                $score += 15;
            } elseif ($this->rainfall <= 25) {
                $score += 10;
            } elseif ($this->rainfall > 50) {
                $score -= 10; // Too much rain
            }
        }
        
        return min(100, max(0, $score));
    }

    // Static utility methods
    public static function getHealthRatings()
    {
        return [
            'excellent' => 'Excellent Health',
            'good' => 'Good Health',
            'fair' => 'Fair Condition',
            'poor' => 'Poor Health',
            'critical' => 'Critical Condition',
        ];
    }

    public static function getGrowthStages()
    {
        return [
            'germination' => 'Germination',
            'seedling' => 'Seedling',
            'vegetative' => 'Vegetative Growth',
            'flowering' => 'Flowering',
            'fruiting' => 'Fruiting',
            'maturing' => 'Maturing',
            'harvest_ready' => 'Ready for Harvest',
        ];
    }

    public static function getWeedLevels()
    {
        return [
            'none' => 'No Weeds',
            'light' => 'Light Weed Pressure',
            'moderate' => 'Moderate Weeds',
            'heavy' => 'Heavy Weed Pressure',
        ];
    }

    public static function getWeatherConditions()
    {
        return [
            'sunny' => 'Sunny',
            'partly_cloudy' => 'Partly Cloudy',
            'cloudy' => 'Cloudy',
            'rainy' => 'Rainy',
            'stormy' => 'Stormy',
            'windy' => 'Windy',
            'foggy' => 'Foggy',
        ];
    }

    public static function getSeverityLevels()
    {
        return [
            'mild' => 'Mild',
            'moderate' => 'Moderate',
            'severe' => 'Severe',
            'critical' => 'Critical',
        ];
    }
}