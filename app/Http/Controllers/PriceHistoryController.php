<?php

namespace App\Http\Controllers;

use App\Models\PriceHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PriceHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PriceHistory::query();

        // Apply filters
        if ($request->has('crop_name')) {
            $query->byCrop($request->crop_name);
        }

        if ($request->has('variety')) {
            $query->byVariety($request->variety);
        }

        if ($request->has('price_type')) {
            $query->byPriceType($request->price_type);
        }

        if ($request->has('season')) {
            $query->bySeason($request->season);
        }

        if ($request->has('location')) {
            $query->byLocation($request->location);
        }

        if ($request->has('quality_grade')) {
            $query->byQualityGrade($request->quality_grade);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $query->byDateRange($startDate, $endDate);
        } elseif ($request->has('days')) {
            $query->recent($request->days);
        }

        // Sorting
        $sortField = $request->get('sort', 'price_date');
        $sortDirection = $request->get('direction', 'desc');
        
        $allowedSorts = ['price_date', 'crop_name', 'price_per_unit', 'market_location', 'quantity_sold'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDirection);
        }

        // Include relationships
        $with = ['createdBy:id,name', 'sale:id,buyer_name'];
        $query->with($with);

        // Pagination
        $perPage = min($request->get('per_page', 20), 100);
        $priceHistory = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $priceHistory,
            'meta' => [
                'available_crops' => PriceHistory::distinct()->pluck('crop_name')->sort()->values(),
                'available_locations' => PriceHistory::distinct()->whereNotNull('market_location')->pluck('market_location')->sort()->values()
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_name' => 'required|string|max:255',
            'variety' => 'nullable|string|max:255',
            'unit' => 'required|string|max:50',
            'price_per_unit' => 'required|numeric|min:0.01|max:99999999.99',
            'price_date' => 'required|date|before_or_equal:today',
            'market_location' => 'nullable|string|max:255',
            'price_type' => ['required', Rule::in(['wholesale', 'retail', 'farmgate'])],
            'quality_grade' => ['nullable', Rule::in(['A', 'B', 'C', 'mixed', 'unknown'])],
            'quantity_sold' => 'nullable|numeric|min:0.001|max:999999.999',
            'season' => ['nullable', Rule::in(['dry', 'wet', 'harvest'])],
            'market_conditions' => 'nullable|string|max:1000',
            'demand_level' => 'nullable|numeric|min:1|max:5',
            'supply_level' => 'nullable|numeric|min:1|max:5'
        ]);

        $validated['created_by'] = auth()->id();

        $priceHistory = PriceHistory::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Price record created successfully',
            'data' => $priceHistory->load('createdBy:id,name')
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $priceHistory = PriceHistory::with(['createdBy:id,name', 'sale:id,buyer_name,sale_date'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $priceHistory
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $priceHistory = PriceHistory::findOrFail($id);

        // Prevent modification of system-generated records
        if ($priceHistory->sale_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot modify price records generated from sales'
            ], 400);
        }

        $validated = $request->validate([
            'crop_name' => 'sometimes|required|string|max:255',
            'variety' => 'nullable|string|max:255',
            'unit' => 'sometimes|required|string|max:50',
            'price_per_unit' => 'sometimes|required|numeric|min:0.01|max:99999999.99',
            'price_date' => 'sometimes|required|date|before_or_equal:today',
            'market_location' => 'nullable|string|max:255',
            'price_type' => ['sometimes', 'required', Rule::in(['wholesale', 'retail', 'farmgate'])],
            'quality_grade' => ['nullable', Rule::in(['A', 'B', 'C', 'mixed', 'unknown'])],
            'quantity_sold' => 'nullable|numeric|min:0.001|max:999999.999',
            'season' => ['nullable', Rule::in(['dry', 'wet', 'harvest'])],
            'market_conditions' => 'nullable|string|max:1000',
            'demand_level' => 'nullable|numeric|min:1|max:5',
            'supply_level' => 'nullable|numeric|min:1|max:5'
        ]);

        $priceHistory->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Price record updated successfully',
            'data' => $priceHistory->load('createdBy:id,name')
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $priceHistory = PriceHistory::findOrFail($id);

        // Prevent deletion of system-generated records
        if ($priceHistory->sale_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete price records generated from sales'
            ], 400);
        }

        $priceHistory->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Price record deleted successfully'
        ]);
    }

    public function getPriceTrends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_name' => 'required|string',
            'months' => 'nullable|integer|min:1|max:60'
        ]);

        $trends = PriceHistory::getPriceTrends(
            $validated['crop_name'],
            $validated['months'] ?? 12
        );

        return response()->json([
            'status' => 'success',
            'data' => $trends,
            'meta' => [
                'crop_name' => $validated['crop_name'],
                'period_months' => $validated['months'] ?? 12
            ]
        ]);
    }

    public function getCurrentMarketPrices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:30'
        ]);

        $prices = PriceHistory::getCurrentMarketPrices($validated['days'] ?? 7);

        return response()->json([
            'status' => 'success',
            'data' => $prices,
            'meta' => [
                'period_days' => $validated['days'] ?? 7,
                'last_updated' => now()->toISOString()
            ]
        ]);
    }

    public function getMarketComparison(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_name' => 'required|string',
            'variety' => 'nullable|string'
        ]);

        $comparison = PriceHistory::getMarketComparison(
            $validated['crop_name'],
            $validated['variety'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'data' => $comparison,
            'meta' => [
                'crop_name' => $validated['crop_name'],
                'variety' => $validated['variety'] ?? 'all varieties',
                'period' => 'last 30 days'
            ]
        ]);
    }

    public function getSeasonalPatterns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_name' => 'required|string',
            'years' => 'nullable|integer|min:1|max:5'
        ]);

        $patterns = PriceHistory::getSeasonalPatterns(
            $validated['crop_name'],
            $validated['years'] ?? 2
        );

        return response()->json([
            'status' => 'success',
            'data' => $patterns,
            'meta' => [
                'crop_name' => $validated['crop_name'],
                'period_years' => $validated['years'] ?? 2
            ]
        ]);
    }

    public function getPriceForecastData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_name' => 'required|string'
        ]);

        $forecastData = PriceHistory::getPriceForecastData($validated['crop_name']);

        return response()->json([
            'status' => 'success',
            'data' => $forecastData,
            'meta' => [
                'crop_name' => $validated['crop_name'],
                'generated_at' => now()->toISOString()
            ]
        ]);
    }

    public function getPriceAnalytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_name' => 'nullable|string',
            'days' => 'nullable|integer|min:1|max:365'
        ]);

        $query = PriceHistory::query();
        
        if (isset($validated['crop_name'])) {
            $query->byCrop($validated['crop_name']);
        }
        
        $query->recent($validated['days'] ?? 30);

        $analytics = [
            'price_summary' => [
                'total_records' => $query->count(),
                'average_price' => round($query->avg('price_per_unit'), 2),
                'min_price' => $query->min('price_per_unit'),
                'max_price' => $query->max('price_per_unit'),
                'total_quantity' => $query->sum('quantity_sold')
            ],
            'by_price_type' => $query->selectRaw('price_type, COUNT(*) as count, AVG(price_per_unit) as avg_price')
                ->groupBy('price_type')
                ->get()
                ->mapWithKeys(function($item) {
                    return [$item->price_type => [
                        'count' => $item->count,
                        'average_price' => round($item->avg_price, 2)
                    ]];
                })->toArray(),
            'by_location' => $query->whereNotNull('market_location')
                ->selectRaw('market_location, COUNT(*) as count, AVG(price_per_unit) as avg_price')
                ->groupBy('market_location')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(function($item) {
                    return [
                        'location' => $item->market_location,
                        'count' => $item->count,
                        'average_price' => round($item->avg_price, 2)
                    ];
                })->toArray(),
            'market_conditions' => [
                'average_demand' => round($query->avg('demand_level'), 1),
                'average_supply' => round($query->avg('supply_level'), 1),
                'demand_distribution' => $query->selectRaw('
                        CASE 
                            WHEN demand_level >= 4.5 THEN "Very High"
                            WHEN demand_level >= 3.5 THEN "High"
                            WHEN demand_level >= 2.5 THEN "Medium"
                            WHEN demand_level >= 1.5 THEN "Low"
                            ELSE "Very Low"
                        END as demand_category,
                        COUNT(*) as count
                    ')
                    ->groupBy('demand_category')
                    ->pluck('count', 'demand_category')
                    ->toArray()
            ]
        ];

        // Add volatility analysis if crop specified
        if (isset($validated['crop_name'])) {
            $analytics['volatility'] = [
                'volatility_index' => PriceHistory::calculateVolatilityIndex($validated['crop_name']),
                'price_stability' => PriceHistory::calculatePriceStability($validated['crop_name'])
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $analytics,
            'meta' => [
                'crop_name' => $validated['crop_name'] ?? 'all crops',
                'period_days' => $validated['days'] ?? 30,
                'generated_at' => now()->toISOString()
            ]
        ]);
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'price_records' => 'required|array|min:1|max:1000',
            'price_records.*.crop_name' => 'required|string|max:255',
            'price_records.*.price_per_unit' => 'required|numeric|min:0.01',
            'price_records.*.price_date' => 'required|date|before_or_equal:today',
            'price_records.*.unit' => 'required|string|max:50',
            'price_records.*.price_type' => ['required', Rule::in(['wholesale', 'retail', 'farmgate'])],
            'price_records.*.market_location' => 'nullable|string|max:255',
            'price_records.*.quantity_sold' => 'nullable|numeric|min:0.001',
            'price_records.*.variety' => 'nullable|string|max:255',
            'price_records.*.season' => ['nullable', Rule::in(['dry', 'wet', 'harvest'])],
            'price_records.*.quality_grade' => ['nullable', Rule::in(['A', 'B', 'C', 'mixed', 'unknown'])],
            'price_records.*.demand_level' => 'nullable|numeric|min:1|max:5',
            'price_records.*.supply_level' => 'nullable|numeric|min:1|max:5'
        ]);

        $created = [];
        $errors = [];

        foreach ($validated['price_records'] as $index => $record) {
            try {
                $record['created_by'] = auth()->id();
                $priceHistory = PriceHistory::create($record);
                $created[] = $priceHistory;
            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'record' => $record,
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Bulk import completed',
            'data' => [
                'created_count' => count($created),
                'error_count' => count($errors),
                'created_records' => $created,
                'errors' => $errors
            ]
        ], count($errors) > 0 ? 207 : 201); // 207 Multi-Status if there are errors
    }
}