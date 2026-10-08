<?php

namespace App\Models;

use App\Traits\HasUuids;
use App\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationTag extends Model
{
    use HasFactory, HasUuids, TenantScope;

    protected $fillable = [
        'observation_id',
        'tag',
        'tag_category',
    ];

    public const PREDEFINED_TAGS = [
        'symptoms' => [
            'scorching',
            'wilting',
            'yellowing',
            'brown_spots',
            'leaf_curl',
            'stunted_growth',
            'root_rot',
        ],
        'causes' => [
            'transplant_shock',
            'underwatering',
            'overwatering',
            'pest_pressure',
            'fungal_infection',
            'bacterial_infection',
            'nutrient_burn',
            'heat_stress',
            'cold_damage',
        ],
        'severity' => [
            'isolated',
            'spreading',
            'widespread',
            'contained',
            'recovering',
        ],
        'location' => [
            'border_plants',
            'center_plants',
            'shaded_area',
            'sunny_area',
            'low_lying',
            'elevated',
        ],
        'treatment' => [
            'treated',
            'monitoring',
            'quarantined',
            'removed',
            'scheduled_treatment',
        ],
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(FieldObservation::class, 'observation_id');
    }

    public static function getTagsByCategory(string $category): array
    {
        return self::PREDEFINED_TAGS[$category] ?? [];
    }

    public static function getAllPredefinedTags(): array
    {
        return collect(self::PREDEFINED_TAGS)->flatten()->toArray();
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('tag_category', $category);
    }

    public function scopeByTag($query, string $tag)
    {
        return $query->where('tag', $tag);
    }
}