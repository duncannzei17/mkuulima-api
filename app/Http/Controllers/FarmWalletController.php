<?php

namespace App\Http\Controllers;

use App\Models\FarmWallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FarmWalletController extends Controller
{
    public function show(): JsonResponse
    {
        $wallet = $this->getFarmWallet();
        
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        $wallet->load(['farm:id,name', 'lastUpdatedBy:id,name']);

        return response()->json([
            'success' => true,
            'data' => [
                'wallet' => $wallet,
                'balance_distribution' => $wallet->getBalanceDistribution(),
                'spending_analysis' => $wallet->getSpendingAnalysis(),
                'is_healthy' => $wallet->isHealthy(),
                'needs_reconciliation' => $wallet->needsReconciliation(),
                'is_over_spending_limit' => $wallet->isOverSpendingLimit(),
            ]
        ]);
    }

    public function updateBalance(Request $request): JsonResponse
    {
        $this->authorize('updateBalance', FarmWallet::class);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric',
            'method' => 'required|in:cash,mpesa,bank_transfer',
            'type' => 'required|in:income,expense',
            'description' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $wallet = $this->getFarmWallet();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $success = $wallet->updateBalance(
                $request->amount,
                $request->method,
                $request->type
            );

            if ($success) {
                // Create a manual transaction record
                Transaction::create([
                    'type' => $request->type,
                    'category' => 'manual_adjustment',
                    'amount' => abs($request->amount),
                    'payment_method' => $request->method,
                    'description' => $request->description,
                    'transaction_date' => now(),
                    'status' => 'completed',
                    'created_by_user_id' => Auth::id(),
                    'approved_by_user_id' => Auth::id(),
                    'approved_at' => now(),
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'data' => $wallet->fresh(),
                    'message' => 'Wallet balance updated successfully'
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update wallet balance'
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating wallet balance: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reconcile(Request $request): JsonResponse
    {
        $this->authorize('reconcile', FarmWallet::class);

        $validator = Validator::make($request->all(), [
            'actual_balances' => 'required|array',
            'actual_balances.cash_balance' => 'required|numeric|min:0',
            'actual_balances.mpesa_balance' => 'required|numeric|min:0',
            'actual_balances.bank_balance' => 'required|numeric|min:0',
            'reconciliation_notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $wallet = $this->getFarmWallet();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        try {
            $differences = $wallet->reconcile(
                $request->actual_balances,
                Auth::user(),
                $request->reconciliation_notes
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'wallet' => $wallet->fresh(),
                    'differences' => $differences,
                    'is_reconciled' => $wallet->is_reconciled
                ],
                'message' => empty($differences) 
                    ? 'Wallet reconciled successfully - no discrepancies found'
                    : 'Wallet reconciled with discrepancies - balances adjusted'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error reconciling wallet: ' . $e->getMessage()
            ], 500);
        }
    }

    public function lock(Request $request): JsonResponse
    {
        $this->authorize('lock', FarmWallet::class);

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $wallet = $this->getFarmWallet();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        if ($wallet->is_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet is already locked'
            ], 422);
        }

        try {
            $success = $wallet->lock(Auth::user(), $request->reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $wallet->fresh(['lockedBy:id,name']),
                    'message' => 'Wallet locked successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to lock wallet'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error locking wallet: ' . $e->getMessage()
            ], 500);
        }
    }

    public function unlock(): JsonResponse
    {
        $this->authorize('unlock', FarmWallet::class);

        $wallet = $this->getFarmWallet();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        if (!$wallet->is_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet is not locked'
            ], 422);
        }

        try {
            $success = $wallet->unlock();

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $wallet->fresh(),
                    'message' => 'Wallet unlocked successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to unlock wallet'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error unlocking wallet: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateLimits(Request $request): JsonResponse
    {
        $this->authorize('updateLimits', FarmWallet::class);

        $validator = Validator::make($request->all(), [
            'daily_spending_limit' => 'nullable|numeric|min:0',
            'weekly_spending_limit' => 'nullable|numeric|min:0',
            'monthly_spending_limit' => 'nullable|numeric|min:0',
            'low_balance_alert_threshold' => 'nullable|numeric|min:0',
            'negative_balance_alert_threshold' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $wallet = $this->getFarmWallet();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        try {
            $wallet->update($request->only([
                'daily_spending_limit',
                'weekly_spending_limit',
                'monthly_spending_limit',
                'low_balance_alert_threshold',
                'negative_balance_alert_threshold'
            ]));

            return response()->json([
                'success' => true,
                'data' => $wallet->fresh(),
                'message' => 'Wallet limits updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating wallet limits: ' . $e->getMessage()
            ], 500);
        }
    }

    public function dashboard(): JsonResponse
    {
        $wallet = $this->getFarmWallet();
        
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        try {
            $dashboardData = [
                'wallet_overview' => [
                    'total_balance' => $wallet->total_balance,
                    'cash_balance' => $wallet->cash_balance,
                    'mpesa_balance' => $wallet->mpesa_balance,
                    'bank_balance' => $wallet->bank_balance,
                    'monthly_net_flow' => $wallet->monthly_net_flow,
                    'monthly_income' => $wallet->monthly_income,
                    'monthly_expenses' => $wallet->monthly_expenses,
                ],
                'daily_flow' => [
                    'income' => $wallet->getDailyIncome(),
                    'expenses' => $wallet->getDailyExpenses(),
                    'net_flow' => $wallet->getDailyNetFlow(),
                ],
                'balance_distribution' => $wallet->getBalanceDistribution(),
                'spending_analysis' => $wallet->getSpendingAnalysis(),
                'cashflow_trend' => $wallet->getCashflowTrend(30), // Last 30 days
                'status_indicators' => [
                    'is_healthy' => $wallet->isHealthy(),
                    'needs_reconciliation' => $wallet->needsReconciliation(),
                    'is_over_spending_limit' => $wallet->isOverSpendingLimit(),
                    'is_locked' => $wallet->is_locked,
                    'low_balance_alert' => $wallet->total_balance <= $wallet->low_balance_alert_threshold,
                ],
                'outstanding_payments' => Transaction::getOutstandingPayments(),
            ];

            return response()->json([
                'success' => true,
                'data' => $dashboardData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating dashboard data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cashflowTrend(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $wallet = $this->getFarmWallet();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        try {
            $days = $request->get('days', 30);
            $trend = $wallet->getCashflowTrend($days);

            return response()->json([
                'success' => true,
                'data' => [
                    'cashflow_trend' => $trend,
                    'period_days' => $days,
                    'summary' => [
                        'total_income' => collect($trend)->sum('income'),
                        'total_expenses' => collect($trend)->sum('expenses'),
                        'total_net_flow' => collect($trend)->sum('net_flow'),
                        'average_daily_income' => collect($trend)->avg('income'),
                        'average_daily_expenses' => collect($trend)->avg('expenses'),
                        'positive_flow_days' => collect($trend)->where('net_flow', '>', 0)->count(),
                        'negative_flow_days' => collect($trend)->where('net_flow', '<', 0)->count(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating cashflow trend: ' . $e->getMessage()
            ], 500);
        }
    }

    public function transactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'type' => 'nullable|in:income,expense',
            'payment_method' => 'nullable|in:cash,mpesa,bank_transfer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $query = Transaction::query()
                ->with(['createdBy:id,name'])
                ->where('status', 'completed');

            // Apply filters
            if ($request->start_date) {
                $query->where('transaction_date', '>=', $request->start_date);
            }
            if ($request->end_date) {
                $query->where('transaction_date', '<=', $request->end_date);
            }
            if ($request->type) {
                $query->where('type', $request->type);
            }
            if ($request->payment_method) {
                $query->where('payment_method', $request->payment_method);
            }

            $query->orderBy('transaction_date', 'desc');

            $perPage = $request->get('per_page', 20);
            $transactions = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching wallet transactions: ' . $e->getMessage()
            ], 500);
        }
    }

    public function createSnapshot(): JsonResponse
    {
        $this->authorize('manageSnapshots', FarmWallet::class);

        $wallet = $this->getFarmWallet();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        try {
            $wallet->storeDailySnapshot();

            return response()->json([
                'success' => true,
                'data' => [
                    'snapshot' => $wallet->getDailySnapshot(),
                    'wallet' => $wallet->fresh()
                ],
                'message' => 'Daily snapshot created successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating snapshot: ' . $e->getMessage()
            ], 500);
        }
    }

    public function snapshots(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'nullable|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $wallet = $this->getFarmWallet();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Farm wallet not found'
            ], 404);
        }

        $days = $request->get('days', 7);
        $snapshots = $wallet->daily_snapshots ?? [];
        
        // Get last N days of snapshots
        $recentSnapshots = collect($snapshots)
            ->sortByDesc('date')
            ->take($days)
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'snapshots' => $recentSnapshots,
                'period_days' => $days,
                'total_snapshots_available' => count($snapshots)
            ]
        ]);
    }

    protected function getFarmWallet(): ?FarmWallet
    {
        $farmId = request()->header('X-Tenant-ID');
        if (!$farmId) {
            return null;
        }

        return FarmWallet::firstOrCreate(['farm_id' => $farmId])->refresh();
    }
}
