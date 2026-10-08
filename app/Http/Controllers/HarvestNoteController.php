<?php

namespace App\Http\Controllers;

use App\Models\HarvestNote;
use App\Models\Harvest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class HarvestNoteController extends Controller
{
    /**
     * Get all notes for a harvest
     */
    public function index(string $harvestId): JsonResponse
    {
        $harvest = Harvest::find($harvestId);

        if (!$harvest) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        $notes = HarvestNote::forHarvest($harvestId)
            ->with('createdBy')
            ->orderBy('created_at', 'desc')
            ->get();

        $summary = HarvestNote::getHarvestNotesSummary($harvestId);

        return response()->json([
            'success' => true,
            'data' => [
                'harvest_id' => $harvestId,
                'notes' => $notes,
                'summary' => $summary
            ]
        ]);
    }

    /**
     * Create new harvest note
     */
    public function store(Request $request, string $harvestId): JsonResponse
    {
        $harvest = Harvest::find($harvestId);

        if (!$harvest) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'note' => 'required|string|max:1000',
            'note_type' => 'required|in:general,health_condition,pest_issue,weather_impact,quality_issue,anomaly,improvement',
            'metadata' => 'nullable|array',
            'is_critical' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $note = HarvestNote::create([
                'id' => Str::uuid(),
                'harvest_id' => $harvestId,
                'note' => $request->note,
                'note_type' => $request->note_type,
                'created_by' => $request->user()->id,
                'metadata' => $request->metadata,
                'is_critical' => $request->is_critical ?? false,
            ]);

            $note->load('createdBy');

            return response()->json([
                'success' => true,
                'message' => 'Note added successfully',
                'data' => $note
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update harvest note
     */
    public function update(Request $request, string $harvestId, string $noteId): JsonResponse
    {
        $note = HarvestNote::where('harvest_id', $harvestId)->find($noteId);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found'
            ], 404);
        }

        // Check if user can edit this note
        if (!$this->canEditNote($request->user(), $note)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to edit this note'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'note' => 'string|max:1000',
            'note_type' => 'in:general,health_condition,pest_issue,weather_impact,quality_issue,anomaly,improvement',
            'metadata' => 'nullable|array',
            'is_critical' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $note->update($request->only([
                'note', 
                'note_type', 
                'metadata', 
                'is_critical'
            ]));

            $note->load('createdBy');

            return response()->json([
                'success' => true,
                'message' => 'Note updated successfully',
                'data' => $note
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete harvest note
     */
    public function destroy(Request $request, string $harvestId, string $noteId): JsonResponse
    {
        $note = HarvestNote::where('harvest_id', $harvestId)->find($noteId);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found'
            ], 404);
        }

        // Check if user can delete this note
        if (!$this->canEditNote($request->user(), $note)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this note'
            ], 403);
        }

        try {
            $note->delete();

            return response()->json([
                'success' => true,
                'message' => 'Note deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete note',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark note as critical
     */
    public function markCritical(Request $request, string $harvestId, string $noteId): JsonResponse
    {
        $note = HarvestNote::where('harvest_id', $harvestId)->find($noteId);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Note not found'
            ], 404);
        }

        // Check if user can mark as critical
        if (!$this->canMarkCritical($request->user(), $note)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to mark notes as critical'
            ], 403);
        }

        try {
            $note->markAsCritical();

            return response()->json([
                'success' => true,
                'message' => 'Note marked as critical',
                'data' => $note
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark note as critical',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recurring issues for a crop cycle
     */
    public function recurringIssues(string $cropCycleId): JsonResponse
    {
        try {
            $issues = HarvestNote::getRecurringIssues($cropCycleId);

            return response()->json([
                'success' => true,
                'data' => [
                    'crop_cycle_id' => $cropCycleId,
                    'recurring_issues' => $issues
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get recurring issues',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get improvement suggestions for a crop cycle
     */
    public function improvementSuggestions(string $cropCycleId): JsonResponse
    {
        try {
            $suggestions = HarvestNote::getImprovementSuggestions($cropCycleId);

            return response()->json([
                'success' => true,
                'data' => [
                    'crop_cycle_id' => $cropCycleId,
                    'improvement_suggestions' => $suggestions
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get improvement suggestions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get critical notes across all harvests
     */
    public function criticalNotes(Request $request): JsonResponse
    {
        $query = HarvestNote::critical()->with(['harvest.cropCycle', 'createdBy']);

        // Filter by date range if provided
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        // Filter by note type if provided
        if ($request->has('note_type')) {
            $query->byType($request->note_type);
        }

        $criticalNotes = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $criticalNotes
        ]);
    }

    // Helper Methods

    /**
     * Check if user can edit note
     */
    protected function canEditNote($user, HarvestNote $note): bool
    {
        $role = $user->getRoleOnFarm($note->harvest->cropCycle->farm_id);
        
        // Owners and managers can edit any note
        if (in_array($role, ['owner', 'manager'])) {
            return true;
        }

        // Users can edit their own notes
        return $note->created_by === $user->id;
    }

    /**
     * Check if user can mark notes as critical
     */
    protected function canMarkCritical($user, HarvestNote $note): bool
    {
        $role = $user->getRoleOnFarm($note->harvest->cropCycle->farm_id);
        
        return in_array($role, ['owner', 'manager']);
    }
}
