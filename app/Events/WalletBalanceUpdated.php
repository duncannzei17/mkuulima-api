<?php

namespace App\Events;

use App\Models\FarmWallet;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WalletBalanceUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $farmWallet;
    public $previousBalances;
    public $balanceChange;
    public $eventData;

    /**
     * Create a new event instance.
     */
    public function __construct(FarmWallet $farmWallet, array $previousBalances, array $balanceChange)
    {
        $this->farmWallet = $farmWallet;
        $this->previousBalances = $previousBalances;
        $this->balanceChange = $balanceChange;
        $this->eventData = $this->prepareEventData($farmWallet, $previousBalances, $balanceChange);
    }

    /**
     * Prepare event data for Kafka publication
     */
    private function prepareEventData(FarmWallet $farmWallet, array $previousBalances, array $balanceChange): array
    {
        return [
            'event_type' => 'wallet.balance_updated',
            'event_id' => (string) \Str::uuid(),
            'timestamp' => now()->toISOString(),
            'farm_id' => $farmWallet->farm_id,
            'user_id' => $farmWallet->last_updated_by_user_id,
            'wallet' => [
                'id' => $farmWallet->id,
                'farm_id' => $farmWallet->farm_id,
                'currency' => $farmWallet->currency,
            ],
            'balance_update' => [
                'previous_balances' => $previousBalances,
                'current_balances' => [
                    'cash_balance' => $farmWallet->cash_balance,
                    'mpesa_balance' => $farmWallet->mpesa_balance,
                    'bank_balance' => $farmWallet->bank_balance,
                    'total_balance' => $farmWallet->total_balance,
                ],
                'changes' => $balanceChange,
                'net_change' => $balanceChange['total'] ?? 0,
                'change_type' => ($balanceChange['total'] ?? 0) >= 0 ? 'increase' : 'decrease',
                'updated_at' => $farmWallet->last_balance_update?->toISOString(),
            ],
            'monthly_aggregates' => [
                'monthly_income' => $farmWallet->monthly_income,
                'monthly_expenses' => $farmWallet->monthly_expenses,
                'monthly_net_flow' => $farmWallet->monthly_net_flow,
                'current_month' => $farmWallet->current_month?->toDateString(),
            ],
            'wallet_health' => [
                'is_healthy' => $farmWallet->isHealthy(),
                'needs_reconciliation' => $farmWallet->needsReconciliation(),
                'is_over_spending_limit' => $farmWallet->isOverSpendingLimit(),
                'low_balance_alert' => $farmWallet->total_balance <= $farmWallet->low_balance_alert_threshold,
                'negative_balance' => $farmWallet->total_balance < 0,
                'is_locked' => $farmWallet->is_locked,
            ],
            'spending_limits' => [
                'daily_limit' => $farmWallet->daily_spending_limit,
                'weekly_limit' => $farmWallet->weekly_spending_limit,
                'monthly_limit' => $farmWallet->monthly_spending_limit,
                'current_daily_spending' => $farmWallet->current_daily_spending,
                'current_weekly_spending' => $farmWallet->current_weekly_spending,
                'current_monthly_spending' => $farmWallet->current_monthly_spending,
            ],
            'triggers' => $this->identifyTriggers($farmWallet, $previousBalances, $balanceChange),
            'metadata' => [
                'source' => 'farmOS',
                'version' => '1.0',
                'module' => 'financial_transactions',
                'update_source' => $this->determineUpdateSource($balanceChange),
                'significant_change' => abs($balanceChange['total'] ?? 0) > 1000,
                'reconciliation_status' => $farmWallet->is_reconciled,
            ]
        ];
    }

    /**
     * Identify triggers that may need action
     */
    private function identifyTriggers(FarmWallet $farmWallet, array $previousBalances, array $balanceChange): array
    {
        $triggers = [];

        // Low balance trigger
        $previousTotal = $previousBalances['total_balance'] ?? 0;
        $currentTotal = $farmWallet->total_balance;
        
        if ($previousTotal > $farmWallet->low_balance_alert_threshold && 
            $currentTotal <= $farmWallet->low_balance_alert_threshold) {
            $triggers[] = 'low_balance_threshold_crossed';
        }

        // Negative balance trigger
        if ($previousTotal >= 0 && $currentTotal < 0) {
            $triggers[] = 'negative_balance_alert';
        }

        // Large withdrawal trigger
        if (($balanceChange['total'] ?? 0) < -5000) {
            $triggers[] = 'large_withdrawal_detected';
        }

        // Large deposit trigger
        if (($balanceChange['total'] ?? 0) > 10000) {
            $triggers[] = 'large_deposit_detected';
        }

        // Spending limit triggers
        if ($farmWallet->isOverSpendingLimit()) {
            $triggers[] = 'spending_limit_exceeded';
        }

        // Reconciliation needed trigger
        if ($farmWallet->needsReconciliation()) {
            $triggers[] = 'reconciliation_needed';
        }

        return $triggers;
    }

    /**
     * Determine the source of the balance update
     */
    private function determineUpdateSource(array $balanceChange): string
    {
        // This could be enhanced to track the actual source
        // For now, we infer based on the change pattern
        
        if (isset($balanceChange['mpesa']) && $balanceChange['mpesa'] != 0) {
            return 'mpesa_transaction';
        }
        
        if (isset($balanceChange['bank']) && $balanceChange['bank'] != 0) {
            return 'bank_transaction';
        }
        
        if (isset($balanceChange['cash']) && $balanceChange['cash'] != 0) {
            return 'cash_transaction';
        }
        
        return 'manual_adjustment';
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('farm.' . $this->farmWallet->farm_id),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'wallet.balance_updated',
            'wallet' => [
                'farm_id' => $this->farmWallet->farm_id,
                'total_balance' => $this->farmWallet->total_balance,
                'cash_balance' => $this->farmWallet->cash_balance,
                'mpesa_balance' => $this->farmWallet->mpesa_balance,
                'bank_balance' => $this->farmWallet->bank_balance,
            ],
            'balance_change' => $this->balanceChange,
            'triggers' => $this->eventData['triggers'] ?? [],
            'timestamp' => now()->toISOString()
        ];
    }
}