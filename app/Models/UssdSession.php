<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Traits\HasUuids;

class UssdSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_id', 'user_id', 'session_id', 'phone_number', 'network_code',
        'status', 'current_menu', 'current_step', 'current_flow', 'language',
        'menu_history', 'temp_data', 'context_data', 'last_input', 'last_output',
        'is_authenticated', 'pin_hash', 'failed_attempts', 'locked_until',
        'total_requests', 'started_at', 'last_activity_at', 'expires_at',
        'duration_seconds', 'error_count', 'last_error', 'last_error_at',
        'completed_successfully', 'completion_reason', 'completion_data'
    ];

    protected $casts = [
        'menu_history' => 'array',
        'temp_data' => 'array',
        'context_data' => 'array',
        'completion_data' => 'array',
        'is_authenticated' => 'boolean',
        'completed_successfully' => 'boolean',
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'locked_until' => 'datetime',
        'last_error_at' => 'datetime'
    ];

    // Session status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';

    // Language constants
    public const LANG_SWAHILI = 'sw';
    public const LANG_ENGLISH = 'en';

    // Menu constants
    public const MENU_MAIN = 'main';
    public const MENU_EXPENSES = 'expenses';
    public const MENU_LABOUR = 'labour';
    public const MENU_HARVEST = 'harvest';
    public const MENU_SALES = 'sales';
    public const MENU_MARKET_PRICE = 'market_price';
    public const MENU_BALANCE = 'balance';
    public const MENU_ALERTS = 'alerts';
    public const MENU_TASKS = 'tasks';
    public const MENU_HELP = 'help';

    // Flow constants
    public const FLOW_EXPENSE_ADD = 'expense_add';
    public const FLOW_LABOUR_ADD = 'labour_add';
    public const FLOW_HARVEST_RECORD = 'harvest_record';
    public const FLOW_PAYMENT_REQUEST = 'payment_request';
    public const FLOW_PRICE_CHECK = 'price_check';

    // Relationships
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(UssdRequest::class, 'session_id');
    }

    public function latestRequest(): HasMany
    {
        return $this->hasMany(UssdRequest::class, 'session_id')->latest();
    }

    // Session management methods
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at && now()->isAfter($this->expires_at);
    }

    public function isLocked(): bool
    {
        return $this->locked_until && now()->isBefore($this->locked_until);
    }

    public function extendSession(int $minutes = 5): void
    {
        $this->expires_at = now()->addMinutes($minutes);
        $this->last_activity_at = now();
        $this->save();
    }

    public function updateActivity(): void
    {
        $this->last_activity_at = now();
        $this->total_requests++;
        $this->save();
    }

    public function completeSession(string $reason = 'completed', array $data = []): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completion_reason = $reason;
        $this->completion_data = $data;
        $this->completed_successfully = $reason === 'completed';
        $this->duration_seconds = $this->started_at ? (int) round($this->started_at->diffInSeconds(now())) : null;
        $this->save();
    }

    public function abandonSession(string $reason = 'user_abandoned'): void
    {
        $this->status = self::STATUS_ABANDONED;
        $this->completion_reason = $reason;
        $this->completed_successfully = false;
        $this->duration_seconds = $this->started_at ? (int) round($this->started_at->diffInSeconds(now())) : null;
        $this->save();
    }

    // Authentication methods
    public function authenticateWithPin(string $pin): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        if ($this->pin_hash && Hash::check($pin, $this->pin_hash)) {
            $this->is_authenticated = true;
            $this->failed_attempts = 0;
            $this->locked_until = null;
            $this->save();
            return true;
        }

        $this->failed_attempts++;
        if ($this->failed_attempts >= 3) {
            $this->locked_until = now()->addMinutes(5); // Lock for 5 minutes
        }
        $this->save();
        
        return false;
    }

    public function setPin(string $pin): void
    {
        $this->pin_hash = Hash::make($pin);
        $this->save();
    }

    // Menu navigation methods
    public function setMenu(string $menu, int $step = 1, string $flow = null): void
    {
        // Add current menu to history
        if ($this->current_menu) {
            $history = $this->menu_history ?? [];
            $history[] = [
                'menu' => $this->current_menu,
                'step' => $this->current_step,
                'flow' => $this->current_flow,
                'timestamp' => now()->toISOString()
            ];
            $this->menu_history = array_slice($history, -10); // Keep last 10
        }

        $this->current_menu = $menu;
        $this->current_step = $step;
        $this->current_flow = $flow;
        $this->save();
    }

    public function nextStep(): void
    {
        $this->current_step++;
        $this->save();
    }

    public function previousStep(): void
    {
        if ($this->current_step > 1) {
            $this->current_step--;
        } else {
            // Go back to previous menu if available
            $history = $this->menu_history ?? [];
            if (!empty($history)) {
                $previous = array_pop($history);
                $this->current_menu = $previous['menu'];
                $this->current_step = $previous['step'];
                $this->current_flow = $previous['flow'];
                $this->menu_history = $history;
            } else {
                $this->current_menu = self::MENU_MAIN;
                $this->current_step = 1;
                $this->current_flow = null;
            }
        }
        $this->save();
    }

    // Data storage methods
    public function setTempData(string $key, $value): void
    {
        $tempData = $this->temp_data ?? [];
        $tempData[$key] = $value;
        $this->temp_data = $tempData;
        $this->save();
    }

    public function getTempData(string $key = null, $default = null)
    {
        $tempData = $this->temp_data ?? [];
        return $key ? ($tempData[$key] ?? $default) : $tempData;
    }

    public function clearTempData(): void
    {
        $this->temp_data = [];
        $this->save();
    }

    public function setContext(string $key, $value): void
    {
        $contextData = $this->context_data ?? [];
        $contextData[$key] = $value;
        $this->context_data = $contextData;
        $this->save();
    }

    public function getContext(string $key = null, $default = null)
    {
        $contextData = $this->context_data ?? [];
        return $key ? ($contextData[$key] ?? $default) : $contextData;
    }

    // Error handling
    public function logError(string $error, string $type = 'general'): void
    {
        $this->error_count++;
        $this->last_error = $error;
        $this->last_error_at = now();
        
        // Store error in context for debugging
        $errors = $this->getContext('errors', []);
        $errors[] = [
            'type' => $type,
            'message' => $error,
            'menu' => $this->current_menu,
            'step' => $this->current_step,
            'timestamp' => now()->toISOString()
        ];
        $this->setContext('errors', array_slice($errors, -5)); // Keep last 5 errors
        
        $this->save();
    }

    // Static factory methods
    public static function startSession(
        string $sessionId,
        string $phoneNumber,
        string $networkCode = null,
        string $language = self::LANG_SWAHILI
    ): self {
        return self::create([
            'session_id' => $sessionId,
            'phone_number' => $phoneNumber,
            'network_code' => $networkCode,
            'language' => $language,
            'status' => self::STATUS_ACTIVE,
            'current_menu' => self::MENU_MAIN,
            'current_step' => 1,
            'started_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(5) // 5 minute default timeout
        ]);
    }

    public static function findActiveSession(string $sessionId, string $phoneNumber): ?self
    {
        return self::where('session_id', $sessionId)
                   ->where('phone_number', $phoneNumber)
                   ->where('status', self::STATUS_ACTIVE)
                   ->first();
    }

    // Cleanup methods
    public static function expireSessions(): int
    {
        $count = self::where('status', self::STATUS_ACTIVE)
                     ->where('expires_at', '<', now())
                     ->count();
                     
        self::where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '<', now())
            ->update([
                'status' => self::STATUS_EXPIRED,
                'completion_reason' => 'timeout'
            ]);
            
        return $count;
    }

    public static function cleanupOldSessions(int $daysOld = 30): int
    {
        return self::where('created_at', '<', now()->subDays($daysOld))->delete();
    }
}
