<?php

namespace App\Http\Controllers;

use App\Models\PendingPayment;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PendingPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:draft,pending_manager_approval,pending_owner_approval,approved,rejected,processing,completed,failed,cancelled,on_hold',
            'payment_type' => 'nullable|in:labour_payment,supplier_payment,expense_reimbursement,bonus_payment,advance_payment,refund_payment,contractor_payment,service_payment',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'recipient_phone' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|in:requested_at,scheduled_payment_date,amount,priority',
            'sort_direction' => 'nullable|in:asc,desc'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = PendingPayment::query()
            ->with(['requestedBy:id,name', 'managerApprovedBy:id,name', 'ownerApprovedBy:id,name', 'transaction']);

        // Apply filters
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->payment_type) {
            $query->where('payment_type', $request->payment_type);
        }
        if ($request->priority) {
            $query->where('priority', $request->priority);
        }
        if ($request->start_date) {
            $query->where('requested_at', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->where('requested_at', '<=', $request->end_date);
        }
        if ($request->recipient_phone) {
            $query->where('recipient_phone', 'like', '%' . $request->recipient_phone . '%');
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'requested_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate results
        $perPage = $request->get('per_page', 20);
        $payments = $query->paginate($perPage);

        // Add computed fields
        $payments->getCollection()->transform(function ($payment) {
            $payment->status_color = $payment->getStatusColor();
            $payment->priority_color = $payment->getPriorityColor();
            $payment->is_overdue = $payment->isOverdue();
            $payment->days_until_due = $payment->getDaysUntilDue();
            return $payment;
        });

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_type' => 'required|in:labour_payment,supplier_payment,expense_reimbursement,bonus_payment,advance_payment,refund_payment,contractor_payment,service_payment',
            'amount' => 'required|numeric|min:0.01',
            'payment_reason' => 'required|string|max:1000',
            'recipient_name' => 'required|string|max:255',
            'recipient_phone' => ['nullable', 'string', 'regex:/^(?:\+254|254|0)?[17]\d{8}$/'],
            'recipient_email' => 'nullable|email|max:255',
            'preferred_payment_method' => 'required|in:cash,mpesa,bank_transfer,cheque',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'scheduled_payment_date' => 'nullable|date|after_or_equal:today',
            'linked_id' => 'nullable|uuid',
            'linked_type' => 'nullable|in:labour,expense,supplier_invoice,contract',
            'payment_details' => 'nullable|array',
            'budget_category' => 'nullable|string|max:255',
            'budget_allocated' => 'nullable|numeric|min:0',
            'supporting_documents' => 'nullable|array',
            'internal_notes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $payment = PendingPayment::create([
                'payment_type' => $request->payment_type,
                'amount' => $request->amount,
                'payment_reason' => $request->payment_reason,
                'recipient_name' => $request->recipient_name,
                'recipient_phone' => $request->recipient_phone,
                'recipient_email' => $request->recipient_email,
                'preferred_payment_method' => $request->preferred_payment_method,
                'priority' => $request->get('priority', 'medium'),
                'scheduled_payment_date' => $request->scheduled_payment_date,
                'linked_id' => $request->linked_id,
                'linked_type' => $request->linked_type,
                'payment_details' => $request->payment_details,
                'budget_category' => $request->budget_category,
                'budget_allocated' => $request->budget_allocated,
                'supporting_documents' => $request->supporting_documents,
                'internal_notes' => $request->internal_notes,
                'requested_by_user_id' => Auth::id(),
                'status' => $this->determineInitialStatus($request),
            ]);

            // Check budget constraints
            if ($payment->exceedsBudget()) {
                $payment->exceeds_budget = true;
                $payment->status = 'pending_owner_approval'; // Force owner approval for budget overruns
                $payment->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $payment->load(['requestedBy:id,name']),
                'message' => 'Payment request created successfully'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating payment request: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(PendingPayment $pendingPayment): JsonResponse
    {
        $this->authorize('view', $pendingPayment);

        $pendingPayment->load([
            'requestedBy:id,name,email',
            'managerApprovedBy:id,name,email',
            'ownerApprovedBy:id,name,email',
            'rejectedBy:id,name,email',
            'transaction',
            'linkedRecord'
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'payment' => $pendingPayment,
                'status_color' => $pendingPayment->getStatusColor(),
                'priority_color' => $pendingPayment->getPriorityColor(),
                'is_overdue' => $pendingPayment->isOverdue(),
                'days_until_due' => $pendingPayment->getDaysUntilDue(),
                'can_be_approved_by_manager' => $pendingPayment->canBeApprovedByManager(),
                'can_be_approved_by_owner' => $pendingPayment->canBeApprovedByOwner(),
                'can_be_rejected' => $pendingPayment->canBeRejected(),
                'can_be_executed' => $pendingPayment->canBeExecuted()
            ]
        ]);
    }

    public function update(Request $request, PendingPayment $pendingPayment): JsonResponse
    {
        $this->authorize('update', $pendingPayment);

        if (!in_array($pendingPayment->status, ['draft', 'rejected'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft and rejected payment requests can be updated'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'sometimes|numeric|min:0.01',
            'payment_reason' => 'sometimes|string|max:1000',
            'recipient_name' => 'sometimes|string|max:255',
            'recipient_phone' => ['sometimes', 'nullable', 'string', 'regex:/^(?:\+254|254|0)?[17]\d{8}$/'],
            'recipient_email' => 'sometimes|nullable|email|max:255',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'scheduled_payment_date' => 'sometimes|nullable|date|after_or_equal:today',
            'payment_details' => 'sometimes|array',
            'internal_notes' => 'sometimes|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $pendingPayment->update($request->only([
                'amount', 'payment_reason', 'recipient_name', 'recipient_phone',
                'recipient_email', 'priority', 'scheduled_payment_date',
                'payment_details', 'internal_notes'
            ]));

            // Reset status if it was rejected and now being updated
            if ($pendingPayment->status === 'rejected') {
                $pendingPayment->status = $this->determineInitialStatus($request);
                $pendingPayment->rejected_by_user_id = null;
                $pendingPayment->rejected_at = null;
                $pendingPayment->rejection_reason = null;
                $pendingPayment->save();
            }

            return response()->json([
                'success' => true,
                'data' => $pendingPayment->fresh(['requestedBy:id,name']),
                'message' => 'Payment request updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating payment request: ' . $e->getMessage()
            ], 500);
        }
    }

    public function approveByManager(Request $request, PendingPayment $pendingPayment): JsonResponse
    {
        $this->authorize('approveAsManager', $pendingPayment);

        if (!$pendingPayment->canBeApprovedByManager()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment request cannot be approved by manager in its current state'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'approval_notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $success = $pendingPayment->approveByManager(Auth::user(), $request->approval_notes);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $pendingPayment->fresh(['managerApprovedBy:id,name']),
                    'message' => 'Payment request approved by manager successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to approve payment request'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error approving payment request: ' . $e->getMessage()
            ], 500);
        }
    }

    public function approveByOwner(Request $request, PendingPayment $pendingPayment): JsonResponse
    {
        $this->authorize('approveAsOwner', $pendingPayment);

        if (!$pendingPayment->canBeApprovedByOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment request cannot be approved by owner in its current state'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'approval_notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $success = $pendingPayment->approveByOwner(Auth::user(), $request->approval_notes);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $pendingPayment->fresh(['ownerApprovedBy:id,name']),
                    'message' => 'Payment request approved by owner successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to approve payment request'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error approving payment request: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reject(Request $request, PendingPayment $pendingPayment): JsonResponse
    {
        $this->authorize('reject', $pendingPayment);

        if (!$pendingPayment->canBeRejected()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment request cannot be rejected in its current state'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $success = $pendingPayment->reject(Auth::user(), $request->rejection_reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $pendingPayment->fresh(['rejectedBy:id,name']),
                    'message' => 'Payment request rejected successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to reject payment request'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error rejecting payment request: ' . $e->getMessage()
            ], 500);
        }
    }

    public function execute(PendingPayment $pendingPayment): JsonResponse
    {
        $this->authorize('execute', $pendingPayment);

        if (!$pendingPayment->canBeExecuted()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment request cannot be executed in its current state'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $transaction = $pendingPayment->executePayment();

            if ($transaction) {
                DB::commit();
                return response()->json([
                    'success' => true,
                    'data' => [
                        'pending_payment' => $pendingPayment->fresh(),
                        'transaction' => $transaction
                    ],
                    'message' => 'Payment executed successfully'
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to execute payment'
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error executing payment: ' . $e->getMessage()
            ], 500);
        }
    }

    public function summary(): JsonResponse
    {
        try {
            $summary = PendingPayment::getPendingPaymentsSummary();

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching payments summary: ' . $e->getMessage()
            ], 500);
        }
    }

    public function analytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->subMonth();
            $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now();

            $analytics = PendingPayment::getPaymentMetrics($startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    public function duePayments(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filter' => 'nullable|in:today,week,overdue',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = PendingPayment::query()
            ->with(['requestedBy:id,name'])
            ->where('status', 'approved');

        switch ($request->get('filter', 'today')) {
            case 'today':
                $query->dueToday();
                break;
            case 'week':
                $query->whereBetween('scheduled_payment_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ]);
                break;
            case 'overdue':
                $query->overdue();
                break;
        }

        $payments = $query->orderBy('scheduled_payment_date')->get();

        $payments->transform(function ($payment) {
            $payment->status_color = $payment->getStatusColor();
            $payment->priority_color = $payment->getPriorityColor();
            $payment->is_overdue = $payment->isOverdue();
            $payment->days_until_due = $payment->getDaysUntilDue();
            return $payment;
        });

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    protected function determineInitialStatus(Request $request): string
    {
        $amount = $request->amount;
        $user = Auth::user();

        // Auto-approve for farm owners with small amounts
        if ($user->role === 'owner' && $amount <= 2000) {
            return 'approved';
        }

        // Require manager approval for medium amounts
        if ($amount <= 10000) {
            return 'pending_manager_approval';
        }

        // Require owner approval for larger amounts
        return 'pending_owner_approval';
    }
}
