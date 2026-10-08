<?php

namespace App\Http\Controllers;

use App\Models\Market;
use App\Models\MarketPrice;
use App\Models\MarketInsight;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MarketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Market::with(['marketPrices' => function ($q) {
                $q->where('price_date', '>=', now()->subDays(7))
                  ->orderBy('price_date', 'desc');
            }])
            ->active()
            ->orderBy('distance_from_farm', 'asc');

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$term}%")
                ->orWhere('location', 'ilike', "%{$term}%")
                ->orWhere('county', 'ilike', "%{$term}%"));
        }

        // Filter by location
        if ($request->has('county')) {
            $query->where('county', $request->county);
        }

        if ($request->has('ward')) {
            $query->where('ward', $request->ward);
        }

        // Filter by market type
        if ($request->has('market_type')) {
            $query->where('market_type', $request->market_type);
        }

        // Filter by crop
        if ($request->has('crop')) {
            $query->withCrop($request->crop);
        }

        // Filter by distance
        if ($request->has('max_distance') && $request->has('latitude') && $request->has('longitude')) {
            $query = Market::nearby($request->latitude, $request->longitude, $request->max_distance)
                ->active();
        }

        // Filter by facilities
        if ($request->has('has_cold_storage') && $request->boolean('has_cold_storage')) {
            $query->where('has_cold_storage', true);
        }

        if ($request->has('has_processing_facility') && $request->boolean('has_processing_facility')) {
            $query->where('has_processing_facility', true);
        }

        // Only verified markets
        if ($request->boolean('verified_only')) {
            $query->verified();
        }

        $markets = $query->paginate($request->per_page ?? 15);

        $markets->setCollection($markets->getCollection()->map(function ($market) {
                return array_merge($market->toArray(), [
                    'market_intelligence' => $market->getMarketIntelligence(),
                    'current_prices' => $market->getCurrentPrices(),
                ]);
            }));

        return response()->json(['success' => true, 'data' => $markets]);
    }

    public function show(Market $market): JsonResponse
    {
        $market->load([
            'marketPrices' => function ($q) {
                $q->orderBy('price_date', 'desc')->limit(30);
            },
            'insights' => function ($q) {
                $q->published()->orderBy('created_at', 'desc')->limit(10);
            }
        ]);

        return response()->json([
            'success' => true,
            'data' => array_merge($market->toArray(), [
                'market_intelligence' => $market->getMarketIntelligence(),
                'current_prices' => $market->getCurrentPrices(),
                'best_price_crops' => $market->getBestPriceCrops(),
                'is_operating_today' => $market->isOperatingToday(),
                'is_open_now' => $market->isOpenNow(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'market_type' => 'required|in:' . implode(',', array_keys(Market::MARKET_TYPES)),
            'description' => 'nullable|string',
            'county' => 'required|string|max:100',
            'ward' => 'nullable|string|max:100',
            'location' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'common_crops' => 'required|array',
            'common_crops.*' => 'string|max:100',
            'operating_days' => 'required|array',
            'operating_days.*' => 'string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'opening_time' => 'nullable|date_format:H:i',
            'closing_time' => 'nullable|date_format:H:i',
            'contact_phone' => 'nullable|string|max:20',
            'contact_person' => 'nullable|string|max:255',
            'has_cold_storage' => 'boolean',
            'has_processing_facility' => 'boolean',
            'has_transport_access' => 'boolean',
            'payment_methods' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            $data['is_active'] = true;
            $data['verification_status'] = 'pending';

            // Calculate distance from user's farm if coordinates provided
            $user = Auth::user();
            $farmId = $request->header('X-Tenant-ID');
            $farm = $farmId ? $user->farms()->where('farms.id', $farmId)->first() : null;
            
            if ($farm && $farm->latitude && $farm->longitude && 
                isset($data['latitude']) && isset($data['longitude'])) {
                $market = new Market($data);
                $data['distance_from_farm'] = $market->calculateDistance(
                    $farm->latitude, 
                    $farm->longitude
                );
            }

            $market = Market::create($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Market created successfully',
                'data' => $market,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create market',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, Market $market): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'market_type' => 'sometimes|required|in:' . implode(',', array_keys(Market::MARKET_TYPES)),
            'description' => 'nullable|string',
            'county' => 'sometimes|required|string|max:100',
            'ward' => 'nullable|string|max:100',
            'location' => 'sometimes|required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'common_crops' => 'sometimes|required|array',
            'operating_days' => 'sometimes|required|array',
            'opening_time' => 'nullable|date_format:H:i',
            'closing_time' => 'nullable|date_format:H:i',
            'contact_phone' => 'nullable|string|max:20',
            'contact_person' => 'nullable|string|max:255',
            'has_cold_storage' => 'boolean',
            'has_processing_facility' => 'boolean',
            'has_transport_access' => 'boolean',
            'payment_methods' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $market->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Market updated successfully',
                'data' => $market->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update market',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function verify(Request $request, Market $market): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        $validator = Validator::make($request->all(), [
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $market->verify(Auth::user(), $request->verification_notes);

            return response()->json([
                'success' => true,
                'message' => 'Market verified successfully',
                'data' => $market->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify market',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function rate(Request $request, Market $market): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|numeric|between:1,5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $market->updateRating($request->rating);

            return response()->json([
                'success' => true,
                'message' => 'Market rated successfully',
                'data' => [
                    'new_rating' => $market->fresh()->market_rating,
                    'total_reviews' => $market->fresh()->total_reviews,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to rate market',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function collectionPrices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop' => 'nullable|string|max:100',
            'market_id' => 'nullable|uuid|exists:markets,id',
            'grade' => 'nullable|string|max:50',
            'days' => 'nullable|integer|min:1|max:730',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = MarketPrice::with('market:id,name,location,county,distance_from_farm')->latest('price_date');
        if (! empty($validated['crop'])) {
            $query->where('crop_name', 'ilike', '%' . $validated['crop'] . '%');
        }
        foreach (['market_id', 'grade'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (! empty($validated['days'])) {
            $query->where('price_date', '>=', now()->subDays($validated['days']));
        }

        $prices = $query->paginate($validated['per_page'] ?? 50);
        $best = collect($prices->items())->sortByDesc(fn ($price) => (float) $price->average_price - (float) $price->transport_cost_per_kg)->first();
        return response()->json([
            'success' => true,
            'data' => $prices,
            'meta' => ['best_market_price' => $best],
        ]);
    }

    public function storePrice(Request $request, ?Market $market = null): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        $validated = $request->validate([
            'market_id' => [$market ? 'nullable' : 'required', 'uuid', 'exists:markets,id'],
            'crop_name' => 'required|string|max:100',
            'crop_variety' => 'nullable|string|max:100',
            'grade' => 'nullable|string|max:50',
            'unit' => 'required|string|max:20',
            'min_price' => 'required|numeric|min:0.01',
            'max_price' => 'required|numeric|gte:min_price',
            'average_price' => 'required|numeric|gte:min_price|lte:max_price',
            'modal_price' => 'nullable|numeric|min:0.01',
            'wholesale_price' => 'nullable|numeric|min:0.01',
            'retail_price' => 'nullable|numeric|min:0.01',
            'price_date' => 'required|date|before_or_equal:today',
            'demand_level' => 'required|in:very_low,low,normal,high,very_high',
            'supply_level' => 'required|in:very_low,low,normal,high,very_high',
            'quantity_available' => 'nullable|numeric|min:0',
            'quantity_sold' => 'nullable|numeric|min:0',
            'quality_rating' => 'nullable|in:poor,fair,good,excellent',
            'transport_cost_per_kg' => 'nullable|numeric|min:0',
            'data_source' => 'nullable|in:farmer_report,market_survey,buyer_feedback,government_data,api_feed',
            'data_confidence' => 'nullable|numeric|between:0,1',
            'notes' => 'nullable|string|max:1000',
        ]);
        $validated['market_id'] = $market?->id ?? $validated['market_id'];
        $validated['reported_by'] = $request->user()->id;
        $price = DB::transaction(function () use ($validated) {
            $price = MarketPrice::create($validated);
            $price->calculatePriceChange();
            $price->updateDaysFromLastUpdate();
            $price->checkPriceAlert();
            return $price->fresh('market:id,name,location,county,distance_from_farm');
        });

        if ($price->is_price_alert) {
            MarketInsight::create([
                'insight_type' => 'price_alert',
                'title' => "{$price->crop_name} price changed " . abs((float) $price->price_change_percentage) . '%',
                'description' => "The average price at {$price->market->name} is now KES {$price->average_price} per {$price->unit}.",
                'recommendation' => (float) $price->price_change_percentage > 0 ? 'Review this selling opportunity.' : 'Compare other markets before committing stock.',
                'crop_name' => $price->crop_name,
                'market_id' => $price->market_id,
                'severity' => abs((float) $price->price_change_percentage) >= 25 ? 'critical' : 'warning',
                'urgency' => 'high',
                'price_change_percentage' => $price->price_change_percentage,
                'current_price' => $price->average_price,
                'confidence_score' => $price->data_confidence,
                'insight_date' => now()->toDateString(),
                'data_source' => 'price_analysis',
                'generated_by' => request()->user()->id,
                'status' => 'published',
                'send_notification' => false,
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Market price recorded.', 'data' => $price], 201);
    }

    public function prices(Request $request, Market $market): JsonResponse
    {
        $query = $market->marketPrices()->orderBy('price_date', 'desc');

        if ($request->has('crop')) {
            $query->where('crop_name', $request->crop);
        }

        if ($request->has('grade')) {
            $query->where('grade', $request->grade);
        }

        if ($request->has('days')) {
            $days = max(1, min(365, (int) $request->days));
            $query->where('price_date', '>=', now()->subDays($days));
        }

        $prices = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $prices,
        ]);
    }

    public function priceHistory(Request $request, Market $market): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'crop' => 'required|string|max:100',
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $crop = $request->crop;
        $days = $request->days ?? 30;

        $history = $market->getPriceHistory($crop, $days);

        return response()->json([
            'success' => true,
            'data' => [
                'crop' => $crop,
                'market' => $market->name,
                'period_days' => $days,
                'price_history' => $history,
            ],
        ]);
    }

    public function analytics(Request $request, Market $market): JsonResponse
    {
        $days = $request->input('days', 30);
        
        $analytics = [
            'market_intelligence' => $market->getMarketIntelligence(),
            'current_prices' => $market->getCurrentPrices(),
            'best_price_crops' => $market->getBestPriceCrops(10),
            'price_trends' => $this->getPriceTrends($market, $days),
            'activity_summary' => $this->getActivitySummary($market, $days),
            'comparative_analysis' => $this->getComparativeAnalysis($market),
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics,
        ]);
    }

    private function getPriceTrends($market, $days): array
    {
        $prices = MarketPrice::where('market_id', $market->id)
            ->where('price_date', '>=', now()->subDays($days))
            ->orderBy('price_date', 'asc')
            ->get();

        return $prices->groupBy('crop_name')->map(function ($cropPrices, $crop) {
            $first = $cropPrices->first();
            $last = $cropPrices->last();
            
            $trend = 'stable';
            $change = 0;
            
            if ($first && $last && $first->average_price > 0) {
                $change = (($last->average_price - $first->average_price) / $first->average_price) * 100;
                $trend = $change > 5 ? 'increasing' : ($change < -5 ? 'decreasing' : 'stable');
            }

            return [
                'crop' => $crop,
                'trend' => $trend,
                'change_percentage' => round($change, 2),
                'current_price' => $last->average_price ?? 0,
                'data_points' => $cropPrices->count(),
            ];
        })->values();
    }

    private function getActivitySummary($market, $days): array
    {
        $prices = MarketPrice::where('market_id', $market->id)
            ->where('price_date', '>=', now()->subDays($days))
            ->get();

        return [
            'total_price_updates' => $prices->count(),
            'active_crops' => $prices->pluck('crop_name')->unique()->count(),
            'average_confidence' => round($prices->avg('data_confidence') ?? 0, 2),
            'price_alerts' => $prices->where('is_price_alert', true)->count(),
            'last_update' => $prices->max('created_at'),
        ];
    }

    private function getComparativeAnalysis($market): array
    {
        // Compare with other markets in same county
        $nearbyMarkets = Market::where('county', $market->county)
            ->where('id', '!=', $market->id)
            ->active()
            ->verified()
            ->get();

        return [
            'market_rank' => $this->getMarketRank($market),
            'nearby_markets_count' => $nearbyMarkets->count(),
            'average_rating_comparison' => [
                'this_market' => $market->market_rating,
                'county_average' => round($nearbyMarkets->avg('market_rating'), 2),
            ],
            'facility_comparison' => [
                'cold_storage_availability' => [
                    'this_market' => $market->has_cold_storage,
                    'county_percentage' => round(
                        $nearbyMarkets->where('has_cold_storage', true)->count() / 
                        max(1, $nearbyMarkets->count()) * 100, 1
                    ),
                ],
            ],
        ];
    }

    private function getMarketRank($market): int
    {
        $score = $market->calculateMarketScore();
        
        $betterMarkets = Market::where('county', $market->county)
            ->active()
            ->verified()
            ->get()
            ->filter(function ($m) use ($score) {
                return $m->calculateMarketScore() > $score;
            })
            ->count();

        return $betterMarkets + 1;
    }

    public function destroy(Request $request, Market $market): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        try {
            $market->is_active = false;
            $market->save();

            return response()->json([
                'success' => true,
                'message' => 'Market deactivated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate market',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function canManage(Request $request): bool
    {
        $farmId = $request->header('X-Tenant-ID');
        return in_array($farmId ? $request->user()?->getRoleOnFarm($farmId) : null, ['owner', 'manager'], true);
    }

    private function managementRequired(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Only farm owners and managers can manage market data.'], 403);
    }
}
