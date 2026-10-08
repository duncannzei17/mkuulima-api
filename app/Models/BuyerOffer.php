<?php

namespace App\Models;

use App\Traits\HasUuids;
use App\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class BuyerOffer extends Model
{
    use HasFactory, HasUuids, TenantScope, SoftDeletes;

    protected $fillable = [
        'offer_number',
        'buyer_id',
        'farm_id',
        'crop_name',
        'crop_variety',
        'quantity_needed',
        'unit',
        'offered_price_per_unit',
        'total_offer_value',
        'preferred_grade',
        'quality_requirements',
        'needed_by_date',
        'offer_valid_until',
        'flexible_date',
        'delivery_terms',
        'preferred_location',
        'location_latitude',
        'location_longitude',
        'payment_method',
        'payment_timing',
        'advance_payment_percentage',
        'price_negotiable',
        'status',
        'farmer_responses',
        'quantity_committed',
        'quantity_received',
        'interested_farmers',
        'accepted_by',
        'accepted_at',
        'market_price_at_offer',
        'price_premium_percentage',
        'urgency_level',
        'is_bulk_order',
        'is_recurring_offer',
        'special_requirements',
        'buyer_notes',
        'contact_person',
        'contact_phone',
        'contact_email',
        'offer_fulfilled_at',
        'fulfillment_time_hours',
        'farmer_rating',
        'farmer_feedback',
        'will_reorder',
        'matching_criteria',
        'match_score',
        'auto_match_enabled',
        'suggested_farmers',
    ];

    protected $casts = [
        'quantity_needed' => 'decimal:2',
        'offered_price_per_unit' => 'decimal:2',
        'total_offer_value' => 'decimal:2',
        'needed_by_date' => 'date',
        'offer_valid_until' => 'date',
        'flexible_date' => 'boolean',
        'location_latitude' => 'decimal:8',
        'location_longitude' => 'decimal:8',
        'advance_payment_percentage' => 'decimal:2',
        'price_negotiable' => 'boolean',
        'farmer_responses' => 'integer',
        'quantity_committed' => 'decimal:2',
        'quantity_received' => 'decimal:2',
        'interested_farmers' => 'array',
        'accepted_at' => 'datetime',
        'market_price_at_offer' => 'decimal:2',
        'price_premium_percentage' => 'decimal:2',
        'is_bulk_order' => 'boolean',
        'is_recurring_offer' => 'boolean',
        'offer_fulfilled_at' => 'datetime',
        'fulfillment_time_hours' => 'integer',
        'farmer_rating' => 'decimal:2',
        'will_reorder' => 'boolean',
        'matching_criteria' => 'array',
        'match_score' => 'decimal:2',
        'auto_match_enabled' => 'boolean',
        'suggested_farmers' => 'array',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'farmer_interested' => 'Farmer Interested',
        'negotiating' => 'Negotiating',
        'accepted' => 'Accepted',
        'partially_filled' => 'Partially Filled',
        'fulfilled' => 'Fulfilled',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
    ];

    public const URGENCY_LEVELS = [
        'low' => 'Low Priority',
        'normal' => 'Normal Priority',
        'high' => 'High Priority',
        'urgent' => 'Urgent',
    ];

    public const PAYMENT_TIMINGS = [
        'on_delivery' => 'On Delivery',
        'within_24h' => 'Within 24 Hours',
        'within_week' => 'Within 1 Week',
        'net_30' => 'Net 30 Days',
    ];

    public const DELIVERY_TERMS = [
        'pickup' => 'Buyer Pickup',
        'delivery' => 'Farmer Delivery',
        'market' => 'Market Delivery',
        'logistics' => 'Third-party Logistics',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($offer) {
            $offer->offer_number = $offer->generateOfferNumber();
            $offer->total_offer_value = $offer->quantity_needed * $offer->offered_price_per_unit;
        });

        static::created(function ($offer) {
            $offer->calculatePricePremium();
            $offer->generateMatchingSuggestions();
        });
    }

    private function generateOfferNumber(): string
    {
        $prefix = 'BO';
        $date = now()->format('ymd');
        $random = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        
        return "{$prefix}{$date}{$random}";
    }

    public function calculatePricePremium(): void
    {
        $marketPrice = MarketPrice::where('crop_name', $this->crop_name)
            ->where('price_date', '>=', now()->subDays(3))
            ->latest('price_date')
            ->value('average_price');

        if ($marketPrice && $marketPrice > 0) {
            $this->market_price_at_offer = $marketPrice;
            $premium = (($this->offered_price_per_unit - $marketPrice) / $marketPrice) * 100;
            $this->price_premium_percentage = round($premium, 2);
            $this->save();
        }
    }

    public function generateMatchingSuggestions(): void
    {
        // AI-powered farmer matching based on criteria
        $matchingCriteria = [
            'crop_name' => $this->crop_name,
            'quantity_available' => $this->quantity_needed,
            'location_radius' => 50, // km
            'quality_grade' => $this->preferred_grade,
            'urgency' => $this->urgency_level,
        ];

        $suggestedFarmers = $this->findMatchingFarmers($matchingCriteria);

        $this->matching_criteria = $matchingCriteria;
        $this->suggested_farmers = $suggestedFarmers;
        $this->save();
    }

    private function findMatchingFarmers(array $criteria): array
    {
        // Find farmers with available harvest matching criteria
        $availableHarvests = Harvest::where('crop_name', $criteria['crop_name'])
            ->where('status', 'approved')
            ->where('harvest_date', '>=', now()->subDays(7))
            ->where('quantity_harvested', '>=', $criteria['quantity_available'] * 0.5) // At least 50% of needed quantity
            ->with(['cropCycle.farm', 'createdBy'])
            ->get();

        $suggestions = [];

        foreach ($availableHarvests as $harvest) {
            $farm = $harvest->cropCycle->farm;
            $farmer = $harvest->createdBy;

            // Calculate distance if location data available
            $distance = null;
            if ($this->location_latitude && $this->location_longitude && 
                $farm->latitude && $farm->longitude) {
                $distance = $this->calculateDistance(
                    $farm->latitude, 
                    $farm->longitude,
                    $this->location_latitude,
                    $this->location_longitude
                );
            }

            // Skip if too far
            if ($distance && $distance > $criteria['location_radius']) {
                continue;
            }

            // Calculate match score
            $matchScore = $this->calculateMatchScore($harvest, $criteria);

            $suggestions[] = [
                'farmer_id' => $farmer->id,
                'farmer_name' => $farmer->name,
                'farm_name' => $farm->name,
                'harvest_id' => $harvest->id,
                'available_quantity' => $harvest->quantity_harvested,
                'grade' => $harvest->grade,
                'harvest_date' => $harvest->harvest_date,
                'distance_km' => $distance,
                'match_score' => $matchScore,
                'farmer_phone' => $farmer->phone,
            ];
        }

        // Sort by match score and return top 10
        return collect($suggestions)
            ->sortByDesc('match_score')
            ->take(10)
            ->values()
            ->toArray();
    }

    private function calculateMatchScore($harvest, $criteria): float
    {
        $score = 0;
        $maxScore = 100;

        // Quantity match (30%)
        $quantityRatio = min(1, $harvest->quantity_harvested / $criteria['quantity_available']);
        $score += $quantityRatio * 30;

        // Quality grade match (25%)
        if ($harvest->grade === $criteria['quality_grade']) {
            $score += 25;
        } elseif (in_array($harvest->grade, ['A', 'B']) && $criteria['quality_grade'] === 'mixed') {
            $score += 15;
        }

        // Freshness (25%)
        $daysOld = now()->diffInDays($harvest->harvest_date);
        if ($daysOld <= 1) {
            $score += 25;
        } elseif ($daysOld <= 3) {
            $score += 20;
        } elseif ($daysOld <= 7) {
            $score += 10;
        }

        // Farmer reliability (20%)
        $farmer = $harvest->createdBy;
        $farmerScore = $this->getFarmerReliabilityScore($farmer);
        $score += ($farmerScore / 5) * 20;

        return round(min($maxScore, $score), 2);
    }

    private function getFarmerReliabilityScore($farmer): float
    {
        // Calculate farmer reliability based on past performance
        $completedOrders = SellOrder::where('created_by', $farmer->id)
            ->where('status', 'completed')
            ->count();

        $totalOrders = SellOrder::where('created_by', $farmer->id)
            ->whereIn('status', ['completed', 'cancelled'])
            ->count();

        if ($totalOrders === 0) {
            return 3.5; // Default score for new farmers
        }

        $completionRate = $completedOrders / $totalOrders;
        
        // Convert completion rate to 5-star scale
        return round($completionRate * 5, 2);
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371; // km

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDiff = $latTo - $latFrom;
        $lonDiff = $lonTo - $lonFrom;

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos($latFrom) * cos($latTo) * 
             sin($lonDiff / 2) * sin($lonDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    public function addInterestedFarmer(User $farmer): bool
    {
        $interestedFarmers = $this->interested_farmers ?? [];
        
        if (!in_array($farmer->id, $interestedFarmers)) {
            $interestedFarmers[] = $farmer->id;
            $this->interested_farmers = $interestedFarmers;
            $this->farmer_responses += 1;
            
            if ($this->status === 'active') {
                $this->status = 'farmer_interested';
            }
            
            $this->save();
            
            // Notify buyer of farmer interest
            event(new \App\Events\FarmerRespondedToOffer($this, $farmer));
            
            return true;
        }
        
        return false;
    }

    public function acceptFarmerOffer(User $farmer, float $quantity): bool
    {
        if ($this->status !== 'farmer_interested' && $this->status !== 'negotiating') {
            return false;
        }

        $this->accepted_by = $farmer->id;
        $this->accepted_at = now();
        $this->quantity_committed += $quantity;
        
        if ($this->quantity_committed >= $this->quantity_needed) {
            $this->status = 'accepted';
        } else {
            $this->status = 'partially_filled';
        }
        
        $this->save();
        
        // Create corresponding sell order
        $this->createSellOrder($farmer, $quantity);
        
        return true;
    }

    private function createSellOrder(User $farmer, float $quantity): SellOrder
    {
        return SellOrder::create([
            'crop_cycle_id' => null, // Will be linked when farmer specifies
            'buyer_id' => $this->buyer_id,
            'created_by' => $farmer->id,
            'crop_name' => $this->crop_name,
            'crop_variety' => $this->crop_variety,
            'quantity' => $quantity,
            'unit' => $this->unit,
            'grade' => $this->preferred_grade,
            'asking_price_per_unit' => $this->offered_price_per_unit,
            'minimum_acceptable_price' => $this->offered_price_per_unit,
            'agreed_price_per_unit' => $this->offered_price_per_unit,
            'pickup_location' => $farmer->farm->address ?? 'Farm location',
            'delivery_location' => $this->preferred_location,
            'preferred_pickup_time' => $this->needed_by_date->startOfDay(),
            'payment_method' => $this->payment_method,
            'special_instructions' => $this->special_requirements,
            'status' => 'price_agreed',
            'urgency_level' => $this->urgency_level,
        ]);
    }

    public function markFulfilled(): bool
    {
        $this->status = 'fulfilled';
        $this->offer_fulfilled_at = now();
        $this->fulfillment_time_hours = $this->created_at->diffInHours(now());
        $this->save();
        
        return true;
    }

    public function expire(): bool
    {
        if (now()->gt($this->offer_valid_until)) {
            $this->status = 'expired';
            $this->save();
            return true;
        }
        
        return false;
    }

    public function cancel(): bool
    {
        $this->status = 'cancelled';
        $this->save();
        
        // Notify interested farmers
        event(new \App\Events\BuyerOfferCancelled($this));
        
        return true;
    }

    public function rateFarmer(float $rating, string $feedback = null): bool
    {
        $this->farmer_rating = $rating;
        $this->farmer_feedback = $feedback;
        $this->will_reorder = $rating >= 4.0;
        $this->save();
        
        return true;
    }

    public function getMatchingScore(User $farmer): float
    {
        // Calculate how well this farmer matches the offer
        $farmerHarvests = Harvest::whereHas('cropCycle', function ($query) use ($farmer) {
                $query->where('created_by', $farmer->id);
            })
            ->where('crop_name', $this->crop_name)
            ->where('harvest_date', '>=', now()->subDays(30))
            ->get();

        if ($farmerHarvests->isEmpty()) {
            return 0;
        }

        $totalQuantity = $farmerHarvests->sum('quantity_harvested');
        $averageGrade = $farmerHarvests->avg('grade_numeric');
        $reliability = $this->getFarmerReliabilityScore($farmer);

        $score = 0;
        
        // Quantity match
        if ($totalQuantity >= $this->quantity_needed) {
            $score += 40;
        } else {
            $score += ($totalQuantity / $this->quantity_needed) * 40;
        }

        // Grade match
        $score += min(30, ($averageGrade / 5) * 30);

        // Reliability
        $score += ($reliability / 5) * 30;

        return round($score, 2);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('offer_valid_until', '>=', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('offer_valid_until', '<', now())
            ->where('status', '!=', 'fulfilled');
    }

    public function scopeByCrop($query, string $crop)
    {
        return $query->where('crop_name', $crop);
    }

    public function scopeUrgent($query)
    {
        return $query->where('urgency_level', 'urgent');
    }

    public function scopeBulkOrders($query)
    {
        return $query->where('is_bulk_order', true);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring_offer', true);
    }

    public function getStatusDisplayAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getUrgencyDisplayAttribute(): string
    {
        return self::URGENCY_LEVELS[$this->urgency_level] ?? $this->urgency_level;
    }

    public function getPaymentTimingDisplayAttribute(): string
    {
        return self::PAYMENT_TIMINGS[$this->payment_timing] ?? $this->payment_timing;
    }

    public function getDeliveryTermsDisplayAttribute(): string
    {
        return self::DELIVERY_TERMS[$this->delivery_terms] ?? $this->delivery_terms;
    }

    public function getIsExpiredAttribute(): bool
    {
        return now()->gt($this->offer_valid_until);
    }

    public function getFulfillmentPercentageAttribute(): float
    {
        if ($this->quantity_needed == 0) return 0;
        return round(($this->quantity_committed / $this->quantity_needed) * 100, 2);
    }
}