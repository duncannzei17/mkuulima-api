<?php

namespace App\Http\Controllers;

use App\Events\SellOrderEvents\SellOrderCreated;
use App\Events\SellOrderEvents\SellOrderDispatchRequested;
use App\Events\SellOrderEvents\SellOrderStatusChanged;
use App\Models\Buyer;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\Market;
use App\Models\MarketPrice;
use App\Models\Sale;
use App\Models\SellOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SellOrderController extends Controller
{
    private const ACTIVE_STATUSES = [
        'pending', 'buyer_confirmed', 'price_agreed', 'logistics_assigned', 'picked_up', 'in_transit',
    ];

    private const TRANSITIONS = [
        'pending' => ['buyer_confirmed'],
        'buyer_confirmed' => ['price_agreed'],
        'price_agreed' => ['logistics_assigned'],
        'logistics_assigned' => ['picked_up'],
        'picked_up' => ['in_transit'],
        'in_transit' => ['delivered'],
        'delivered' => ['payment_received'],
        'payment_received' => ['completed'],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(SellOrder::STATUSES))],
            'crop' => 'nullable|string|max:100',
            'buyer_id' => 'nullable|uuid',
            'market_id' => 'nullable|uuid',
            'search' => 'nullable|string|max:100',
            'active_only' => 'nullable|boolean',
            'my_orders' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = SellOrder::query()
            ->with(['cropCycle:id,crop_name,variety', 'buyer:id,name,type,location,reliability_score,phone,phone_encrypted', 'market:id,name,location,county', 'createdBy:id,name'])
            ->latest();

        foreach (['status', 'buyer_id', 'market_id'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (! empty($validated['crop'])) {
            $query->where('crop_name', 'ilike', '%' . $validated['crop'] . '%');
        }
        if (! empty($validated['search'])) {
            $term = $validated['search'];
            $query->where(fn ($q) => $q->where('order_number', 'ilike', "%{$term}%")
                ->orWhere('crop_name', 'ilike', "%{$term}%"));
        }
        if ($request->boolean('active_only')) {
            $query->whereIn('status', self::ACTIVE_STATUSES);
        }
        if ($request->boolean('my_orders')) {
            $query->where('created_by', $request->user()->id);
        }

        $orders = $query->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $orders,
            'meta' => $this->summary(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'crop_cycle_id' => 'required|uuid|exists:crop_cycles,id',
            'harvest_id' => 'nullable|uuid|exists:harvests,id',
            'buyer_id' => 'required|uuid|exists:buyers,id',
            'market_id' => 'required|uuid|exists:markets,id',
            'crop_name' => 'nullable|string|max:100',
            'crop_variety' => 'nullable|string|max:100',
            'quantity' => 'required|numeric|min:0.001|max:999999.99',
            'unit' => 'required|string|max:20',
            'grade' => 'required|string|max:50',
            'quality_description' => 'nullable|string|max:1000',
            'asking_price_per_unit' => 'required|numeric|min:0.01|max:99999999.99',
            'minimum_acceptable_price' => 'required|numeric|min:0.01|lte:asking_price_per_unit',
            'is_negotiable' => 'nullable|boolean',
            'pickup_location' => 'required|string|max:255',
            'pickup_latitude' => 'nullable|numeric|between:-90,90',
            'pickup_longitude' => 'nullable|numeric|between:-180,180',
            'delivery_location' => 'nullable|string|max:255',
            'delivery_latitude' => 'nullable|numeric|between:-90,90',
            'delivery_longitude' => 'nullable|numeric|between:-180,180',
            'preferred_pickup_time' => 'required|date|after:now',
            'latest_pickup_time' => 'nullable|date|after:preferred_pickup_time',
            'flexible_timing' => 'nullable|boolean',
            'special_instructions' => 'nullable|string|max:1000',
            'urgency_level' => ['nullable', Rule::in(array_keys(SellOrder::URGENCY_LEVELS))],
        ]);

        $order = DB::transaction(function () use ($validated, $request) {
            $cropCycle = CropCycle::lockForUpdate()->findOrFail($validated['crop_cycle_id']);
            $harvest = ! empty($validated['harvest_id'])
                ? Harvest::lockForUpdate()->findOrFail($validated['harvest_id'])
                : null;

            if ($harvest && $harvest->crop_cycle_id !== $cropCycle->id) {
                abort(422, 'Selected harvest does not belong to the selected crop cycle.');
            }
            if ($harvest && $this->normalizeUnit($harvest->unit) !== $this->normalizeUnit($validated['unit'])) {
                abort(422, 'Sell order unit must match the selected harvest unit.');
            }
            if ($harvest) {
                $reserved = (float) SellOrder::where('harvest_id', $harvest->id)
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->sum('quantity');
                $available = max(0, $harvest->getRemainingQuantity() - $reserved);
                if ((float) $validated['quantity'] > $available) {
                    abort(422, "Insufficient harvested stock. {$available} {$harvest->unit} is available.");
                }
            }

            $market = Market::findOrFail($validated['market_id']);
            $buyer = Buyer::active()->findOrFail($validated['buyer_id']);
            $data = array_merge($validated, [
                'created_by' => $request->user()->id,
                'crop_name' => $cropCycle->crop_name,
                'crop_variety' => $validated['crop_variety'] ?? $cropCycle->variety,
                'delivery_location' => $validated['delivery_location'] ?? $market->location,
                'delivery_latitude' => $validated['delivery_latitude'] ?? $market->latitude,
                'delivery_longitude' => $validated['delivery_longitude'] ?? $market->longitude,
                'status' => 'pending',
                'logistics_order_id' => 'DSP-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
                'market_price_at_order' => $this->latestMarketPrice($market->id, $cropCycle->crop_name),
                'is_repeat_customer' => $buyer->total_transactions > 0,
                'buyer_order_count' => SellOrder::where('buyer_id', $buyer->id)->count(),
            ]);

            $order = SellOrder::create($data);
            $order->estimateTransportCost();
            return $order->fresh();
        });

        event(new SellOrderCreated($order));
        event(new SellOrderDispatchRequested($order, $this->dispatchPayload($order)));

        return response()->json([
            'success' => true,
            'message' => 'Sell order created and logistics dispatch requested.',
            'data' => $this->loadOrder($order),
        ], 201);
    }

    public function show(SellOrder $sellOrder): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->loadOrder($sellOrder)]);
    }

    public function update(Request $request, SellOrder $sellOrder): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        if (! in_array($sellOrder->status, ['pending', 'buyer_confirmed'], true)) {
            return response()->json(['success' => false, 'message' => 'Only pending orders can be edited.'], 409);
        }

        $validated = $request->validate([
            'quantity' => 'sometimes|numeric|min:0.001',
            'asking_price_per_unit' => 'sometimes|numeric|min:0.01',
            'minimum_acceptable_price' => 'sometimes|numeric|min:0.01',
            'pickup_location' => 'sometimes|string|max:255',
            'preferred_pickup_time' => 'sometimes|date|after:now',
            'latest_pickup_time' => 'nullable|date|after:preferred_pickup_time',
            'special_instructions' => 'nullable|string|max:1000',
            'urgency_level' => ['sometimes', Rule::in(array_keys(SellOrder::URGENCY_LEVELS))],
        ]);
        $asking = (float) ($validated['asking_price_per_unit'] ?? $sellOrder->asking_price_per_unit);
        $minimum = (float) ($validated['minimum_acceptable_price'] ?? $sellOrder->minimum_acceptable_price);
        if ($minimum > $asking) {
            return response()->json(['success' => false, 'message' => 'Minimum acceptable price cannot exceed asking price.'], 422);
        }
        $sellOrder->update($validated);

        return response()->json(['success' => true, 'message' => 'Sell order updated.', 'data' => $this->loadOrder($sellOrder)]);
    }

    public function confirmBuyer(Request $request, SellOrder $sellOrder): JsonResponse
    {
        $request->validate(['buyer_id' => 'nullable|uuid|exists:buyers,id', 'notes' => 'nullable|string|max:500']);
        if ($request->filled('buyer_id')) {
            $sellOrder->buyer_id = $request->input('buyer_id');
            $sellOrder->save();
        }
        return $this->transition($request, $sellOrder, 'buyer_confirmed', $request->input('notes', 'Buyer confirmed.'));
    }

    public function agreePrice(Request $request, SellOrder $sellOrder): JsonResponse
    {
        $validated = $request->validate([
            'agreed_price_per_unit' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);
        if ((float) $validated['agreed_price_per_unit'] < (float) $sellOrder->minimum_acceptable_price) {
            return response()->json(['success' => false, 'message' => 'Agreed price is below the minimum acceptable price.'], 422);
        }
        $sellOrder->agreed_price_per_unit = $validated['agreed_price_per_unit'];
        $sellOrder->total_value = (float) $sellOrder->quantity * (float) $validated['agreed_price_per_unit'];
        $sellOrder->save();
        return $this->transition($request, $sellOrder, 'price_agreed', $validated['notes'] ?? 'Price agreed.');
    }

    public function assignLogistics(Request $request, SellOrder $sellOrder): JsonResponse
    {
        $validated = $request->validate([
            'rider_id' => 'required|string|max:100',
            'rider_name' => 'required|string|max:255',
            'rider_phone' => 'required|string|max:20',
            'vehicle_details' => 'required|string|max:255',
            'transport_cost' => 'nullable|numeric|min:0',
            'expected_delivery_time' => 'nullable|date|after:now',
            'notes' => 'nullable|string|max:500',
        ]);
        $sellOrder->fill($validated)->save();
        return $this->transition($request, $sellOrder, 'logistics_assigned', $validated['notes'] ?? 'Rider assigned.');
    }

    public function markPickedUp(Request $request, SellOrder $sellOrder): JsonResponse
    {
        return $this->transition($request, $sellOrder, 'picked_up', $request->input('notes', 'Produce picked up.'));
    }

    public function markInTransit(Request $request, SellOrder $sellOrder): JsonResponse
    {
        return $this->transition($request, $sellOrder, 'in_transit', $request->input('notes', 'Produce in transit.'));
    }

    public function markDelivered(Request $request, SellOrder $sellOrder): JsonResponse
    {
        return $this->transition($request, $sellOrder, 'delivered', $request->input('notes', 'Produce delivered.'));
    }

    public function markPaymentReceived(Request $request, SellOrder $sellOrder): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        if ($sellOrder->status !== 'delivered') {
            return $this->invalidTransition($sellOrder, 'payment_received');
        }
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => ['required', Rule::in(array_keys(SellOrder::PAYMENT_METHODS))],
            'payment_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);
        $newTotal = (float) $sellOrder->payment_received + (float) $validated['amount'];
        if ($newTotal > (float) $sellOrder->total_value) {
            return response()->json(['success' => false, 'message' => 'Payment exceeds the order balance.'], 422);
        }
        $sellOrder->update([
            'payment_received' => $newTotal,
            'payment_method' => $validated['payment_method'],
            'payment_reference' => $validated['payment_reference'] ?? null,
            'payment_received_at' => now(),
        ]);
        if ($newTotal < (float) $sellOrder->total_value) {
            return response()->json([
                'success' => true,
                'message' => 'Partial payment recorded.',
                'data' => $this->loadOrder($sellOrder),
                'balance' => (float) $sellOrder->total_value - $newTotal,
            ]);
        }
        return $this->transition($request, $sellOrder, 'payment_received', $validated['notes'] ?? 'Full payment received.');
    }

    public function complete(Request $request, SellOrder $sellOrder): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        if ($sellOrder->status !== 'payment_received') {
            return $this->invalidTransition($sellOrder, 'completed');
        }

        $order = DB::transaction(function () use ($sellOrder, $request) {
            $order = SellOrder::lockForUpdate()->findOrFail($sellOrder->id);
            if ($order->sale_id) {
                return $order;
            }
            $gross = (float) $order->total_value;
            $transport = (float) $order->transport_cost;
            $sale = Sale::create([
                'crop_cycle_id' => $order->crop_cycle_id,
                'harvest_id' => $order->harvest_id,
                'sale_date' => now()->toDateString(),
                'quantity_sold' => $order->quantity,
                'unit' => $order->unit,
                'price_per_unit' => $order->agreed_price_per_unit,
                'gross_income' => $gross,
                'total_deductions' => $transport,
                'net_income' => $gross - $transport,
                'buyer_id' => $order->buyer_id,
                'buyer_name' => $order->buyer?->name,
                'market_location' => $order->market?->location,
                'sale_type' => 'delivery',
                'payment_method' => $this->salePaymentMethod($order->payment_method),
                'payment_status' => 'paid',
                'amount_paid' => $gross - $transport,
                'amount_outstanding' => 0,
                'transport_cost' => $transport,
                'notes' => "Created from market sell order {$order->order_number}.",
                'status' => 'approved',
                'created_by' => $request->user()->id,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            $order->sale_id = $sale->id;
            $order->commission_amount = 0;
            $order->net_amount = $gross - $transport;
            $order->order_fulfilled_at = now();
            $order->fulfillment_time_hours = (int) floor($order->created_at->floatDiffInHours(now()));
            $this->applyTransition($order, 'completed', 'Order completed and sale recorded.', $request->user()->id);
            $this->refreshBuyer($order->buyer_id);
            return $order;
        });

        return response()->json(['success' => true, 'message' => 'Order completed and sale recorded.', 'data' => $this->loadOrder($order)]);
    }

    public function cancel(Request $request, SellOrder $sellOrder): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        if (! in_array($sellOrder->status, self::ACTIVE_STATUSES, true)) {
            return response()->json(['success' => false, 'message' => 'This order can no longer be cancelled.'], 409);
        }
        $validated = $request->validate(['reason' => 'required|string|max:255', 'notes' => 'nullable|string|max:1000']);
        $oldStatus = $sellOrder->status;
        $sellOrder->fill([
            'status' => 'cancelled',
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $validated['reason'],
            'cancellation_notes' => $validated['notes'] ?? null,
        ]);
        $this->appendHistory($sellOrder, 'cancelled', $validated['reason'], $request->user()->id);
        $sellOrder->save();
        $this->refreshBuyer($sellOrder->buyer_id);
        event(new SellOrderStatusChanged($sellOrder, $oldStatus, ['reason' => $validated['reason']]));

        return response()->json(['success' => true, 'message' => 'Sell order cancelled.', 'data' => $this->loadOrder($sellOrder)]);
    }

    public function getBuyerRecommendations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop' => 'required|string|max:100',
            'quantity' => 'required|numeric|min:0.001',
            'grade' => 'nullable|string|max:50',
        ]);
        $buyers = Buyer::active()->orderByDesc('reliability_score')->limit(20)->get()->map(function (Buyer $buyer) use ($validated) {
            $latestOffer = $buyer->offers()->where('crop_name', 'ilike', $validated['crop'])
                ->whereIn('status', ['active', 'partially_filled'])->where('offer_valid_until', '>=', now()->toDateString())
                ->orderByDesc('offered_price_per_unit')->first();
            $price = (float) ($latestOffer?->offered_price_per_unit ?? 0);
            return [
                'buyer' => $buyer,
                'offered_price_per_unit' => $price ?: null,
                'available_quantity' => $latestOffer ? max(0, (float) $latestOffer->quantity_needed - (float) $latestOffer->quantity_committed) : null,
                'score' => round(((float) $buyer->reliability_score * 20) + min(20, $price / 10), 2),
            ];
        })->sortByDesc('score')->values();
        $markets = MarketPrice::with('market:id,name,location,county,distance_from_farm')
            ->where('crop_name', 'ilike', $validated['crop'])->where('price_date', '>=', now()->subDays(14))
            ->orderByDesc('average_price')->get()->unique('market_id')->values()->map(fn (MarketPrice $price) => [
                'market' => $price->market,
                'price_per_unit' => (float) $price->average_price,
                'demand_level' => $price->demand_level,
                'net_price_after_transport' => round((float) $price->average_price - (float) $price->transport_cost_per_kg, 2),
            ])->sortByDesc('net_price_after_transport')->values();

        return response()->json(['success' => true, 'data' => ['buyers' => $buyers, 'markets' => $markets]]);
    }

    public function estimateTransportCost(float $pickupLat, float $pickupLng, float $deliveryLat, float $deliveryLng): JsonResponse
    {
        foreach ([$pickupLat, $deliveryLat] as $latitude) {
            abort_unless($latitude >= -90 && $latitude <= 90, 422, 'Invalid latitude.');
        }
        foreach ([$pickupLng, $deliveryLng] as $longitude) {
            abort_unless($longitude >= -180 && $longitude <= 180, 422, 'Invalid longitude.');
        }
        $distance = $this->distance($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        return response()->json(['success' => true, 'data' => [
            'distance_km' => $distance,
            'estimated_cost' => round(max(150, $distance * 25), 2),
            'currency' => 'KES',
        ]]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int) $request->input('days', 30)));
        $orders = SellOrder::where('created_at', '>=', now()->subDays($days))->get();
        return response()->json(['success' => true, 'data' => [
            'summary' => $this->summary(),
            'revenue' => round((float) $orders->where('status', 'completed')->sum('total_value'), 2),
            'average_order_value' => round((float) $orders->avg('total_value'), 2),
            'completion_rate' => $orders->isEmpty() ? 0 : round($orders->where('status', 'completed')->count() / $orders->count() * 100, 1),
            'by_crop' => $orders->groupBy('crop_name')->map(fn ($items, $crop) => [
                'crop' => $crop, 'orders' => $items->count(), 'value' => round((float) $items->sum('total_value'), 2),
            ])->values(),
        ]]);
    }

    public function destroy(Request $request, SellOrder $sellOrder): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        if (! in_array($sellOrder->status, ['cancelled', 'expired'], true)) {
            return response()->json(['success' => false, 'message' => 'Only cancelled or expired orders can be deleted.'], 409);
        }
        $sellOrder->delete();
        return response()->json(['success' => true, 'message' => 'Sell order deleted.']);
    }

    private function transition(Request $request, SellOrder $order, string $status, string $notes): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->managementRequired();
        }
        if (! in_array($status, self::TRANSITIONS[$order->status] ?? [], true)) {
            return $this->invalidTransition($order, $status);
        }
        $oldStatus = $order->status;
        $this->applyTransition($order, $status, $notes, $request->user()->id);
        event(new SellOrderStatusChanged($order, $oldStatus, ['notes' => $notes]));
        return response()->json(['success' => true, 'message' => 'Order status updated.', 'data' => $this->loadOrder($order)]);
    }

    private function applyTransition(SellOrder $order, string $status, string $notes, string $userId): void
    {
        $order->status = $status;
        $this->appendHistory($order, $status, $notes, $userId);
        $order->save();
    }

    private function appendHistory(SellOrder $order, string $status, string $notes, string $userId): void
    {
        $history = $order->status_history ?? [];
        $history[] = ['status' => $status, 'timestamp' => now()->toISOString(), 'notes' => $notes, 'user_id' => $userId];
        $order->status_history = $history;
    }

    private function invalidTransition(SellOrder $order, string $target): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "Cannot move order from {$order->status} to {$target}.",
            'allowed_transitions' => self::TRANSITIONS[$order->status] ?? [],
        ], 409);
    }

    private function loadOrder(SellOrder $order): SellOrder
    {
        return $order->fresh()->load(['cropCycle:id,crop_name,variety', 'harvest:id,crop_cycle_id,total_quantity,unit', 'buyer', 'market:id,name,location,county,latitude,longitude', 'createdBy:id,name']);
    }

    private function summary(): array
    {
        return [
            'total' => SellOrder::count(),
            'active' => SellOrder::whereIn('status', self::ACTIVE_STATUSES)->count(),
            'pending' => SellOrder::where('status', 'pending')->count(),
            'completed' => SellOrder::where('status', 'completed')->count(),
            'cancelled' => SellOrder::where('status', 'cancelled')->count(),
        ];
    }

    private function refreshBuyer(?string $buyerId): void
    {
        if (! $buyerId || ! ($buyer = Buyer::find($buyerId))) {
            return;
        }
        $completed = SellOrder::where('buyer_id', $buyerId)->where('status', 'completed')->get();
        $cancelled = SellOrder::where('buyer_id', $buyerId)->where('status', 'cancelled')->count();
        $total = $completed->count() + $cancelled;
        $reliability = $total === 0 ? 5 : max(1, 5 - ($cancelled / $total * 3));
        $buyer->update([
            'total_transactions' => $completed->count(),
            'total_purchases' => $completed->sum('total_value'),
            'average_purchase_value' => $completed->avg('total_value') ?? 0,
            'last_purchase_date' => $completed->max('order_fulfilled_at'),
            'reliability_score' => round($reliability, 2),
        ]);
    }

    private function latestMarketPrice(string $marketId, string $crop): ?float
    {
        $price = MarketPrice::where('market_id', $marketId)->where('crop_name', 'ilike', $crop)
            ->latest('price_date')->value('average_price');
        return $price === null ? null : (float) $price;
    }

    private function dispatchPayload(SellOrder $order): array
    {
        return [
            'tenant_id' => request()->header('X-Tenant-ID'),
            'dispatch_id' => $order->logistics_order_id,
            'order_id' => $order->id,
            'pickup_location' => $order->pickup_location,
            'delivery_location' => $order->delivery_location,
            'preferred_pickup_time' => $order->preferred_pickup_time?->toISOString(),
            'cargo' => ['crop' => $order->crop_name, 'quantity' => (float) $order->quantity, 'unit' => $order->unit, 'grade' => $order->grade],
        ];
    }

    private function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    private function normalizeUnit(string $unit): string
    {
        return strtolower(rtrim(trim($unit), 's'));
    }

    private function salePaymentMethod(?string $method): string
    {
        return match ($method) {
            'mpesa' => 'm_pesa',
            'check' => 'cheque',
            default => $method ?: 'cash',
        };
    }

    private function canManage(Request $request): bool
    {
        $farmId = $request->header('X-Tenant-ID');
        return in_array($farmId ? $request->user()?->getRoleOnFarm($farmId) : null, ['owner', 'manager'], true);
    }

    private function managementRequired(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Only farm owners and managers can manage sell orders.'], 403);
    }
}
