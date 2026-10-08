<?php

namespace App\Models;

use App\Traits\HasUuids;
use App\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class SellOrder extends Model
{
    use HasFactory, HasUuids, TenantScope, SoftDeletes;

    protected $fillable = [
        'order_number',
        'crop_cycle_id',
        'harvest_id',
        'sale_id',
        'buyer_id',
        'market_id',
        'created_by',
        'crop_name',
        'crop_variety',
        'quantity',
        'unit',
        'grade',
        'quality_description',
        'asking_price_per_unit',
        'minimum_acceptable_price',
        'agreed_price_per_unit',
        'total_value',
        'is_negotiable',
        'pickup_location',
        'pickup_latitude',
        'pickup_longitude',
        'delivery_location',
        'delivery_latitude',
        'delivery_longitude',
        'preferred_pickup_time',
        'latest_pickup_time',
        'expected_delivery_time',
        'flexible_timing',
        'status',
        'logistics_provider',
        'logistics_order_id',
        'rider_id',
        'rider_name',
        'rider_phone',
        'vehicle_details',
        'transport_cost',
        'transport_distance',
        'payment_method',
        'payment_reference',
        'payment_received',
        'payment_received_at',
        'commission_amount',
        'net_amount',
        'special_instructions',
        'status_history',
        'order_fulfilled_at',
        'fulfillment_time_hours',
        'customer_rating',
        'customer_feedback',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_notes',
        'has_issues',
        'issue_description',
        'market_price_at_order',
        'price_premium_percentage',
        'is_repeat_customer',
        'buyer_order_count',
        'urgency_level',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'asking_price_per_unit' => 'decimal:2',
        'minimum_acceptable_price' => 'decimal:2',
        'agreed_price_per_unit' => 'decimal:2',
        'total_value' => 'decimal:2',
        'is_negotiable' => 'boolean',
        'pickup_latitude' => 'decimal:8',
        'pickup_longitude' => 'decimal:8',
        'delivery_latitude' => 'decimal:8',
        'delivery_longitude' => 'decimal:8',
        'preferred_pickup_time' => 'datetime',
        'latest_pickup_time' => 'datetime',
        'expected_delivery_time' => 'datetime',
        'flexible_timing' => 'boolean',
        'transport_cost' => 'decimal:2',
        'transport_distance' => 'decimal:2',
        'payment_received' => 'decimal:2',
        'payment_received_at' => 'datetime',
        'commission_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'status_history' => 'array',
        'order_fulfilled_at' => 'datetime',
        'fulfillment_time_hours' => 'integer',
        'customer_rating' => 'decimal:2',
        'cancelled_at' => 'datetime',
        'has_issues' => 'boolean',
        'market_price_at_order' => 'decimal:2',
        'price_premium_percentage' => 'decimal:2',
        'is_repeat_customer' => 'boolean',
        'buyer_order_count' => 'integer',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'buyer_confirmed' => 'Buyer Confirmed',
        'price_agreed' => 'Price Agreed',
        'logistics_assigned' => 'Logistics Assigned',
        'picked_up' => 'Picked Up',
        'in_transit' => 'In Transit',
        'delivered' => 'Delivered',
        'payment_received' => 'Payment Received',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ];

    public const URGENCY_LEVELS = [
        'low' => 'Low Priority',
        'normal' => 'Normal Priority',
        'high' => 'High Priority',
        'urgent' => 'Urgent',
    ];

    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'mpesa' => 'M-Pesa',
        'bank_transfer' => 'Bank Transfer',
        'check' => 'Check',
        'mobile_money' => 'Mobile Money',
    ];

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            $order->order_number = $order->generateOrderNumber();
            $order->status_history = [];
        });

        static::created(function ($order) {
            $order->updateStatusHistory('created', 'Order created');
            $order->calculatePricePremium();
        });
    }

    private function generateOrderNumber(): string
    {
        $prefix = 'SO';
        $date = now()->format('ymd');
        $random = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        
        return "{$prefix}{$date}{$random}";
    }

    public function updateStatus(string $newStatus, string $notes = null): bool
    {
        $oldStatus = $this->status;
        $this->status = $newStatus;
        
        $this->updateStatusHistory($newStatus, $notes);
        
        // Handle status-specific logic
        switch ($newStatus) {
            case 'price_agreed':
                $this->calculateTotalValue();
                break;
                
            case 'logistics_assigned':
                $this->dispatchToLogistics();
                break;
                
            case 'picked_up':
                $this->updateInventory();
                break;
                
            case 'delivered':
                $this->expected_delivery_time = now();
                break;
                
            case 'completed':
                $this->completeOrder();
                break;
                
            case 'cancelled':
                $this->handleCancellation();
                break;
        }
        
        $this->save();
        
        // Fire status update event
        event(new \App\Events\SellOrderStatusUpdated($this, $oldStatus, $newStatus));
        
        return true;
    }

    private function updateStatusHistory(string $status, string $notes = null): void
    {
        $history = $this->status_history ?? [];
        
        $history[] = [
            'status' => $status,
            'timestamp' => now()->toISOString(),
            'notes' => $notes,
            'user_id' => auth()->id(),
        ];
        
        $this->status_history = $history;
    }

    public function calculateTotalValue(): void
    {
        if ($this->agreed_price_per_unit) {
            $this->total_value = $this->quantity * $this->agreed_price_per_unit;
            $this->save();
        }
    }

    public function calculatePricePremium(): void
    {
        $marketPrice = MarketPrice::where('crop_name', $this->crop_name)
            ->where('price_date', '>=', now()->subDays(3))
            ->latest('price_date')
            ->value('average_price');

        if ($marketPrice && $marketPrice > 0) {
            $this->market_price_at_order = $marketPrice;
            $askingPrice = $this->asking_price_per_unit;
            
            if ($askingPrice > 0) {
                $premium = (($askingPrice - $marketPrice) / $marketPrice) * 100;
                $this->price_premium_percentage = round($premium, 2);
                $this->save();
            }
        }
    }

    public function dispatchToLogistics(): array
    {
        // Integration with Siku Mpya logistics system
        $logisticsPayload = [
            'order_id' => $this->id,
            'order_number' => $this->order_number,
            'pickup' => [
                'location' => $this->pickup_location,
                'latitude' => $this->pickup_latitude,
                'longitude' => $this->pickup_longitude,
                'preferred_time' => $this->preferred_pickup_time,
                'latest_time' => $this->latest_pickup_time,
                'contact_person' => $this->createdBy->name,
                'contact_phone' => $this->createdBy->phone,
            ],
            'delivery' => [
                'location' => $this->delivery_location ?? $this->market?->location,
                'latitude' => $this->delivery_latitude,
                'longitude' => $this->delivery_longitude,
                'expected_time' => $this->expected_delivery_time,
            ],
            'cargo' => [
                'type' => 'agricultural_produce',
                'description' => "{$this->quantity} {$this->unit} of {$this->crop_name}",
                'weight_kg' => $this->quantity,
                'special_handling' => $this->getSpecialHandlingRequirements(),
            ],
            'payment' => [
                'transport_cost' => $this->transport_cost,
                'payment_method' => $this->payment_method,
                'who_pays' => 'farmer', // or 'buyer' based on terms
            ],
            'urgency' => $this->urgency_level,
            'special_instructions' => $this->special_instructions,
        ];

        // Fire Kafka event for Siku Mpya
        event(new \App\Events\SellOrderDispatchRequested($this, $logisticsPayload));

        return $logisticsPayload;
    }

    private function getSpecialHandlingRequirements(): array
    {
        $requirements = [];

        // Temperature sensitive crops
        if (in_array(strtolower($this->crop_name), ['lettuce', 'spinach', 'herbs'])) {
            $requirements[] = 'temperature_controlled';
        }

        // Fragile crops
        if (in_array(strtolower($this->crop_name), ['tomatoes', 'berries', 'grapes'])) {
            $requirements[] = 'handle_with_care';
        }

        // High value crops
        if ($this->total_value > 50000) { // KES 50,000
            $requirements[] = 'high_value_cargo';
        }

        return $requirements;
    }

    public function updateInventory(): void
    {
        // Deduct from inventory when picked up
        if ($this->harvest_id) {
            $harvest = $this->harvest;
            if ($harvest) {
                // Update harvest allocation
                $harvest->allocateToSale($this->quantity, $this->id);
            }
        }
    }

    public function completeOrder(): void
    {
        $this->order_fulfilled_at = now();
        $this->fulfillment_time_hours = $this->created_at->diffInHours(now());
        
        // Calculate net amount after commission
        $commission = $this->calculateCommission();
        $this->commission_amount = $commission;
        $this->net_amount = $this->total_value - $commission;
        
        // Update buyer statistics
        if ($this->buyer) {
            $this->buyer->updateOrderStatistics();
        }
        
        // Create sale record
        $this->createSaleRecord();
        
        $this->save();
    }

    private function calculateCommission(): float
    {
        // 2% commission on total value
        $commissionRate = 0.02;
        return $this->total_value * $commissionRate;
    }

    private function createSaleRecord(): void
    {
        // Create corresponding sale record in Module 7
        Sale::create([
            'harvest_id' => $this->harvest_id,
            'buyer_id' => $this->buyer_id,
            'crop_name' => $this->crop_name,
            'quantity' => $this->quantity,
            'unit_price' => $this->agreed_price_per_unit,
            'total_amount' => $this->total_value,
            'sale_date' => now()->toDateString(),
            'payment_method' => $this->payment_method,
            'delivery_location' => $this->delivery_location,
            'order_reference' => $this->order_number,
            'status' => 'completed',
            'created_by' => $this->created_by,
        ]);
    }

    public function handleCancellation(): void
    {
        $this->cancelled_at = now();
        
        // If logistics was assigned, cancel the dispatch
        if ($this->logistics_order_id) {
            event(new \App\Events\SellOrderCancelled($this));
        }
        
        // Release inventory allocation
        if ($this->harvest_id) {
            $harvest = $this->harvest;
            if ($harvest) {
                $harvest->releaseAllocation($this->quantity, $this->id);
            }
        }
    }

    public function cancel(User $user, string $reason, string $notes = null): bool
    {
        $this->cancelled_by = $user->id;
        $this->cancellation_reason = $reason;
        $this->cancellation_notes = $notes;
        
        return $this->updateStatus('cancelled', "Cancelled by {$user->name}: {$reason}");
    }

    public function calculateDistance(): float
    {
        if (!$this->pickup_latitude || !$this->pickup_longitude || 
            !$this->delivery_latitude || !$this->delivery_longitude) {
            return 0;
        }

        // Use same distance calculation as Market model
        $earthRadius = 6371; // km

        $latFrom = deg2rad($this->pickup_latitude);
        $lonFrom = deg2rad($this->pickup_longitude);
        $latTo = deg2rad($this->delivery_latitude);
        $lonTo = deg2rad($this->delivery_longitude);

        $latDiff = $latTo - $latFrom;
        $lonDiff = $lonTo - $lonFrom;

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos($latFrom) * cos($latTo) * 
             sin($lonDiff / 2) * sin($lonDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = round($earthRadius * $c, 2);
        
        $this->transport_distance = $distance;
        $this->save();

        return $distance;
    }

    public function estimateTransportCost(): float
    {
        $distance = $this->transport_distance ?? $this->calculateDistance();
        
        if ($distance == 0) {
            return 0;
        }

        // Base rate per km (can be configurable)
        $baseRatePerKm = 25; // KES per km
        
        // Weight factor
        $weightMultiplier = max(1, $this->quantity / 50); // Every 50kg adds complexity
        
        // Urgency multiplier
        $urgencyMultipliers = [
            'low' => 1.0,
            'normal' => 1.0,
            'high' => 1.3,
            'urgent' => 1.5,
        ];
        
        $urgencyMultiplier = $urgencyMultipliers[$this->urgency_level] ?? 1.0;
        
        $cost = $distance * $baseRatePerKm * $weightMultiplier * $urgencyMultiplier;
        
        $this->transport_cost = round($cost, 2);
        $this->save();
        
        return $this->transport_cost;
    }

    public function recordPayment(float $amount, string $method, string $reference = null): bool
    {
        $this->payment_received += $amount;
        $this->payment_method = $method;
        $this->payment_reference = $reference;
        $this->payment_received_at = now();
        
        if ($this->payment_received >= $this->total_value) {
            $this->updateStatus('payment_received', 'Full payment received');
            
            // Auto-complete if delivered and paid
            if ($this->status === 'delivered') {
                $this->updateStatus('completed', 'Order completed - delivered and paid');
            }
        }
        
        $this->save();
        return true;
    }

    public function rateTransaction(float $rating, string $feedback = null): bool
    {
        $this->customer_rating = $rating;
        $this->customer_feedback = $feedback;
        $this->save();
        
        // Update buyer reliability score
        if ($this->buyer) {
            $this->buyer->updateReliabilityScore();
        }
        
        return true;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['cancelled', 'completed', 'expired']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByCrop($query, string $crop)
    {
        return $query->where('crop_name', $crop);
    }

    public function scopeByBuyer($query, string $buyerId)
    {
        return $query->where('buyer_id', $buyerId);
    }

    public function scopeUrgent($query)
    {
        return $query->where('urgency_level', 'urgent');
    }

    public function scopeOverdue($query)
    {
        return $query->where('preferred_pickup_time', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled', 'expired']);
    }

    public function getStatusDisplayAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getUrgencyDisplayAttribute(): string
    {
        return self::URGENCY_LEVELS[$this->urgency_level] ?? $this->urgency_level;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->preferred_pickup_time < now() && 
               !in_array($this->status, ['completed', 'cancelled', 'expired']);
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->payment_received >= $this->total_value) {
            return 'fully_paid';
        } elseif ($this->payment_received > 0) {
            return 'partially_paid';
        } else {
            return 'unpaid';
        }
    }
}
