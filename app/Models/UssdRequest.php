<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use App\Traits\HasUuids;

class UssdRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id', 'request_id', 'sequence_number', 'phone_number', 'network_code',
        'user_input', 'menu_selection', 'raw_request', 'system_response', 'response_type',
        'display_text', 'next_menu', 'action_taken', 'action_data', 'api_endpoint',
        'api_request', 'api_response', 'received_at', 'processed_at', 'responded_at',
        'processing_time_ms', 'has_error', 'error_type', 'error_message', 'error_details',
        'user_friendly_response', 'outcome', 'notes'
    ];

    protected $casts = [
        'action_data' => 'array',
        'api_request' => 'array',
        'api_response' => 'array',
        'error_details' => 'array',
        'has_error' => 'boolean',
        'user_friendly_response' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'responded_at' => 'datetime'
    ];

    // Response type constants
    public const RESPONSE_CONTINUE = 'CON';
    public const RESPONSE_END = 'END';

    // Error type constants
    public const ERROR_VALIDATION = 'validation';
    public const ERROR_API = 'api';
    public const ERROR_SYSTEM = 'system';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_AUTHENTICATION = 'authentication';

    // Outcome constants
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_ERROR = 'error';
    public const OUTCOME_VALIDATION_FAILED = 'validation_failed';
    public const OUTCOME_TIMEOUT = 'timeout';

    // Relationships
    public function session(): BelongsTo
    {
        return $this->belongsTo(UssdSession::class, 'session_id');
    }

    // Request processing methods
    public function markAsReceived(): void
    {
        $this->received_at = now();
        $this->save();
    }

    public function markAsProcessed(): void
    {
        $this->processed_at = now();
        $this->calculateProcessingTime();
        $this->save();
    }

    public function markAsResponded(): void
    {
        $this->responded_at = now();
        $this->calculateProcessingTime();
        $this->save();
    }

    protected function calculateProcessingTime(): void
    {
        if ($this->received_at && $this->responded_at) {
            $this->processing_time_ms = $this->received_at->diffInMilliseconds($this->responded_at);
        }
    }

    // Response building methods
    public function setResponse(string $text, string $type = self::RESPONSE_CONTINUE, string $nextMenu = null): void
    {
        $this->display_text = $text;
        $this->response_type = $type;
        $this->next_menu = $nextMenu;
        $this->system_response = $type . ' ' . $text;
        $this->user_friendly_response = true;
        $this->markAsProcessed();
    }

    public function setSuccessResponse(string $text, string $action = null, array $data = []): void
    {
        $this->setResponse($text, self::RESPONSE_END);
        $this->outcome = self::OUTCOME_SUCCESS;
        $this->action_taken = $action;
        $this->action_data = $data;
    }

    public function setErrorResponse(string $text, string $errorType = self::ERROR_SYSTEM, string $errorMessage = null): void
    {
        $this->setResponse($text, self::RESPONSE_END);
        $this->has_error = true;
        $this->error_type = $errorType;
        $this->error_message = $errorMessage ?? $text;
        $this->outcome = self::OUTCOME_ERROR;
        $this->user_friendly_response = true; // Still try to be user friendly
    }

    public function setValidationErrorResponse(string $text, array $errors = []): void
    {
        $this->setResponse($text, self::RESPONSE_CONTINUE);
        $this->has_error = true;
        $this->error_type = self::ERROR_VALIDATION;
        $this->error_message = $text;
        $this->error_details = $errors;
        $this->outcome = self::OUTCOME_VALIDATION_FAILED;
    }

    // API integration tracking
    public function logApiCall(string $endpoint, array $request, array $response = null, bool $success = true): void
    {
        $this->api_endpoint = $endpoint;
        $this->api_request = $request;
        $this->api_response = $response;
        
        if (!$success && $response) {
            $this->has_error = true;
            $this->error_type = self::ERROR_API;
            $this->error_message = $response['message'] ?? 'API call failed';
            $this->error_details = $response;
            $this->outcome = self::OUTCOME_ERROR;
        }
        
        $this->save();
    }

    // Input parsing methods
    public function parseUserInput(?string $input): array
    {
        $input = $input ? trim($input) : '';
        $this->user_input = $input;
        
        // Handle numeric menu selections
        if (is_numeric($input)) {
            $this->menu_selection = $input;
            return [
                'type' => 'menu_choice',
                'value' => (int)$input,
                'original' => $input
            ];
        }

        // Handle text input
        if (strlen($input) > 0) {
            return [
                'type' => 'text_input',
                'value' => $input,
                'original' => $input
            ];
        }

        // Handle empty input
        return [
            'type' => 'empty',
            'value' => null,
            'original' => $input
        ];
    }

    // Validation methods
    public function validateAmount(?string $input): array
    {
        if ($input === null) {
            return [
                'valid' => false,
                'error' => 'Tafadhali ingiza kiasi cha fedha (mfano: 500)',
                'value' => null
            ];
        }
        
        $amount = trim($input);
        
        // Remove common prefixes
        $amount = str_replace(['ksh', 'kshs', 'sh'], '', strtolower($amount));
        $amount = trim($amount);
        
        if (!is_numeric($amount)) {
            return [
                'valid' => false,
                'error' => 'Tafadhali ingiza kiasi cha fedha kwa nambari tu (mfano: 500)',
                'value' => null
            ];
        }
        
        $amount = (float)$amount;
        
        if ($amount <= 0) {
            return [
                'valid' => false,
                'error' => 'Kiasi lazima kiwe zaidi ya sifuri',
                'value' => null
            ];
        }
        
        if ($amount > 1000000) {
            return [
                'valid' => false,
                'error' => 'Kiasi ni kikubwa sana. Tafadhali ingiza kiasi kidogo',
                'value' => null
            ];
        }
        
        return [
            'valid' => true,
            'error' => null,
            'value' => $amount
        ];
    }

    public function validateQuantity(?string $input): array
    {
        if ($input === null) {
            return [
                'valid' => false,
                'error' => 'Tafadhali ingiza idadi (mfano: 50)',
                'value' => null
            ];
        }
        
        $quantity = trim($input);
        
        if (!is_numeric($quantity)) {
            return [
                'valid' => false,
                'error' => 'Tafadhali ingiza idadi kwa nambari tu (mfano: 50)',
                'value' => null
            ];
        }
        
        $quantity = (float)$quantity;
        
        if ($quantity <= 0) {
            return [
                'valid' => false,
                'error' => 'Idadi lazima iwe zaidi ya sifuri',
                'value' => null
            ];
        }
        
        if ($quantity > 10000) {
            return [
                'valid' => false,
                'error' => 'Idadi ni kubwa sana. Tafadhali ingiza idadi ndogo',
                'value' => null
            ];
        }
        
        return [
            'valid' => true,
            'error' => null,
            'value' => $quantity
        ];
    }

    // Analysis and reporting methods
    public function isSlowResponse(): bool
    {
        return $this->processing_time_ms && $this->processing_time_ms > 3000; // 3 seconds
    }

    public function isSuccessful(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESS && !$this->has_error;
    }

    public static function getPerformanceMetrics(int $days = 7): array
    {
        $startDate = now()->subDays($days);
        
        $requests = self::where('created_at', '>=', $startDate);
        
        return [
            'total_requests' => $requests->count(),
            'successful_requests' => $requests->where('outcome', self::OUTCOME_SUCCESS)->count(),
            'error_requests' => $requests->where('has_error', true)->count(),
            'average_processing_time' => $requests->whereNotNull('processing_time_ms')->avg('processing_time_ms'),
            'slow_requests' => $requests->where('processing_time_ms', '>', 3000)->count(),
            'validation_errors' => $requests->where('error_type', self::ERROR_VALIDATION)->count(),
            'api_errors' => $requests->where('error_type', self::ERROR_API)->count(),
            'system_errors' => $requests->where('error_type', self::ERROR_SYSTEM)->count()
        ];
    }

    public static function getPopularActions(int $days = 7): array
    {
        $startDate = now()->subDays($days);
        
        return self::where('created_at', '>=', $startDate)
                   ->whereNotNull('action_taken')
                   ->selectRaw('action_taken, COUNT(*) as count')
                   ->groupBy('action_taken')
                   ->orderByDesc('count')
                   ->limit(10)
                   ->get()
                   ->toArray();
    }

    // Static factory methods
    public static function createForSession(UssdSession $session, string $input = null): self
    {
        $sequenceNumber = $session->requests()->count() + 1;
        
        return self::create([
            'session_id' => $session->id,
            'sequence_number' => $sequenceNumber,
            'phone_number' => $session->phone_number,
            'network_code' => $session->network_code,
            'user_input' => $input,
            'received_at' => now()
        ]);
    }
}