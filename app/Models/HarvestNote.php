<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestNote extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'harvest_id',
        'note',
        'note_type',
        'created_by',
        'metadata',
        'is_critical'
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_critical' => 'boolean',
    ];

    protected $attributes = [
        'note_type' => 'general',
        'is_critical' => false,
    ];

    // Relationships
    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('note_type', $type);
    }

    public function scopeForHarvest($query, string $harvestId)
    {
        return $query->where('harvest_id', $harvestId);
    }

    public function scopeHealthIssues($query)
    {
        return $query->whereIn('note_type', [
            'health_condition', 
            'pest_issue', 
            'quality_issue'
        ]);
    }

    // Business Logic Methods

    /**
     * Mark note as critical
     */
    public function markAsCritical(): void
    {
        $this->update(['is_critical' => true]);
    }

    /**
     * Get notes summary for a harvest
     */
    public static function getHarvestNotesSummary(string $harvestId): array
    {
        $notes = static::forHarvest($harvestId)->get();

        return [
            'total_notes' => $notes->count(),
            'critical_notes' => $notes->where('is_critical', true)->count(),
            'by_type' => [
                'general' => $notes->where('note_type', 'general')->count(),
                'health_condition' => $notes->where('note_type', 'health_condition')->count(),
                'pest_issue' => $notes->where('note_type', 'pest_issue')->count(),
                'weather_impact' => $notes->where('note_type', 'weather_impact')->count(),
                'quality_issue' => $notes->where('note_type', 'quality_issue')->count(),
                'anomaly' => $notes->where('note_type', 'anomaly')->count(),
                'improvement' => $notes->where('note_type', 'improvement')->count(),
            ],
            'critical_issues' => $notes->where('is_critical', true)->map(function($note) {
                return [
                    'type' => $note->note_type,
                    'note' => $note->note,
                    'created_at' => $note->created_at->format('Y-m-d H:i'),
                ];
            })->toArray(),
        ];
    }

    /**
     * Get recurring issues analysis
     */
    public static function getRecurringIssues(string $cropCycleId): array
    {
        $notes = static::healthIssues()
            ->whereHas('harvest', function($query) use ($cropCycleId) {
                $query->where('crop_cycle_id', $cropCycleId);
            })
            ->get();

        $issuePatterns = [];

        foreach ($notes as $note) {
            $key = $note->note_type;
            if (!isset($issuePatterns[$key])) {
                $issuePatterns[$key] = [
                    'type' => $key,
                    'frequency' => 0,
                    'critical_instances' => 0,
                    'recent_occurrences' => [],
                ];
            }

            $issuePatterns[$key]['frequency']++;
            
            if ($note->is_critical) {
                $issuePatterns[$key]['critical_instances']++;
            }

            $issuePatterns[$key]['recent_occurrences'][] = [
                'date' => $note->created_at->format('Y-m-d'),
                'note' => $note->note,
                'is_critical' => $note->is_critical,
            ];
        }

        // Sort by frequency
        uasort($issuePatterns, function($a, $b) {
            return $b['frequency'] <=> $a['frequency'];
        });

        return array_values($issuePatterns);
    }

    /**
     * Get improvement suggestions based on notes
     */
    public static function getImprovementSuggestions(string $cropCycleId): array
    {
        $improvementNotes = static::byType('improvement')
            ->whereHas('harvest', function($query) use ($cropCycleId) {
                $query->where('crop_cycle_id', $cropCycleId);
            })
            ->get();

        $healthIssues = static::getRecurringIssues($cropCycleId);

        $suggestions = [];

        // Add explicit improvement notes
        foreach ($improvementNotes as $note) {
            $suggestions[] = [
                'type' => 'improvement_note',
                'suggestion' => $note->note,
                'source' => 'Direct farmer input',
                'priority' => $note->is_critical ? 'High' : 'Medium',
                'date' => $note->created_at->format('Y-m-d'),
            ];
        }

        // Generate suggestions based on recurring issues
        foreach ($healthIssues as $issue) {
            if ($issue['frequency'] >= 3) { // Recurring issue
                $suggestions[] = [
                    'type' => 'issue_prevention',
                    'suggestion' => static::generateSuggestionForIssue($issue['type']),
                    'source' => 'Pattern analysis',
                    'priority' => $issue['critical_instances'] > 0 ? 'High' : 'Medium',
                    'issue_frequency' => $issue['frequency'],
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Generate suggestion for common issues
     */
    protected static function generateSuggestionForIssue(string $issueType): string
    {
        $suggestions = [
            'pest_issue' => 'Consider implementing integrated pest management (IPM) strategies or adjusting spray schedules.',
            'health_condition' => 'Review soil health, nutrient management, and watering practices.',
            'quality_issue' => 'Evaluate harvest timing, handling practices, and post-harvest storage conditions.',
        ];

        return $suggestions[$issueType] ?? 'Review farming practices related to this recurring issue.';
    }
}