<?php

namespace App\Events;

use App\Models\MpesaLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MpesaTransactionProcessed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $mpesaLog;
    public $eventData;

    /**
     * Create a new event instance.
     */
    public function __construct(MpesaLog $mpesaLog)
    {
        $this->mpesaLog = $mpesaLog;
        $this->eventData = $this->prepareEventData($mpesaLog);
    }

    /**
     * Prepare event data for Kafka publication
     */
    private function prepareEventData(MpesaLog $mpesaLog): array
    {
        return [
            'event_type' => 'mpesa.transaction_processed',
            'event_id' => (string) \Str::uuid(),
            'timestamp' => now()->toISOString(),
            'farm_id' => auth()->user()->farm_id ?? null,
            'user_id' => $mpesaLog->created_by_user_id,
            'mpesa_transaction' => [
                'id' => $mpesaLog->id,
                'transaction_type' => $mpesaLog->transaction_type,
                'flow_direction' => $mpesaLog->flow_direction,
                'mpesa_transaction_id' => $mpesaLog->mpesa_transaction_id,
                'mpesa_receipt_number' => $mpesaLog->mpesa_receipt_number,
                'checkout_request_id' => $mpesaLog->checkout_request_id,
                'merchant_request_id' => $mpesaLog->merchant_request_id,
                'amount' => $mpesaLog->amount,
                'transaction_cost' => $mpesaLog->transaction_cost,
                'currency' => $mpesaLog->currency,
                'status' => $mpesaLog->status,
                'result_code' => $mpesaLog->result_code,
                'result_description' => $mpesaLog->result_description,
                'sender_phone' => $mpesaLog->sender_phone,
                'receiver_phone' => $mpesaLog->receiver_phone,
                'sender_name' => $mpesaLog->sender_name,
                'receiver_name' => $mpesaLog->receiver_name,
                'initiated_at' => $mpesaLog->initiated_at?->toISOString(),
                'completed_at' => $mpesaLog->completed_at?->toISOString(),
                'processing_time_ms' => $mpesaLog->processing_time_ms,
            ],
            'linked_transaction' => $mpesaLog->transaction_id ? [
                'id' => $mpesaLog->transaction_id,
                'type' => $mpesaLog->transaction->type ?? null,
                'amount' => $mpesaLog->transaction->amount ?? null,
                'status' => $mpesaLog->transaction->status ?? null,
            ] : null,
            'processing_details' => [
                'is_successful' => $mpesaLog->isSuccessful(),
                'is_failed' => $mpesaLog->isFailed(),
                'is_pending' => $mpesaLog->isPending(),
                'is_duplicate' => $mpesaLog->is_duplicate,
                'duplicate_of' => $mpesaLog->duplicate_of,
                'retry_count' => $mpesaLog->retry_count,
                'can_retry' => $mpesaLog->canRetry(),
                'failure_reason' => $mpesaLog->failure_reason,
            ],
            'api_details' => [
                'api_endpoint' => $mpesaLog->api_endpoint,
                'response_code' => $mpesaLog->response_code,
                'response_description' => $mpesaLog->response_description,
                'ip_address' => $mpesaLog->ip_address,
                'callback_received_at' => $mpesaLog->callback_received_at?->toISOString(),
            ],
            'business_impact' => $this->calculateBusinessImpact($mpesaLog),
            'metadata' => [
                'source' => 'farmOS',
                'version' => '1.0',
                'module' => 'financial_transactions',
                'submodule' => 'mpesa_integration',
                'transaction_category' => $this->categorizeTransaction($mpesaLog),
                'requires_notification' => $this->requiresNotification($mpesaLog),
                'reconciliation_status' => $mpesaLog->is_reconciled,
            ]
        ];
    }

    /**
     * Calculate business impact of the M-Pesa transaction
     */
    private function calculateBusinessImpact(MpesaLog $mpesaLog): array
    {
        $impact = [
            'financial_impact' => 0,
            'wallet_method' => null,
            'impact_type' => 'neutral',
            'affects_cash_flow' => false,
        ];

        if ($mpesaLog->isSuccessful()) {
            $impact['financial_impact'] = $mpesaLog->amount - $mpesaLog->transaction_cost;
            $impact['wallet_method'] = 'mpesa';
            $impact['affects_cash_flow'] = true;

            switch ($mpesaLog->flow_direction) {
                case 'INCOMING':
                    $impact['impact_type'] = 'positive'; // Money coming in
                    break;
                case 'OUTGOING':
                    $impact['impact_type'] = 'negative'; // Money going out
                    $impact['financial_impact'] = -$impact['financial_impact'];
                    break;
            }
        }

        return $impact;
    }

    /**
     * Categorize the transaction based on type and context
     */
    private function categorizeTransaction(MpesaLog $mpesaLog): string
    {
        switch ($mpesaLog->transaction_type) {
            case 'STK_PUSH':
                return 'customer_payment';
            case 'C2B':
                return 'customer_to_business';
            case 'B2C':
                return 'business_to_customer';
            case 'REVERSAL':
                return 'transaction_reversal';
            case 'BALANCE_INQUIRY':
                return 'balance_check';
            default:
                return 'unknown';
        }
    }

    /**
     * Determine if this transaction requires immediate notification
     */
    private function requiresNotification(MpesaLog $mpesaLog): bool
    {
        // Notify on failures, large amounts, or duplicates
        return $mpesaLog->isFailed() || 
               $mpesaLog->amount > 10000 || 
               $mpesaLog->is_duplicate ||
               $mpesaLog->transaction_type === 'REVERSAL';
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $farmId = auth()->user()->farm_id ?? 'default';
        
        return [
            new PrivateChannel('farm.' . $farmId),
            new PrivateChannel('mpesa.transactions'), // Global M-Pesa monitoring channel
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'mpesa.transaction_processed',
            'mpesa_transaction' => [
                'id' => $this->mpesaLog->id,
                'transaction_type' => $this->mpesaLog->transaction_type,
                'amount' => $this->mpesaLog->amount,
                'status' => $this->mpesaLog->status,
                'receipt_number' => $this->mpesaLog->mpesa_receipt_number,
                'phone_number' => $this->mpesaLog->sender_phone ?: $this->mpesaLog->receiver_phone,
                'is_successful' => $this->mpesaLog->isSuccessful(),
                'processing_time_ms' => $this->mpesaLog->processing_time_ms,
            ],
            'business_impact' => $this->eventData['business_impact'],
            'timestamp' => now()->toISOString()
        ];
    }
}