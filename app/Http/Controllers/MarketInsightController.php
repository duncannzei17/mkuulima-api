<?php

namespace App\Http\Controllers;

use App\Models\MarketInsight;
use App\Models\MarketPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketInsightController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop' => 'nullable|string|max:100',
            'severity' => 'nullable|in:info,warning,critical',
            'featured' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = MarketInsight::with('market:id,name,location,county')->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->latest('published_at');
        foreach (['severity'] as $field) {
            if (! empty($validated[$field])) $query->where($field, $validated[$field]);
        }
        if (! empty($validated['crop'])) $query->where('crop_name', 'ilike', '%' . $validated['crop'] . '%');
        if ($request->boolean('featured')) $query->where('is_featured', true);
        return response()->json(['success' => true, 'data' => $query->paginate($validated['per_page'] ?? 20)]);
    }

    public function generate(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) return $this->managementRequired();
        $validated = $request->validate(['crop' => 'nullable|string|max:100']);
        $query = MarketPrice::with('market:id,name,location')->where('price_date', '>=', now()->subDays(14));
        if (! empty($validated['crop'])) $query->where('crop_name', 'ilike', '%' . $validated['crop'] . '%');
        $latest = $query->latest('price_date')->get()->unique(fn ($price) => $price->market_id . ':' . strtolower($price->crop_name));
        $created = $latest->map(function (MarketPrice $price) use ($request) {
            $type = in_array($price->demand_level, ['high', 'very_high'], true) ? 'demand_forecast' : 'market_opportunity';
            return MarketInsight::create([
                'insight_type' => $type,
                'title' => "{$price->crop_name}: {$price->demand_level} demand at {$price->market->name}",
                'description' => "Current average price is KES {$price->average_price} per {$price->unit}, with {$price->supply_level} supply.",
                'recommendation' => in_array($price->demand_level, ['high', 'very_high'], true) ? 'Consider matching available harvest to this market.' : 'Compare net prices and buyer reliability before selling.',
                'crop_name' => $price->crop_name,
                'market_id' => $price->market_id,
                'severity' => 'info',
                'urgency' => in_array($price->demand_level, ['high', 'very_high'], true) ? 'high' : 'medium',
                'current_price' => $price->average_price,
                'confidence_score' => $price->data_confidence,
                'supporting_data' => ['demand_level' => $price->demand_level, 'supply_level' => $price->supply_level, 'price_date' => $price->price_date],
                'insight_date' => now()->toDateString(),
                'valid_until' => now()->addDays(7)->toDateString(),
                'data_source' => 'price_analysis',
                'generated_by' => $request->user()->id,
                'status' => 'published',
                'send_notification' => false,
            ]);
        })->values();
        return response()->json(['success' => true, 'message' => "Generated {$created->count()} market insights.", 'data' => $created], 201);
    }

    public function destroy(Request $request, MarketInsight $marketInsight): JsonResponse
    {
        if (! $this->canManage($request)) return $this->managementRequired();
        $marketInsight->delete();
        return response()->json(['success' => true, 'message' => 'Market insight archived.']);
    }

    private function canManage(Request $request): bool
    {
        $farmId = $request->header('X-Tenant-ID');
        return in_array($farmId ? $request->user()?->getRoleOnFarm($farmId) : null, ['owner', 'manager'], true);
    }

    private function managementRequired(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Only farm owners and managers can manage market insights.'], 403);
    }
}
