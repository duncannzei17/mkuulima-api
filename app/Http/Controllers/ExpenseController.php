<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;

class ExpenseController extends Controller
{
    /**
     * Get all expenses for the current farm tenant
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $status = $request->query('status');
            $categoryId = $request->query('category_id');
            $cropCycleId = $request->query('crop_cycle_id');
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $paymentMethod = $request->query('payment_method');
            $minAmount = $request->query('min_amount');
            $maxAmount = $request->query('max_amount');
            $search = $request->query('search');
            $limit = $request->query('limit', 20);
            $sortBy = $request->query('sort_by', 'expense_date');
            $sortOrder = $request->query('sort_order', 'desc');

            $query = Expense::with(['category', 'creator', 'approver', 'cropCycle', 'task'])
                ->whereNull('metadata->template')
                ->whereNull('metadata->validation_rule')
                ->whereNull('metadata->validation_settings')
                ->whereNull('metadata->import_export_job')
                ->whereNull('metadata->notification_state')
                ->whereNull('metadata->notification_settings')
                ->whereNull('metadata->duplicate_settings')
                ->whereNull('metadata->duplicate_resolution->archived');

            // Apply filters
            if ($status) {
                $query->where('status', $status);
            }

            if ($categoryId) {
                $query->byCategory($categoryId);
            }

            if ($cropCycleId) {
                $query->byCrop($cropCycleId);
            }

            if ($startDate && $endDate) {
                $query->byDateRange($startDate, $endDate);
            } elseif ($startDate) {
                $query->byDateRange($startDate);
            }

            if ($paymentMethod) {
                $query->byPaymentMethod($paymentMethod);
            }

            if ($minAmount || $maxAmount) {
                $query->byAmount($minAmount, $maxAmount);
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                      ->orWhere('supplier_name', 'like', "%{$search}%")
                      ->orWhere('payment_reference', 'like', "%{$search}%");
                });
            }

            // Apply sorting
            if (in_array($sortBy, ['expense_date', 'amount', 'created_at'])) {
                $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
            }

            $expenses = $query->paginate($limit);

            // Transform expenses with additional data
            $expenses->getCollection()->transform(function ($expense) {
                return [
                    'id' => $expense->id,
                    'expense_date' => $expense->expense_date,
                    'expense_time' => $expense->expense_time,
                    'amount' => $expense->amount,
                    'total_amount' => $expense->total_amount,
                    'formatted_amount' => $expense->formatted_amount,
                    'formatted_total_amount' => $expense->formatted_total_amount,
                    'description' => $expense->description,
                    'category' => [
                        'id' => $expense->category->id,
                        'name' => $expense->category->name,
                        'color' => $expense->category->color,
                        'icon' => $expense->category->icon,
                    ],
                    'subcategory' => $expense->subcategory,
                    'crop_cycle' => $expense->cropCycle ? [
                        'id' => $expense->cropCycle->id,
                        'crop_name' => $expense->cropCycle->crop_name,
                        'variety' => $expense->cropCycle->variety,
                    ] : null,
                    'task' => $expense->task ? [
                        'id' => $expense->task->id,
                        'task_name' => $expense->task->task_name,
                    ] : null,
                    'status' => $expense->status,
                    'status_badge' => $expense->status_badge,
                    'payment_method' => $expense->payment_method,
                    'payment_reference' => $expense->payment_reference,
                    'supplier_name' => $expense->supplier_name,
                    'quantity' => $expense->quantity,
                    'unit' => $expense->unit,
                    'unit_cost' => $expense->unit_cost,
                    'tax_amount' => $expense->tax_amount,
                    'transport_cost' => $expense->transport_cost,
                    'handling_fee' => $expense->handling_fee,
                    'has_receipt' => $expense->has_receipt,
                    'location_display' => $expense->location_display,
                    'days_ago' => $expense->days_ago,
                    'is_recent' => $expense->is_recent,
                    'can_be_edited' => $expense->can_be_edited,
                    'can_be_deleted' => $expense->can_be_deleted,
                    'created_by' => [
                        'id' => $expense->creator->id,
                        'name' => $expense->creator->name,
                    ],
                    'approved_by' => $expense->approver ? [
                        'id' => $expense->approver->id,
                        'name' => $expense->approver->name,
                    ] : null,
                    'approved_at' => $expense->approved_at,
                    'created_at' => $expense->created_at,
                    'updated_at' => $expense->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'expenses' => $expenses->items(),
                    'pagination' => [
                        'current_page' => $expenses->currentPage(),
                        'last_page' => $expenses->lastPage(),
                        'per_page' => $expenses->perPage(),
                        'total' => $expenses->total(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expenses',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a new expense
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'expense_date' => 'required|date|before_or_equal:' . now()->addDays(1)->format('Y-m-d'),
                'expense_time' => 'sometimes|date_format:H:i',
                'amount' => 'required|numeric|min:0.01|max:10000000',
                'description' => 'required|string|max:500',
                'category_id' => 'required|uuid|exists:expense_categories,id',
                'subcategory' => 'sometimes|nullable|string|max:100',
                'crop_cycle_id' => 'sometimes|nullable|uuid|exists:crop_cycles,id',
                'bed_id' => 'sometimes|nullable|uuid',
                'task_id' => 'sometimes|nullable|uuid|exists:crop_tasks,id',
                'paid_to' => 'sometimes|nullable|string|max:200',
                'payment_method' => 'sometimes|string|in:cash,m_pesa,bank_transfer,cheque,credit,mobile_money,other',
                'payment_reference' => 'sometimes|nullable|string|max:100',
                'payment_details' => 'sometimes|nullable|string|max:200',
                'receipt_number' => 'sometimes|nullable|string|max:100',
                'receipt_photos' => 'sometimes|array|max:10',
                'receipt_photos.*' => 'string|max:2048',
                'supplier_name' => 'sometimes|nullable|string|max:200',
                'supplier_phone' => 'sometimes|nullable|string|max:20',
                'quantity' => 'sometimes|nullable|numeric|min:0',
                'unit' => 'sometimes|nullable|string|max:20',
                'unit_price' => 'sometimes|nullable|numeric|min:0',
                'tax_amount' => 'sometimes|nullable|numeric|min:0',
                'transport_cost' => 'sometimes|nullable|numeric|min:0',
                'handling_fee' => 'sometimes|nullable|numeric|min:0',
                'latitude' => 'sometimes|nullable|numeric|between:-90,90',
                'longitude' => 'sometimes|nullable|numeric|between:-180,180',
                'location_name' => 'sometimes|nullable|string|max:100',
                'submit_for_approval' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate category exists and is active
            $category = ExpenseCategory::active()->findOrFail($request->category_id);

            DB::beginTransaction();

            $expenseData = $validator->validated();
            $expenseData['created_by'] = $user->id;

            // Determine initial status based on user role and amount
            if ($this->requiresApproval($user, $request->amount)) {
                $expenseData['status'] = 'pending';
            } else {
                $expenseData['status'] = 'approved';
                $expenseData['approved_by'] = $user->id;
                $expenseData['approved_at'] = now();
            }

            // Set defaults
            $expenseData['expense_time'] = $expenseData['expense_time'] ?? '12:00:00';
            $expenseData['payment_method'] = $expenseData['payment_method'] ?? 'cash';
            $expenseData['tax_amount'] = $expenseData['tax_amount'] ?? 0;
            $expenseData['transport_cost'] = $expenseData['transport_cost'] ?? 0;
            $expenseData['handling_fee'] = $expenseData['handling_fee'] ?? 0;
            $expense = Expense::create($expenseData);

            // Update category statistics if approved
            if ($expense->status === 'approved') {
                $category->updateUsageStats();
            }

            // Link to crop cycle if specified
            if ($expense->crop_cycle_id) {
                $expense->linkToCrop($expense->crop_cycle_id);
            }

            // Submit for approval if requested
            if ($request->submit_for_approval && $expense->status === 'draft') {
                $expense->submitForApproval();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Expense created successfully',
                'data' => [
                    'expense' => $expense->load(['category', 'creator', 'cropCycle'])
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Expense creation failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get specific expense details
     */
    public function show(Request $request, string $expenseId): JsonResponse
    {
        try {
            $expense = Expense::with([
                'category', 'creator', 'approver', 'cropCycle', 'task',
                'attachments'
            ])->findOrFail($expenseId);

            return response()->json([
                'success' => true,
                'data' => [
                    'expense' => $expense
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update expense details
     */
    public function update(Request $request, string $expenseId): JsonResponse
    {
        try {
            $expense = Expense::findOrFail($expenseId);
            $user = $request->user();

            // Check permissions
            if (!$expense->can_be_edited) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to edit this expense'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'expense_date' => 'sometimes|date|before_or_equal:' . now()->addDays(1)->format('Y-m-d'),
                'expense_time' => 'sometimes|date_format:H:i',
                'amount' => 'sometimes|numeric|min:0.01|max:10000000',
                'description' => 'sometimes|string|max:500',
                'category_id' => 'sometimes|uuid|exists:expense_categories,id',
                'subcategory' => 'sometimes|nullable|string|max:100',
                'crop_cycle_id' => 'sometimes|nullable|uuid|exists:crop_cycles,id',
                'payment_method' => 'sometimes|string|in:cash,m_pesa,bank_transfer,cheque,credit,mobile_money,other',
                'payment_reference' => 'sometimes|nullable|string|max:100',
                'payment_details' => 'sometimes|nullable|string|max:200',
                'receipt_photos' => 'sometimes|array|max:10',
                'receipt_photos.*' => 'string|max:2048',
                'supplier_name' => 'sometimes|nullable|string|max:200',
                'supplier_phone' => 'sometimes|nullable|string|max:20',
                'quantity' => 'sometimes|nullable|numeric|min:0',
                'unit' => 'sometimes|nullable|string|max:20',
                'unit_price' => 'sometimes|nullable|numeric|min:0',
                'tax_amount' => 'sometimes|nullable|numeric|min:0',
                'transport_cost' => 'sometimes|nullable|numeric|min:0',
                'handling_fee' => 'sometimes|nullable|numeric|min:0',
                'location_name' => 'sometimes|nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $originalData = $expense->toArray();
            $validated = $validator->validated();
            $changes = array_filter(
                $validated,
                fn ($value, $key) => $this->expenseValuesDiffer($value, $originalData[$key] ?? null),
                ARRAY_FILTER_USE_BOTH
            );

            if (!empty($changes)) {
                // Handle crop cycle linking changes
                $oldCropCycleId = $expense->crop_cycle_id;
                $newCropCycleId = array_key_exists('crop_cycle_id', $validated)
                    ? $validated['crop_cycle_id']
                    : $oldCropCycleId;

                if ($oldCropCycleId !== $newCropCycleId) {
                    if ($oldCropCycleId) {
                        $expense->unlinkFromCrop();
                    }
                    if ($newCropCycleId) {
                        $expense->linkToCrop($newCropCycleId);
                    }
                }

                $expense->update($validated);
                $expense->addEditHistory($changes, $user->id);

                // Update category statistics
                $expense->category->updateUsageStats();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => [
                    'expense' => $expense->load(['category', 'creator', 'approver', 'cropCycle'])
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Expense update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Approve expense
     */
    public function approve(Request $request, string $expenseId): JsonResponse
    {
        try {
            $expense = Expense::findOrFail($expenseId);
            $user = $request->user();

            // Check permissions (only owners/managers can approve)
            if (!$this->canApproveExpense($user, $expense)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to approve this expense'
                ], 403);
            }

            $expense->approve($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Expense approved successfully',
                'data' => [
                    'expense' => $expense->load(['category', 'creator', 'approver'])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Reject expense
     */
    public function reject(Request $request, string $expenseId): JsonResponse
    {
        try {
            $expense = Expense::findOrFail($expenseId);
            $user = $request->user();

            // Check permissions
            if (!$this->canApproveExpense($user, $expense)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to reject this expense'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $expense->reject($request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Expense rejected successfully',
                'data' => [
                    'expense' => $expense->load(['category', 'creator'])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Delete expense
     */
    public function destroy(Request $request, string $expenseId): JsonResponse
    {
        try {
            $expense = Expense::findOrFail($expenseId);

            if (!$expense->can_be_deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to delete this expense'
                ], 403);
            }

            DB::beginTransaction();

            // Unlink from crop cycle if linked
            if ($expense->crop_cycle_id) {
                $expense->unlinkFromCrop();
            }

            // Update category statistics
            $expense->category->updateUsageStats();

            $expense->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Expense deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Expense deletion failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function export(Request $request)
    {
        if (!$this->canManageExpenseApprovals($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only farm owners and managers can export the expense ledger',
            ], 403);
        }

        $validator = Validator::make($request->query(), [
            'format' => 'nullable|in:csv,pdf',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|in:all,pending,approved,rejected',
            'category' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0|gte:min_amount',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid expense export filters',
                'errors' => $validator->errors(),
            ], 422);
        }

        $format = $request->query('format', 'csv');
        $query = Expense::with(['category', 'creator'])
            ->whereNull('metadata->template')
            ->whereNull('metadata->validation_rule')
            ->whereNull('metadata->validation_settings')
            ->whereNull('metadata->import_export_job')
            ->whereNull('metadata->duplicate_settings')
            ->whereNull('metadata->duplicate_resolution->archived')
            ->latest('expense_date');

        if ($request->query('date_from')) {
            $query->where('expense_date', '>=', $request->query('date_from'));
        }
        if ($request->query('date_to')) {
            $query->where('expense_date', '<=', $request->query('date_to'));
        }
        if ($request->query('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('category')) {
            $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', $request->query('category')));
        }
        if ($request->query('min_amount')) {
            $query->where('amount', '>=', $request->query('min_amount'));
        }
        if ($request->query('max_amount')) {
            $query->where('amount', '<=', $request->query('max_amount'));
        }
        if ($request->query('search')) {
            $search = $request->query('search');
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('description', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%")
                    ->orWhere('payment_reference', 'like', "%{$search}%");
            });
        }

        $expenses = $query->get();
        $filename = 'expenses-' . now()->format('Y-m-d-His') . '.' . $format;

        $this->recordImportExportJob($request, [
            'type' => 'export',
            'title' => strtoupper($format) . ' expense export',
            'description' => 'Exported ' . $expenses->count() . ' expense records',
            'status' => 'completed',
            'record_count' => $expenses->count(),
            'format' => $format,
            'filename' => $filename,
        ]);

        if ($format === 'pdf') {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('exports.expenses', [
                'expenses' => $expenses,
                'generatedAt' => now(),
                'total' => (float) $expenses->sum('amount'),
            ])->render());
            $pdf->setPaper('a4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        return response($this->buildExpenseCsv($expenses), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'options' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $options = json_decode($request->input('options', '{}'), true) ?: [];
        $rows = $this->parseExpenseImportCsv($request->file('file')->getRealPath());
        $created = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;
                $category = ExpenseCategory::active()
                    ->where(function ($categoryQuery) use ($row) {
                        $categoryQuery->where('name', $row['category'] ?? '')
                            ->orWhere('slug', \Illuminate\Support\Str::slug($row['category'] ?? ''));
                    })
                    ->first();

                if (!$category) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Unknown category: ' . ($row['category'] ?? '')];
                    continue;
                }

                if (empty($row['date']) || empty($row['description']) || !is_numeric($row['amount'] ?? null) || (float) $row['amount'] <= 0) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Date, description, category, and positive amount are required'];
                    continue;
                }

                $autoApprove = (bool) ($options['auto_approve'] ?? false);
                $status = $autoApprove || !$this->requiresApproval($request->user(), (float) $row['amount']) ? 'approved' : 'pending';
                $expense = Expense::create([
                    'expense_date' => Carbon::parse($row['date'])->toDateString(),
                    'expense_time' => '12:00:00',
                    'description' => $row['description'],
                    'category_id' => $category->id,
                    'amount' => (float) $row['amount'],
                    'created_by' => $request->user()->id,
                    'approved_by' => $status === 'approved' ? $request->user()->id : null,
                    'approved_at' => $status === 'approved' ? now() : null,
                    'status' => $status,
                    'payment_method' => 'cash',
                    'supplier_name' => $row['vendor'] ?? null,
                    'metadata' => [
                        'imported' => true,
                        'import_file' => $request->file('file')->getClientOriginalName(),
                        'notes' => $row['notes'] ?? null,
                    ],
                ]);
                $created[] = $expense;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Expense import failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        $this->recordImportExportJob($request, [
            'type' => 'import',
            'title' => 'CSV expense import',
            'description' => 'Imported ' . count($created) . ' expense records from ' . $request->file('file')->getClientOriginalName(),
            'status' => empty($errors) ? 'completed' : 'completed_with_errors',
            'record_count' => count($created),
            'error_count' => count($errors),
            'errors' => $errors,
            'filename' => $request->file('file')->getClientOriginalName(),
        ]);

        return response()->json([
            'success' => true,
            'message' => count($created) . ' expense records imported',
            'data' => [
                'created_count' => count($created),
                'error_count' => count($errors),
                'expenses' => collect($created)->map->load(['category', 'creator'])->values(),
                'errors' => $errors,
            ],
        ], empty($created) && !empty($errors) ? 422 : 201);
    }

    public function uploadFile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp,pdf|max:5120',
            'type' => 'sometimes|string|in:receipt,document',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $type = $request->input('type', 'receipt');
        $path = $file->store('expenses/' . $type . 's/' . now()->format('Y/m'), 'public');

        return response()->json([
            'success' => true,
            'message' => ucfirst($type) . ' uploaded successfully',
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'filename' => basename($path),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'type' => $type,
            ],
        ], 201);
    }

    public function deleteFile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file_url' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $path = $this->normalizePublicStoragePath($request->input('file_url'));
        if (!$path || !str_starts_with($path, 'expenses/')) {
            return response()->json([
                'success' => false,
                'message' => 'File path is not managed by expenses',
            ], 422);
        }

        $deleted = Storage::disk('public')->exists($path)
            ? Storage::disk('public')->delete($path)
            : false;

        return response()->json([
            'success' => true,
            'message' => $deleted ? 'File deleted successfully' : 'File was already removed',
            'data' => [
                'deleted' => $deleted,
                'path' => $path,
            ],
        ]);
    }

    public function processOcr(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp,pdf,txt|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $path = $file->store('expenses/ocr/' . now()->format('Y/m'), 'public');
        $absolutePath = Storage::disk('public')->path($path);
        $extractedText = $this->extractReceiptText($absolutePath, $file->getMimeType());
        $fields = $this->extractReceiptFields($extractedText, $file->getClientOriginalName());
        $configuredEngine = $this->availableOcrEngine($file->getMimeType());
        $hasExtractedText = trim($extractedText) !== '';

        return response()->json([
            'success' => true,
            'message' => $hasExtractedText ? 'Receipt text extracted' : 'Receipt saved for manual review',
            'data' => [
                'file' => [
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                ],
                'engine' => $configuredEngine ?: 'metadata',
                'extracted_text' => $extractedText,
                'amount' => $fields['amount'],
                'vendor' => $fields['vendor'],
                'date' => $fields['date'],
                'receipt_number' => $fields['receipt_number'],
                'confidence' => $this->receiptConfidence($fields, $hasExtractedText, (bool) $configuredEngine),
                'requires_review' => !$configuredEngine || !$fields['amount'],
                'warnings' => $configuredEngine ? [] : [
                    'No OCR engine is configured on the server; metadata and filename extraction were used.',
                ],
            ],
        ]);
    }

    public function importTemplate(Request $request)
    {
        $csv = "Date,Description,Category,Amount,Vendor,Notes\n"
            . now()->toDateString() . ",Sample farm labour,Labour,5000,Worker group,Optional notes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="expense-import-template.csv"',
        ]);
    }

    public function importExportHistory(Request $request): JsonResponse
    {
        $history = Expense::query()
            ->where('is_planned', true)
            ->whereNotNull('metadata->import_export_job')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Expense $row) => $this->formatImportExportJob($row));

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    public function importExportHistoryDestroy(Request $request, string $historyId): JsonResponse
    {
        Expense::query()
            ->where('is_planned', true)
            ->whereNotNull('metadata->import_export_job')
            ->findOrFail($historyId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Import/export history item deleted',
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $state = $this->notificationStateData();
        $notifications = collect($this->buildExpenseNotifications($request))
            ->reject(fn ($notification) => in_array($notification['id'], $state['dismissed'] ?? [], true))
            ->map(function ($notification) use ($state) {
                $notification['read'] = in_array($notification['id'], $state['read'] ?? [], true);
                return $notification;
            })
            ->values();

        if ($request->has('read')) {
            $read = filter_var($request->query('read'), FILTER_VALIDATE_BOOLEAN);
            $notifications = $notifications->filter(fn ($notification) => $notification['read'] === $read)->values();
        }

        if ($request->query('type')) {
            $notifications = $notifications->filter(fn ($notification) => $notification['type'] === $request->query('type'))->values();
        }

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    public function markNotificationRead(Request $request, string $notificationId): JsonResponse
    {
        $state = $this->notificationStateData();
        $state['read'] = array_values(array_unique(array_merge($state['read'] ?? [], [$notificationId])));
        $this->saveNotificationState($request, $state);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $state,
        ]);
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $state = $this->notificationStateData();
        $ids = collect($this->buildExpenseNotifications($request))->pluck('id')->all();
        $state['read'] = array_values(array_unique(array_merge($state['read'] ?? [], $ids)));
        $this->saveNotificationState($request, $state);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'data' => $state,
        ]);
    }

    public function dismissNotification(Request $request, string $notificationId): JsonResponse
    {
        $state = $this->notificationStateData();
        $state['dismissed'] = array_values(array_unique(array_merge($state['dismissed'] ?? [], [$notificationId])));
        $this->saveNotificationState($request, $state);

        return response()->json([
            'success' => true,
            'message' => 'Notification dismissed',
            'data' => $state,
        ]);
    }

    public function notificationSettings(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->notificationSettingsData(),
        ]);
    }

    public function notificationSettingsUpdate(Request $request): JsonResponse
    {
        $settings = array_merge($this->defaultNotificationSettings(), $request->all());
        $this->saveNotificationSettings($request, $settings);

        return response()->json([
            'success' => true,
            'message' => 'Notification settings updated successfully',
            'data' => $settings,
        ]);
    }

    /**
     * Bulk create expenses
     */
    public function bulkStore(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'expenses' => 'required|array|max:50',
                'expenses.*.expense_date' => 'required|date|before_or_equal:' . now()->addDays(1)->format('Y-m-d'),
                'expenses.*.amount' => 'required|numeric|min:0.01|max:10000000',
                'expenses.*.description' => 'required|string|max:500',
                'expenses.*.category_id' => 'required|uuid|exists:expense_categories,id',
                'expenses.*.crop_cycle_id' => 'sometimes|nullable|uuid|exists:crop_cycles,id',
                'expenses.*.payment_method' => 'sometimes|string|in:cash,m_pesa,bank_transfer,cheque,credit,mobile_money,other',
                'expenses.*.receipt_photos' => 'sometimes|array|max:10',
                'expenses.*.receipt_photos.*' => 'string|max:2048',
                'expenses.*.metadata' => 'sometimes|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            $createdExpenses = [];
            $errors = [];

            DB::beginTransaction();

            foreach ($request->expenses as $index => $expenseData) {
                try {
                    $expenseData['created_by'] = $user->id;
                    $expenseData['expense_time'] = $expenseData['expense_time'] ?? '12:00:00';
                    $expenseData['payment_method'] = $expenseData['payment_method'] ?? 'cash';

                    // Determine status
                    if ($this->requiresApproval($user, $expenseData['amount'])) {
                        $expenseData['status'] = 'pending';
                    } else {
                        $expenseData['status'] = 'approved';
                        $expenseData['approved_by'] = $user->id;
                        $expenseData['approved_at'] = now();
                    }

                    $expense = Expense::create($expenseData);
                    $createdExpenses[] = $expense;

                    // Update category statistics if approved
                    if ($expense->status === 'approved') {
                        $expense->category->updateUsageStats();
                    }

                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'message' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($createdExpenses) . ' expenses created successfully',
                'data' => [
                    'created_count' => count($createdExpenses),
                    'error_count' => count($errors),
                    'expenses' => $createdExpenses,
                    'errors' => $errors
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Bulk expense creation failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Bulk update expenses.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'expense_ids' => 'required|array|min:1|max:100',
            'expense_ids.*' => 'required|uuid|exists:expenses,id',
            'updates' => 'required|array',
            'updates.status' => 'sometimes|in:approved,pending,rejected,draft',
            'updates.category_id' => 'sometimes|uuid|exists:expense_categories,id',
            'updates.payment_method' => 'sometimes|in:cash,m_pesa,bank_transfer,cheque,credit,mobile_money,other',
            'updates.metadata' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $allowedUpdates = collect($request->input('updates'))
                ->only(['status', 'category_id', 'payment_method', 'metadata'])
                ->all();

            $updated = [];
            $skipped = [];

            DB::beginTransaction();

            Expense::whereIn('id', $request->expense_ids)->get()->each(function (Expense $expense) use ($allowedUpdates, &$updated, &$skipped) {
                if (!$expense->can_be_edited) {
                    $skipped[] = ['id' => $expense->id, 'message' => 'No edit permission'];
                    return;
                }

                $expense->update($allowedUpdates);
                $expense->addEditHistory($allowedUpdates, auth()->id());
                $expense->category?->updateUsageStats();
                $updated[] = $expense->fresh(['category', 'creator', 'approver']);
            });

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($updated) . ' expenses updated successfully',
                'data' => [
                    'updated_count' => count($updated),
                    'skipped_count' => count($skipped),
                    'expenses' => $updated,
                    'skipped' => $skipped,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Bulk expense update failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Bulk delete expenses.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'expense_ids' => 'required|array|min:1|max:100',
            'expense_ids.*' => 'required|uuid|exists:expenses,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $deleted = 0;
            $skipped = [];

            DB::beginTransaction();

            Expense::whereIn('id', $request->expense_ids)->get()->each(function (Expense $expense) use (&$deleted, &$skipped) {
                if (!$expense->can_be_deleted) {
                    $skipped[] = ['id' => $expense->id, 'message' => 'No delete permission'];
                    return;
                }

                if ($expense->crop_cycle_id) {
                    $expense->unlinkFromCrop();
                }

                $category = $expense->category;
                $expense->delete();
                $category?->updateUsageStats();
                $deleted++;
            });

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $deleted . ' expenses deleted successfully',
                'data' => [
                    'deleted_count' => $deleted,
                    'skipped_count' => count($skipped),
                    'skipped' => $skipped,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Bulk expense deletion failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Duplicate an expense into a draft record.
     */
    public function duplicate(Request $request, string $expenseId): JsonResponse
    {
        try {
            $expense = Expense::findOrFail($expenseId);
            $duplicate = $expense->duplicate($request->input('expense_date'));
            $duplicate->created_by = $request->user()->id;
            $duplicate->save();

            return response()->json([
                'success' => true,
                'message' => 'Expense duplicated successfully',
                'data' => [
                    'expense' => $duplicate->load(['category', 'creator', 'cropCycle']),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense duplication failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Pending expenses awaiting approval.
     */
    public function pending(Request $request): JsonResponse
    {
        if (!$this->canManageExpenseApprovals($request)) {
            return $this->expenseApprovalForbidden();
        }

        $limit = min((int) $request->query('limit', $request->query('per_page', 20)), 100);
        $expenses = Expense::with(['category', 'creator'])
            ->awaitingApproval()
            ->paginate($limit);

        return response()->json([
            'success' => true,
            'data' => [
                'expenses' => $expenses->items(),
                'pagination' => [
                    'current_page' => $expenses->currentPage(),
                    'last_page' => $expenses->lastPage(),
                    'per_page' => $expenses->perPage(),
                    'total' => $expenses->total(),
                ],
            ],
        ]);
    }

    /**
     * Bulk approval/rejection workflow.
     */
    public function processApprovals(Request $request): JsonResponse
    {
        if (!$this->canManageExpenseApprovals($request)) {
            return $this->expenseApprovalForbidden();
        }

        $validator = Validator::make($request->all(), [
            'expense_ids' => 'required|array|min:1|max:100',
            'expense_ids.*' => 'required|uuid|exists:expenses,id',
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $processed = [];
            $skipped = [];

            DB::beginTransaction();

            Expense::whereIn('id', $request->expense_ids)->get()->each(function (Expense $expense) use ($request, $user, &$processed, &$skipped) {
                if (!$this->canApproveExpense($user, $expense)) {
                    $skipped[] = ['id' => $expense->id, 'message' => 'No approval permission'];
                    return;
                }

                try {
                    if ($request->action === 'approve') {
                        $expense->approve($user->id);
                    } else {
                        $expense->reject($request->reason, $user->id);
                    }
                    $processed[] = $expense->fresh(['category', 'creator', 'approver']);
                } catch (\Exception $e) {
                    $skipped[] = ['id' => $expense->id, 'message' => $e->getMessage()];
                }
            });

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($processed) . ' expenses processed successfully',
                'data' => [
                    'processed_count' => count($processed),
                    'skipped_count' => count($skipped),
                    'expenses' => $processed,
                    'skipped' => $skipped,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Approval processing failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function approvalHistory(Request $request): JsonResponse
    {
        if (!$this->canManageExpenseApprovals($request)) {
            return $this->expenseApprovalForbidden();
        }

        $query = Expense::with(['category', 'creator', 'approver'])
            ->whereIn('status', ['approved', 'rejected'])
            ->orderByRaw('COALESCE(approved_at, rejected_at, updated_at) DESC');

        if ($request->query('expense_id')) {
            $query->where('id', $request->query('expense_id'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->limit(100)->get(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $period = $this->normalizePeriod($request->query('period', '30_days'));

        return response()->json([
            'success' => true,
            'data' => Expense::getDashboardStats($period),
        ]);
    }

    public function charts(Request $request): JsonResponse
    {
        $period = $this->normalizePeriod($request->query('period', '30_days'));

        return response()->json([
            'success' => true,
            'data' => [
                'category_spending' => ExpenseCategory::getSpendingByCategory($period),
                'payment_methods' => Expense::getPaymentMethodDistribution(),
                'monthly_trend' => Expense::getMonthlyTrend(12),
            ],
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        $months = min((int) $request->query('months', 12), 36);

        return response()->json([
            'success' => true,
            'data' => [
                'monthly' => Expense::getMonthlyTrend($months),
                'daily' => $this->expenseSeries(now()->subDays(30), 'day'),
            ],
        ]);
    }

    public function budgetAnalysis(Request $request): JsonResponse
    {
        $activeBudgets = DB::table('expense_budgets')
            ->where('status', 'active')
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->get();

        $categoryBudgets = ExpenseCategory::active()
            ->get()
            ->map(function (ExpenseCategory $category) {
                return [
                    'category_id' => $category->id,
                    'category' => $category->name,
                    'monthly_budget' => (float) ($category->monthly_budget ?? 0),
                    'seasonal_budget' => (float) ($category->seasonal_budget ?? 0),
                    'spent' => (float) $category->expenses()->approved()->sum('amount'),
                    'is_over_budget' => $category->is_over_budget,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'active_budgets' => $activeBudgets,
                'category_budgets' => $categoryBudgets,
                'total_active_budget' => round((float) $activeBudgets->sum('total_budget'), 2),
                'total_spent' => round((float) Expense::approved()->sum('amount'), 2),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', $request->query('search', '')));
        $query = Expense::with(['category', 'creator', 'approver'])->latest('expense_date');

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('supplier_name', 'like', "%{$term}%")
                    ->orWhere('payment_reference', 'like', "%{$term}%");
            });
        }

        if ($request->query('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'success' => true,
            'data' => [
                'expenses' => $query->limit(50)->get(),
            ],
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $descriptions = Expense::query()
            ->when($term !== '', fn ($query) => $query->where('description', 'like', "%{$term}%"))
            ->select('description')
            ->distinct()
            ->limit(10)
            ->pluck('description');

        $suppliers = Expense::query()
            ->whereNotNull('supplier_name')
            ->when($term !== '', fn ($query) => $query->where('supplier_name', 'like', "%{$term}%"))
            ->select('supplier_name')
            ->distinct()
            ->limit(10)
            ->pluck('supplier_name');

        return response()->json([
            'success' => true,
            'data' => [
                'descriptions' => $descriptions,
                'suppliers' => $suppliers,
            ],
        ]);
    }

    public function auditTrail(Request $request): JsonResponse
    {
        $query = Expense::with(['category', 'creator', 'approver'])->latest('updated_at');

        if ($request->query('expense_id')) {
            $query->where('id', $request->query('expense_id'));
        }

        $events = $query->limit(100)->get()->flatMap(function (Expense $expense) {
            $events = [[
                'expense_id' => $expense->id,
                'action' => 'created',
                'description' => $expense->description,
                'user' => optional($expense->creator)->name,
                'timestamp' => $expense->created_at,
            ]];

            foreach (($expense->edit_history ?? []) as $entry) {
                $events[] = [
                    'expense_id' => $expense->id,
                    'action' => 'updated',
                    'description' => $expense->description,
                    'user_id' => $entry['edited_by'] ?? null,
                    'changes' => $entry['changes'] ?? [],
                    'timestamp' => $entry['timestamp'] ?? $expense->updated_at,
                ];
            }

            return $events;
        })->values();

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    public function auditStats(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'created' => Expense::count(),
                'updated' => Expense::whereNotNull('edit_history')->count(),
                'approved' => Expense::approved()->count(),
                'rejected' => Expense::rejected()->count(),
                'deleted' => Expense::onlyTrashed()->count(),
            ],
        ]);
    }

    public function checkDuplicates(Request $request): JsonResponse
    {
        $amount = (float) $request->input('amount', 0);
        $description = trim((string) $request->input('description', ''));
        $date = $request->input('expense_date', $request->input('date', now()->toDateString()));
        $settings = $this->duplicateDetectionSettings();
        $window = (int) $request->input('date_range', $settings['detectionSettings']['timeWindow'] ?? 7);

        $duplicates = Expense::with('category')
            ->whereNull('metadata->template')
            ->whereNull('metadata->validation_rule')
            ->whereNull('metadata->validation_settings')
            ->whereNull('metadata->import_export_job')
            ->whereNull('metadata->notification_state')
            ->whereNull('metadata->notification_settings')
            ->whereNull('metadata->duplicate_settings')
            ->whereNull('metadata->duplicate_resolution->archived')
            ->whereBetween('expense_date', [
                Carbon::parse($date)->subDays($window)->toDateString(),
                Carbon::parse($date)->addDays($window)->toDateString(),
            ])
            ->whereBetween('amount', [$amount * 0.95, $amount * 1.05])
            ->when($description !== '', function ($query) use ($description) {
                $needle = mb_substr($description, 0, 30);
                $query->where(function ($descriptionQuery) use ($needle) {
                    $descriptionQuery->where('description', 'like', '%' . $needle . '%')
                        ->orWhere('supplier_name', 'like', '%' . $needle . '%')
                        ->orWhere('payment_reference', 'like', '%' . $needle . '%');
                });
            })
            ->latest('expense_date')
            ->limit(25)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'has_duplicates' => $duplicates->isNotEmpty(),
                'duplicates' => $duplicates->map(fn (Expense $expense) => $this->formatDuplicateExpense($expense, 100, ['amount', 'date']))->values(),
            ],
        ]);
    }

    public function duplicateGroups(Request $request): JsonResponse
    {
        $settings = $this->duplicateDetectionSettings();
        $threshold = (int) $request->query('threshold', $settings['detectionSettings']['sensitivity'] ?? 80);
        $dateRange = (int) $request->query('date_range', $settings['detectionSettings']['timeWindow'] ?? 7);
        $limit = (int) $request->query('limit', 250);

        $expenses = Expense::with('category')
            ->whereNull('metadata->template')
            ->whereNull('metadata->validation_rule')
            ->whereNull('metadata->validation_settings')
            ->whereNull('metadata->import_export_job')
            ->whereNull('metadata->notification_state')
            ->whereNull('metadata->notification_settings')
            ->whereNull('metadata->duplicate_settings')
            ->whereNull('metadata->duplicate_resolution->archived')
            ->where('amount', '>', 0)
            ->latest('expense_date')
            ->limit(min(max($limit, 25), 500))
            ->get();

        $groups = $this->buildDuplicateGroups($expenses, $threshold, $dateRange);

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    public function resolveDuplicates(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'duplicate_ids' => 'required|array|min:1',
            'duplicate_ids.*' => 'uuid|exists:expenses,id',
            'keep_id' => 'nullable|uuid|exists:expenses,id',
            'action' => 'required|string|in:delete,merge,archive,keep_all',
            'group_id' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ids = collect($request->input('duplicate_ids'))->unique()->values();
        $keepId = $request->input('keep_id', $ids->first());
        if (!$ids->contains($keepId)) {
            return response()->json([
                'success' => false,
                'message' => 'The kept expense must be part of the duplicate group',
            ], 422);
        }

        $expenses = Expense::with('category')->whereIn('id', $ids)->get()->keyBy('id');
        $keepExpense = $expenses->get($keepId);
        if (!$keepExpense) {
            return response()->json([
                'success' => false,
                'message' => 'Kept expense was not found',
            ], 404);
        }

        $action = $request->input('action');
        $resolved = [];

        DB::beginTransaction();
        try {
            if ($action === 'keep_all') {
                foreach ($expenses as $expense) {
                    $this->markDuplicateResolution($expense, $request, 'dismissed', $keepId);
                    $resolved[] = ['id' => $expense->id, 'result' => 'dismissed'];
                }
            } else {
                foreach ($expenses as $expense) {
                    if ($expense->id === $keepId) {
                        $this->markDuplicateResolution($expense, $request, 'kept', $keepId);
                        $resolved[] = ['id' => $expense->id, 'result' => 'kept'];
                        continue;
                    }

                    if ($action === 'merge') {
                        $keepMetadata = $keepExpense->metadata ?: [];
                        $keepMetadata['duplicate_resolution']['merged_from'][] = [
                            'id' => $expense->id,
                            'description' => $expense->description,
                            'amount' => (float) $expense->amount,
                            'expense_date' => optional($expense->expense_date)->toDateString(),
                            'resolved_at' => now()->toISOString(),
                        ];
                        $keepExpense->update([
                            'metadata' => $keepMetadata,
                            'receipt_number' => $keepExpense->receipt_number ?: $expense->receipt_number,
                            'supplier_name' => $keepExpense->supplier_name ?: $expense->supplier_name,
                            'payment_reference' => $keepExpense->payment_reference ?: $expense->payment_reference,
                        ]);
                    }

                    $this->markDuplicateResolution($expense, $request, $action, $keepId);

                    if ($action === 'archive') {
                        $metadata = $expense->metadata ?: [];
                        $metadata['duplicate_resolution']['archived'] = true;
                        $expense->update(['metadata' => $metadata]);
                        $resolved[] = ['id' => $expense->id, 'result' => 'archived'];
                    } else {
                        $expense->delete();
                        $resolved[] = ['id' => $expense->id, 'result' => $action === 'merge' ? 'merged_deleted' : 'deleted'];
                    }
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Duplicate resolution failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Duplicate group resolved',
            'data' => [
                'action' => $action,
                'keep_id' => $keepId,
                'resolved' => $resolved,
                'kept_expense' => $keepExpense->fresh('category'),
            ],
        ]);
    }

    public function duplicateSettings(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->duplicateDetectionSettings(),
        ]);
    }

    public function duplicateSettingsUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'detectionSettings' => 'nullable|array',
            'detectionSettings.sensitivity' => 'nullable|integer|min:50|max:100',
            'detectionSettings.autoScan' => 'nullable|boolean',
            'detectionSettings.autoResolveHighConfidence' => 'nullable|boolean',
            'detectionSettings.notifyOnDuplicates' => 'nullable|boolean',
            'detectionSettings.timeWindow' => 'nullable|integer|min:1|max:365',
            'fieldWeights' => 'nullable|array',
            'fieldWeights.*.name' => 'required_with:fieldWeights|string|max:80',
            'fieldWeights.*.label' => 'required_with:fieldWeights|string|max:120',
            'fieldWeights.*.weight' => 'required_with:fieldWeights|integer|min:0|max:100',
            'rules' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $settings = array_replace_recursive($this->defaultDuplicateDetectionSettings(), $request->only([
            'detectionSettings',
            'fieldWeights',
            'rules',
        ]));
        $this->saveInternalMetadataRow($request, 'duplicate_settings', 'Expense duplicate detection settings', $settings);

        return response()->json([
            'success' => true,
            'message' => 'Duplicate detection settings updated',
            'data' => $settings,
        ]);
    }

    public function performance(Request $request): JsonResponse
    {
        $stats = Expense::getDashboardStats('30_days');

        return response()->json([
            'success' => true,
            'data' => [
                'average_amount' => round((float) ($stats['average_amount'] ?? 0), 2),
                'approval_backlog' => Expense::pending()->count(),
                'receipt_coverage' => $this->receiptCoverage(),
                'category_count' => ExpenseCategory::active()->count(),
                'monthly_trend' => Expense::getMonthlyTrend(6),
            ],
        ]);
    }

    public function performanceSuggestions(Request $request): JsonResponse
    {
        $suggestions = [];
        $pendingCount = Expense::pending()->count();
        $receiptCoverage = $this->receiptCoverage();

        if ($pendingCount > 0) {
            $suggestions[] = [
                'type' => 'approval',
                'severity' => 'warning',
                'message' => $pendingCount . ' expenses are awaiting approval.',
            ];
        }

        if ($receiptCoverage < 80) {
            $suggestions[] = [
                'type' => 'documentation',
                'severity' => 'info',
                'message' => 'Receipt coverage is below 80%. Encourage receipt capture for audit readiness.',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $suggestions,
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'can_create' => true,
                'can_approve' => $user->ownsFarm() || $user->hasRole('manager'),
                'can_manage_categories' => $user->ownsFarm() || $user->hasRole('manager'),
                'can_export' => $user->ownsFarm() || $user->hasRole('manager') || $user->hasRole('accountant'),
            ],
        ]);
    }

    public function rolePermissions(Request $request, string $role): JsonResponse
    {
        $rolePermissions = [
            'owner' => ['create', 'view', 'edit', 'delete', 'approve', 'export', 'manage_categories'],
            'manager' => ['create', 'view', 'edit', 'approve', 'export', 'manage_categories'],
            'accountant' => ['create', 'view', 'edit', 'export'],
            'worker' => ['create', 'view_own'],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'role' => $role,
                'permissions' => $rolePermissions[$role] ?? ['view'],
            ],
        ]);
    }

    public function realTimeMetrics(Request $request): JsonResponse
    {
        try {
            $todayStart = now()->startOfDay();
            $todayEnd = now()->endOfDay();
            $yesterdayStart = now()->subDay()->startOfDay();
            $yesterdayEnd = now()->subDay()->endOfDay();
            $weekStart = now()->startOfWeek();
            $previousWeekStart = now()->subWeek()->startOfWeek();
            $previousWeekEnd = now()->subWeek()->endOfWeek();
            $thirtyDaysAgo = now()->subDays(30)->startOfDay();

            $today = $this->ledgerExpenseQuery()->approved()->whereBetween('expense_date', [$todayStart->toDateString(), $todayEnd->toDateString()]);
            $yesterdayTotal = (float) $this->ledgerExpenseQuery()->approved()->whereBetween('expense_date', [$yesterdayStart->toDateString(), $yesterdayEnd->toDateString()])->sum('amount');
            $todayTotal = (float) $today->clone()->sum('amount');
            $weekTotal = (float) $this->ledgerExpenseQuery()->approved()->where('expense_date', '>=', $weekStart->toDateString())->sum('amount');
            $previousWeekTotal = (float) $this->ledgerExpenseQuery()->approved()->whereBetween('expense_date', [$previousWeekStart->toDateString(), $previousWeekEnd->toDateString()])->sum('amount');
            $thirtyDayTotal = (float) $this->ledgerExpenseQuery()->approved()->where('expense_date', '>=', $thirtyDaysAgo->toDateString())->sum('amount');

            $categoryDistribution = $this->categoryDistribution($thirtyDaysAgo);
            $cropCycleDistribution = $this->cropCycleDistribution($thirtyDaysAgo);
            $largestExpense = $this->ledgerExpenseQuery()
                ->approved()
                ->with(['category', 'cropCycle'])
                ->where('expense_date', '>=', $thirtyDaysAgo->toDateString())
                ->orderByDesc('amount')
                ->first();
            $dailyBudget = $this->activeDailyExpenseBudget();
            $weeklyBudget = $dailyBudget * 7;

            $data = [
                'todayExpenses' => $todayTotal,
                'todayCount' => $today->clone()->count(),
                'pendingToday' => $this->ledgerExpenseQuery()->pending()->where('expense_date', now()->toDateString())->count(),
                'weekExpenses' => $weekTotal,
                'weekCount' => $this->ledgerExpenseQuery()->approved()->where('expense_date', '>=', $weekStart->toDateString())->count(),
                'averageDaily' => round($thirtyDayTotal / 30, 2),
                'recentActivity' => $this->ledgerExpenseQuery()->where('created_at', '>=', now()->subDay())->count(),
                'lastExpenseTime' => optional($this->ledgerExpenseQuery()->latest('created_at')->first())->created_at?->toISOString(),
                'todayChangePercent' => $this->percentageChange($todayTotal, $yesterdayTotal),
                'weekChangePercent' => $this->percentageChange($weekTotal, $previousWeekTotal),
                'averageDailyTrend' => $this->trendDirection($weekTotal, $previousWeekTotal),
                'dailyBudget' => round($dailyBudget, 2),
                'weeklyBudget' => round($weeklyBudget, 2),
                'todaySeries' => $this->expenseSeries(now()->startOfDay(), 'hour'),
                'flowSeries' => [
                    '24h' => $this->expenseSeries(now()->subDay(), 'hour'),
                    '7d' => $this->expenseSeries(now()->subDays(6)->startOfDay(), 'day'),
                    '30d' => $this->expenseSeries(now()->subDays(29)->startOfDay(), 'day'),
                ],
                'categoryDistribution' => $categoryDistribution,
                'largestCategory' => $categoryDistribution[0] ?? null,
                'cropCycleDistribution' => $cropCycleDistribution,
                'largestExpense' => $largestExpense ? [
                    'id' => $largestExpense->id,
                    'description' => $largestExpense->description,
                    'amount' => (float) $largestExpense->amount,
                    'expenseDate' => optional($largestExpense->expense_date)->toDateString(),
                    'category' => $largestExpense->category?->name ?? 'Uncategorized',
                    'cropCycle' => $largestExpense->cropCycle?->crop_name,
                ] : null,
                'alerts' => $this->buildRealTimeAlerts($todayTotal, $dailyBudget),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load real-time expense metrics',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function realTimeActivity(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 100);
        $query = $this->ledgerExpenseQuery()->with(['category', 'creator', 'approver'])->latest('created_at');

        if ($request->query('since')) {
            $query->where('created_at', '>=', $request->query('since'));
        }

        $activity = $query->limit($limit)->get()->map(function (Expense $expense) {
            return [
                'id' => $expense->id,
                'type' => $expense->status === 'pending' ? 'approval-required' : 'expense-' . $expense->status,
                'message' => $expense->status === 'pending'
                    ? 'Expense requires approval: ' . $expense->description
                    : 'Expense recorded: ' . $expense->description,
                'user' => optional($expense->creator)->name ?? 'System',
                'amount' => (float) $expense->amount,
                'timestamp' => $expense->created_at?->toISOString(),
                'actionable' => $expense->status === 'pending',
                'expense' => $expense,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $activity,
        ]);
    }

    public function realTimeAlerts(Request $request): JsonResponse
    {
        $metrics = $this->buildRealTimeAlerts(
            (float) $this->ledgerExpenseQuery()->approved()->where('expense_date', now()->toDateString())->sum('amount'),
            $this->activeDailyExpenseBudget()
        );

        return response()->json([
            'success' => true,
            'data' => $metrics,
        ]);
    }

    public function dismissRealTimeAlert(Request $request, string $alertId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Alert dismissed successfully',
            'data' => ['alert_id' => $alertId],
        ]);
    }

    /**
     * Get expense analytics dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', '30_days');
            $categoryId = $request->query('category_id');

            // Get dashboard statistics
            $stats = Expense::getDashboardStats($period);

            // Get spending by category
            $categorySpending = ExpenseCategory::getSpendingByCategory($period);

            // Get top expenses
            $topExpenses = Expense::getTopExpenses(10, $period);

            // Get monthly trend
            $monthlyTrend = Expense::getMonthlyTrend(12);

            // Get payment method distribution
            $paymentMethods = Expense::getPaymentMethodDistribution();

            // Get pending approvals count
            $pendingApprovals = Expense::awaitingApproval()->count();

            // Get recent expenses
            $recentExpenses = Expense::with(['category', 'creator'])
                                    ->approved()
                                    ->recent(7)
                                    ->orderBy('created_at', 'desc')
                                    ->limit(10)
                                    ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $stats,
                    'category_spending' => $categorySpending,
                    'top_expenses' => $topExpenses,
                    'monthly_trend' => $monthlyTrend,
                    'payment_methods' => $paymentMethods,
                    'pending_approvals_count' => $pendingApprovals,
                    'recent_expenses' => $recentExpenses->take(10),
                    'period' => $period,
                    'generated_at' => now(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate expense dashboard',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    // Helper methods
    protected function normalizePeriod(?string $period): string
    {
        return match ($period) {
            'day', 'today' => 'today',
            'week', '7_days' => 'week',
            'month', '30_days' => '30_days',
            'year' => 'year',
            default => '30_days',
        };
    }

    protected function percentageChange(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    protected function trendDirection(float $current, float $previous): string
    {
        $change = $this->percentageChange($current, $previous);

        if ($change > 5) {
            return 'up';
        }

        if ($change < -5) {
            return 'down';
        }

        return 'stable';
    }

    protected function expenseSeries(Carbon $start, string $bucket): array
    {
        $expenses = $this->ledgerExpenseQuery()->approved()
            ->where('expense_date', '>=', $start->toDateString())
            ->get(['expense_date', 'expense_time', 'amount']);

        if ($bucket === 'hour') {
            $series = array_fill(0, 24, 0.0);

            foreach ($expenses as $expense) {
                $time = $expense->expense_time ?: '00:00:00';
                $hour = (int) Carbon::parse($expense->expense_date->toDateString() . ' ' . $time)->format('G');
                $series[$hour] += (float) $expense->amount;
            }

            return array_values(array_map(fn ($value) => round($value, 2), $series));
        }

        $days = max(1, $start->diffInDays(now()->startOfDay()) + 1);
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $series[$start->copy()->addDays($i)->toDateString()] = 0.0;
        }

        foreach ($expenses as $expense) {
            $key = $expense->expense_date->toDateString();
            if (array_key_exists($key, $series)) {
                $series[$key] += (float) $expense->amount;
            }
        }

        return array_values(array_map(fn ($value) => round($value, 2), $series));
    }

    protected function categoryDistribution(Carbon $start): array
    {
        $rows = $this->ledgerExpenseQuery()->approved()
            ->with('category')
            ->where('expense_date', '>=', $start->toDateString())
            ->get()
            ->groupBy('category_id')
            ->map(function ($expenses) {
                $category = $expenses->first()->category;

                return [
                    'name' => $category?->name ?? 'Uncategorized',
                    'amount' => (float) $expenses->sum('amount'),
                    'color' => $category?->color ?? '#6B7280',
                ];
            })
            ->values();

        $total = max((float) $rows->sum('amount'), 1.0);

        return $rows->map(fn ($row) => [
            'name' => $row['name'],
            'amount' => round($row['amount'], 2),
            'percentage' => round(($row['amount'] / $total) * 100, 1),
            'color' => $row['color'],
        ])->sortByDesc('amount')->values()->all();
    }

    protected function cropCycleDistribution(Carbon $start): array
    {
        return $this->ledgerExpenseQuery()
            ->approved()
            ->with('cropCycle')
            ->whereNotNull('crop_cycle_id')
            ->where('expense_date', '>=', $start->toDateString())
            ->get()
            ->groupBy('crop_cycle_id')
            ->map(function ($expenses) {
                $cropCycle = $expenses->first()->cropCycle;

                return [
                    'id' => $expenses->first()->crop_cycle_id,
                    'name' => $cropCycle?->crop_name ?? 'Unknown crop cycle',
                    'variety' => $cropCycle?->variety,
                    'amount' => round((float) $expenses->sum('amount'), 2),
                    'expenseCount' => $expenses->count(),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    protected function buildRealTimeAlerts(float $todayTotal, float $dailyBudget): array
    {
        $alerts = [];
        $pendingCount = $this->ledgerExpenseQuery()->pending()->count();

        if ($dailyBudget > 0) {
            $usage = ($todayTotal / $dailyBudget) * 100;

            if ($usage >= 100) {
                $alerts[] = [
                    'id' => 'daily-budget-exceeded',
                    'severity' => 'critical',
                    'title' => 'Daily budget exceeded',
                    'message' => 'Today\'s approved expenses are above the active daily budget.',
                    'timestamp' => now()->toISOString(),
                    'actionable' => true,
                    'actionText' => 'Review Budget',
                ];
            } elseif ($usage >= 80) {
                $alerts[] = [
                    'id' => 'daily-budget-warning',
                    'severity' => 'warning',
                    'title' => 'Daily budget warning',
                    'message' => 'Today\'s approved expenses have reached ' . round($usage) . '% of the active daily budget.',
                    'timestamp' => now()->toISOString(),
                    'actionable' => true,
                    'actionText' => 'Review Budget',
                ];
            }
        }

        if ($pendingCount > 0) {
            $alerts[] = [
                'id' => 'pending-approvals',
                'severity' => 'info',
                'title' => 'Pending approvals',
                'message' => $pendingCount . ' expense' . ($pendingCount === 1 ? '' : 's') . ' waiting for approval.',
                'timestamp' => now()->toISOString(),
                'actionable' => true,
                'actionText' => 'Review',
            ];
        }

        return $alerts;
    }

    protected function activeDailyExpenseBudget(): float
    {
        $monthlyCategoryBudget = (float) ExpenseCategory::active()
            ->whereNotNull('monthly_budget')
            ->sum('monthly_budget');

        if ($monthlyCategoryBudget > 0) {
            return $monthlyCategoryBudget / now()->daysInMonth;
        }

        return (float) DB::table('expense_budgets')
            ->where('status', 'active')
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date', '>=', now()->toDateString())
            ->sum(DB::raw('total_budget / GREATEST((end_date - start_date + 1), 1)'));
    }

    protected function ledgerExpenseQuery()
    {
        return Expense::query()
            ->whereNull('metadata->template')
            ->whereNull('metadata->validation_rule')
            ->whereNull('metadata->validation_settings')
            ->whereNull('metadata->import_export_job')
            ->whereNull('metadata->notification_state')
            ->whereNull('metadata->notification_settings')
            ->whereNull('metadata->duplicate_settings')
            ->whereNull('metadata->duplicate_resolution->archived');
    }

    protected function receiptCoverage(): float
    {
        $total = Expense::count();
        if ($total === 0) {
            return 100.0;
        }

        $withReceipts = Expense::whereNotNull('receipt_photos')->count();

        return round(($withReceipts / $total) * 100, 2);
    }

    protected function resolveExpenseCategory(Request $request, bool $required = true): ?ExpenseCategory
    {
        if ($request->filled('category_id')) {
            return ExpenseCategory::active()->find($request->category_id);
        }

        if ($request->filled('category')) {
            return ExpenseCategory::active()
                ->where(function ($query) use ($request) {
                    $query->where('name', $request->category)
                        ->orWhere('slug', \Illuminate\Support\Str::slug($request->category));
                })
                ->first();
        }

        return $required ? null : null;
    }

    protected function buildExpenseCsv($expenses): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Date', 'Description', 'Category', 'Amount', 'Status', 'Vendor', 'Payment Method', 'Created By']);

        foreach ($expenses as $expense) {
            fputcsv($handle, [
                optional($expense->expense_date)->toDateString(),
                $expense->description,
                $expense->category?->name,
                (float) $expense->amount,
                $expense->status,
                $expense->supplier_name ?: $expense->paid_to,
                $expense->payment_method,
                $expense->creator?->name,
            ]);
        }

        rewind($handle);
        return stream_get_contents($handle);
    }

    protected function parseExpenseImportCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return [];
        }

        $headers = array_map(fn ($header) => \Illuminate\Support\Str::snake(strtolower(trim($header))), $headers);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($values[$index] ?? ''));
            }

            $rows[] = [
                'date' => $row['date'] ?? $row['expense_date'] ?? null,
                'description' => $row['description'] ?? null,
                'category' => $row['category'] ?? null,
                'amount' => $row['amount'] ?? null,
                'vendor' => $row['vendor'] ?? $row['supplier_name'] ?? null,
                'notes' => $row['notes'] ?? null,
            ];
        }

        fclose($handle);
        return $rows;
    }

    protected function recordImportExportJob(Request $request, array $job): ?Expense
    {
        $category = ExpenseCategory::active()->first();
        if (!$category || !$request->user()) {
            return null;
        }

        return Expense::create([
            'expense_date' => now()->toDateString(),
            'expense_time' => '12:00:00',
            'amount' => 0.01,
            'description' => $job['title'] ?? 'Expense import/export job',
            'category_id' => $category->id,
            'created_by' => $request->user()->id,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'status' => 'draft',
            'payment_method' => 'cash',
            'is_planned' => true,
            'metadata' => [
                'import_export_job' => array_merge($job, [
                    'createdAt' => now()->toISOString(),
                ]),
            ],
        ]);
    }

    protected function formatImportExportJob(Expense $row): array
    {
        $metadata = $row->metadata ?: [];
        $job = $metadata['import_export_job'] ?? [];

        return [
            'id' => $row->id,
            'type' => $job['type'] ?? 'export',
            'title' => $job['title'] ?? $row->description,
            'description' => $job['description'] ?? '',
            'status' => $job['status'] ?? 'completed',
            'recordCount' => $job['record_count'] ?? 0,
            'errorCount' => $job['error_count'] ?? 0,
            'format' => $job['format'] ?? null,
            'filename' => $job['filename'] ?? null,
            'errors' => $job['errors'] ?? [],
            'createdAt' => $job['createdAt'] ?? $row->created_at,
        ];
    }

    protected function buildExpenseNotifications(Request $request): array
    {
        $notifications = [];

        Expense::with('category')
            ->pending()
            ->whereNull('metadata->template')
            ->whereNull('metadata->validation_rule')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->each(function (Expense $expense) use (&$notifications) {
                $ageHours = $expense->created_at ? $expense->created_at->diffInHours(now()) : 0;
                $notifications[] = [
                    'id' => 'approval-' . $expense->id,
                    'type' => $ageHours >= 48 ? 'overdue_approval' : 'approval_request',
                    'title' => $ageHours >= 48 ? 'Overdue Approval' : 'Expense Approval Required',
                    'message' => $ageHours >= 48 ? 'Expense approval is overdue.' : 'New expense submission needs approval.',
                    'priority' => $ageHours >= 48 ? 'high' : ((float) $expense->amount >= 50000 ? 'urgent' : 'high'),
                    'read' => false,
                    'createdAt' => $expense->created_at?->toISOString(),
                    'expense' => [
                        'id' => $expense->id,
                        'description' => $expense->description,
                        'amount' => (float) $expense->amount,
                        'date' => optional($expense->expense_date)->toDateString(),
                        'category' => $expense->category?->name ?? 'Uncategorized',
                    ],
                    'actions' => [
                        ['id' => 'approve', 'label' => 'Approve', 'style' => 'primary', 'handler' => 'approve'],
                        ['id' => 'reject', 'label' => 'Reject', 'style' => 'secondary', 'handler' => 'reject'],
                        ['id' => 'view', 'label' => 'View Details', 'style' => 'secondary', 'handler' => 'view'],
                    ],
                ];
            });

        ExpenseCategory::active()
            ->withBudget()
            ->get()
            ->each(function (ExpenseCategory $category) use (&$notifications) {
                $budget = (float) ($category->monthly_budget ?: $category->seasonal_budget);
                if ($budget <= 0) {
                    return;
                }

                $spent = (float) $category->expenses()->approved()->sum('amount');
                $usage = ($spent / $budget) * 100;

                if ($usage < 80) {
                    return;
                }

                $notifications[] = [
                    'id' => 'budget-' . $category->id,
                    'type' => 'budget_alert',
                    'title' => $usage >= 100 ? 'Budget Exceeded' : 'Budget Threshold Reached',
                    'message' => $category->name . ' category has used ' . round($usage) . '% of its budget.',
                    'priority' => $usage >= 100 ? 'urgent' : 'high',
                    'read' => false,
                    'createdAt' => now()->toISOString(),
                    'actions' => [
                        ['id' => 'view_budget', 'label' => 'View Budget', 'style' => 'primary', 'handler' => 'viewBudget'],
                    ],
                ];
            });

        Expense::with('category')
            ->whereIn('status', ['approved', 'rejected'])
            ->where('updated_at', '>=', now()->subDays(7))
            ->whereNull('metadata->template')
            ->whereNull('metadata->validation_rule')
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->each(function (Expense $expense) use (&$notifications) {
                $notifications[] = [
                    'id' => $expense->status . '-' . $expense->id,
                    'type' => $expense->status === 'approved' ? 'expense_approved' : 'expense_rejected',
                    'title' => $expense->status === 'approved' ? 'Expense Approved' : 'Expense Rejected',
                    'message' => $expense->description . ' has been ' . $expense->status . '.',
                    'priority' => 'medium',
                    'read' => false,
                    'createdAt' => $expense->updated_at?->toISOString(),
                    'expense' => [
                        'id' => $expense->id,
                        'description' => $expense->description,
                        'amount' => (float) $expense->amount,
                        'date' => optional($expense->expense_date)->toDateString(),
                        'category' => $expense->category?->name ?? 'Uncategorized',
                    ],
                ];
            });

        return collect($notifications)
            ->sortByDesc(fn ($notification) => $notification['createdAt'])
            ->values()
            ->all();
    }

    protected function notificationStateData(): array
    {
        $row = Expense::query()
            ->where('is_planned', true)
            ->whereNotNull('metadata->notification_state')
            ->first();
        $metadata = $row?->metadata ?: [];

        return array_merge(['read' => [], 'dismissed' => []], $metadata['notification_state'] ?? []);
    }

    protected function saveNotificationState(Request $request, array $state): void
    {
        $this->saveInternalMetadataRow($request, 'notification_state', '__expense_notification_state__', $state);
    }

    protected function notificationSettingsData(): array
    {
        $row = Expense::query()
            ->where('is_planned', true)
            ->whereNotNull('metadata->notification_settings')
            ->first();
        $metadata = $row?->metadata ?: [];

        return array_merge($this->defaultNotificationSettings(), $metadata['notification_settings'] ?? []);
    }

    protected function saveNotificationSettings(Request $request, array $settings): void
    {
        $this->saveInternalMetadataRow($request, 'notification_settings', '__expense_notification_settings__', $settings);
    }

    protected function defaultNotificationSettings(): array
    {
        return [
            'email' => [
                'approvalRequests' => true,
                'budgetAlerts' => true,
                'expenseApproved' => false,
                'expenseRejected' => true,
                'dailyDigest' => true,
                'weeklyReport' => false,
            ],
            'push' => [
                'approvalRequests' => true,
                'budgetAlerts' => true,
                'expenseApproved' => false,
                'expenseRejected' => true,
                'urgentOnly' => false,
            ],
            'dailyDigestTime' => '08:00',
            'reminderFrequency' => 'daily',
            'budgetThreshold' => 80,
            'quietHours' => [
                'enabled' => false,
                'start' => '22:00',
                'end' => '07:00',
            ],
        ];
    }

    protected function saveInternalMetadataRow(Request $request, string $key, string $description, array $data): void
    {
        $row = Expense::query()
            ->where('is_planned', true)
            ->whereNotNull('metadata->' . $key)
            ->first();
        $metadata = $row?->metadata ?: [];
        $metadata[$key] = $data;

        if ($row) {
            $row->update(['metadata' => $metadata]);
            return;
        }

        $category = ExpenseCategory::active()->first();
        if (!$category || !$request->user()) {
            return;
        }

        Expense::create([
            'expense_date' => now()->toDateString(),
            'expense_time' => '12:00:00',
            'amount' => 0.01,
            'description' => $description,
            'category_id' => $category->id,
            'created_by' => $request->user()->id,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'status' => 'draft',
            'payment_method' => 'cash',
            'is_planned' => true,
            'metadata' => $metadata,
        ]);
    }

    protected function normalizePublicStoragePath(string $fileUrl): ?string
    {
        $path = parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl;
        $path = ltrim($path, '/');

        foreach (['storage/', 'public/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }

        return $path ?: null;
    }

    protected function buildDuplicateGroups($expenses, int $threshold, int $dateRange): array
    {
        $groups = [];
        $used = [];
        $expenses = $expenses->values();

        foreach ($expenses as $expense) {
            if (isset($used[$expense->id])) {
                continue;
            }

            $matches = [[
                'expense' => $expense,
                'score' => 100,
                'fields' => ['amount', 'description', 'date', 'category'],
                'rules' => ['Primary'],
            ]];

            foreach ($expenses as $candidate) {
                if ($candidate->id === $expense->id || isset($used[$candidate->id])) {
                    continue;
                }

                $match = $this->scoreDuplicateExpense($expense, $candidate, $dateRange);
                if ($match['score'] >= $threshold) {
                    $matches[] = [
                        'expense' => $candidate,
                        'score' => $match['score'],
                        'fields' => $match['fields'],
                        'rules' => $match['rules'],
                    ];
                }
            }

            if (count($matches) < 2) {
                continue;
            }

            foreach ($matches as $match) {
                $used[$match['expense']->id] = true;
            }

            usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);
            $primary = $matches[0]['expense'];
            $confidence = min(100, (int) round(collect($matches)->avg('score')));
            $matchedRules = collect($matches)->flatMap(fn ($match) => $match['rules'])->unique()->values()->all();
            $expensesPayload = collect($matches)
                ->map(fn ($match) => $this->formatDuplicateExpense($match['expense'], $match['score'], $match['fields']))
                ->values()
                ->all();

            $groups[] = [
                'id' => $this->duplicateGroupId(collect($matches)->pluck('expense.id')->all()),
                'primaryExpense' => $this->formatDuplicateExpense($primary, 100, $matches[0]['fields']),
                'expenses' => $expensesPayload,
                'confidence' => $confidence,
                'status' => $this->duplicateGroupStatus(collect($matches)->pluck('expense')->all()),
                'matchedRules' => $matchedRules,
                'totalAmount' => round((float) collect($matches)->sum(fn ($match) => (float) $match['expense']->amount), 2),
                'expanded' => false,
                'selectedToKeep' => $primary->id,
                'resolutionAction' => 'delete',
            ];
        }

        usort($groups, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return array_slice($groups, 0, 20);
    }

    protected function scoreDuplicateExpense(Expense $base, Expense $candidate, int $dateRange): array
    {
        $score = 0;
        $fields = [];
        $rules = [];
        $baseAmount = max((float) $base->amount, 0.01);
        $amountDifference = abs((float) $base->amount - (float) $candidate->amount) / $baseAmount;

        if ($amountDifference <= 0.01) {
            $score += 40;
            $fields[] = 'amount';
        } elseif ($amountDifference <= 0.05) {
            $score += 28;
            $fields[] = 'amount';
        }

        $dateDifference = abs(Carbon::parse($base->expense_date)->diffInDays(Carbon::parse($candidate->expense_date)));
        if ($dateDifference === 0) {
            $score += 20;
            $fields[] = 'date';
        } elseif ($dateDifference <= $dateRange) {
            $score += max(5, 18 - $dateDifference);
            $fields[] = 'date';
        }

        similar_text(mb_strtolower($base->description), mb_strtolower($candidate->description), $descriptionSimilarity);
        if ($descriptionSimilarity >= 90) {
            $score += 25;
            $fields[] = 'description';
        } elseif ($descriptionSimilarity >= 65) {
            $score += 15;
            $fields[] = 'description';
        }

        if ($base->category_id && $base->category_id === $candidate->category_id) {
            $score += 10;
            $fields[] = 'category';
        }

        if ($base->supplier_name && $candidate->supplier_name && mb_strtolower($base->supplier_name) === mb_strtolower($candidate->supplier_name)) {
            $score += 10;
            $fields[] = 'vendor';
        }

        if ($base->payment_reference && $base->payment_reference === $candidate->payment_reference) {
            $score += 15;
            $fields[] = 'payment_reference';
        }

        if (in_array('amount', $fields, true) && in_array('date', $fields, true) && in_array('description', $fields, true)) {
            $rules[] = 'Exact Match';
        }
        if (in_array('amount', $fields, true) && in_array('date', $fields, true)) {
            $rules[] = 'Similar Amount & Date';
        }
        if (in_array('vendor', $fields, true) && in_array('amount', $fields, true)) {
            $rules[] = 'Same Vendor & Amount';
        }
        if (in_array('description', $fields, true)) {
            $rules[] = 'Fuzzy Description Match';
        }

        return [
            'score' => min(100, $score),
            'fields' => array_values(array_unique($fields)),
            'rules' => array_values(array_unique($rules ?: ['Similarity Match'])),
        ];
    }

    protected function formatDuplicateExpense(Expense $expense, int $score, array $fields): array
    {
        return [
            'id' => $expense->id,
            'description' => $expense->description,
            'amount' => (float) $expense->amount,
            'date' => optional($expense->expense_date)->toDateString(),
            'category' => $expense->category->name ?? 'Uncategorized',
            'vendor' => $expense->supplier_name ?: $expense->paid_to,
            'receiptUrl' => $expense->receipt_photos[0] ?? null,
            'similarityScore' => $score,
            'matchingFields' => array_values(array_unique($fields)),
        ];
    }

    protected function duplicateGroupId(array $ids): string
    {
        sort($ids);
        return 'dup-' . substr(sha1(implode('|', $ids)), 0, 16);
    }

    protected function duplicateGroupStatus(array $expenses): string
    {
        $statuses = collect($expenses)
            ->map(fn (Expense $expense) => $expense->metadata['duplicate_resolution']['status'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($statuses->contains('kept') || $statuses->contains('delete') || $statuses->contains('merge') || $statuses->contains('archive')) {
            return 'resolved';
        }

        if ($statuses->contains('dismissed')) {
            return 'dismissed';
        }

        return 'unresolved';
    }

    protected function markDuplicateResolution(Expense $expense, Request $request, string $status, string $keepId): void
    {
        $metadata = $expense->metadata ?: [];
        $metadata['duplicate_resolution'] = array_merge($metadata['duplicate_resolution'] ?? [], [
            'status' => $status,
            'group_id' => $request->input('group_id'),
            'keep_id' => $keepId,
            'resolved_by' => $request->user()?->id,
            'resolved_at' => now()->toISOString(),
        ]);
        $expense->update(['metadata' => $metadata]);
    }

    protected function duplicateDetectionSettings(): array
    {
        $row = Expense::query()
            ->where('is_planned', true)
            ->whereNotNull('metadata->duplicate_settings')
            ->first();
        $metadata = $row?->metadata ?: [];

        return array_replace_recursive($this->defaultDuplicateDetectionSettings(), $metadata['duplicate_settings'] ?? []);
    }

    protected function defaultDuplicateDetectionSettings(): array
    {
        return [
            'detectionSettings' => [
                'sensitivity' => 80,
                'autoScan' => true,
                'autoResolveHighConfidence' => false,
                'notifyOnDuplicates' => true,
                'timeWindow' => 7,
            ],
            'fieldWeights' => [
                ['name' => 'amount', 'label' => 'Amount', 'weight' => 90],
                ['name' => 'description', 'label' => 'Description', 'weight' => 70],
                ['name' => 'vendor', 'label' => 'Vendor', 'weight' => 60],
                ['name' => 'date', 'label' => 'Date', 'weight' => 50],
                ['name' => 'category', 'label' => 'Category', 'weight' => 40],
            ],
            'rules' => [
                ['id' => 'exact-match', 'name' => 'Exact Match', 'enabled' => true, 'sensitivity' => 100],
                ['id' => 'similar-amount-date', 'name' => 'Similar Amount & Date', 'enabled' => true, 'sensitivity' => 85],
                ['id' => 'same-vendor-amount', 'name' => 'Same Vendor & Amount', 'enabled' => true, 'sensitivity' => 80],
                ['id' => 'fuzzy-description', 'name' => 'Fuzzy Description Match', 'enabled' => false, 'sensitivity' => 70],
            ],
        ];
    }

    protected function extractReceiptText(string $absolutePath, ?string $mimeType): string
    {
        if ($mimeType === 'text/plain') {
            return trim((string) file_get_contents($absolutePath));
        }

        if ($mimeType === 'application/pdf' && $this->commandExists('pdftotext')) {
            $output = shell_exec('pdftotext -layout ' . escapeshellarg($absolutePath) . ' - 2>/dev/null');
            return trim((string) $output);
        }

        if (str_starts_with((string) $mimeType, 'image/') && $this->commandExists('tesseract')) {
            $output = shell_exec('tesseract ' . escapeshellarg($absolutePath) . ' stdout 2>/dev/null');
            return trim((string) $output);
        }

        return '';
    }

    protected function extractReceiptFields(string $text, string $filename): array
    {
        $source = trim($text) !== '' ? $text : str_replace(['_', '-'], ' ', pathinfo($filename, PATHINFO_FILENAME));
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $source) ?: [])));

        return [
            'amount' => $this->extractReceiptAmount($source),
            'vendor' => $this->extractReceiptVendor($lines),
            'date' => $this->extractReceiptDate($source),
            'receipt_number' => $this->extractReceiptNumber($source),
        ];
    }

    protected function extractReceiptAmount(string $text): ?float
    {
        $patterns = [
            '/(?:total|amount|paid|balance due)\s*[:\-]?\s*(?:kes|ksh|usd)?\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{1,2})?|[0-9]+(?:\.[0-9]{1,2})?)/i',
            '/(?:kes|ksh|usd)\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{1,2})?|[0-9]+(?:\.[0-9]{1,2})?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return round((float) str_replace(',', '', $matches[1]), 2);
            }
        }

        return null;
    }

    protected function extractReceiptDate(string $text): ?string
    {
        $patterns = [
            '/\b([0-9]{4}[-\/.][0-9]{1,2}[-\/.][0-9]{1,2})\b/',
            '/\b([0-9]{1,2}[-\/.][0-9]{1,2}[-\/.][0-9]{2,4})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                try {
                    return Carbon::parse(str_replace('.', '-', $matches[1]))->toDateString();
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }

    protected function extractReceiptVendor(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (strlen($line) >= 3 && !preg_match('/(receipt|invoice|total|amount|date|tax|vat)/i', $line)) {
                return mb_substr($line, 0, 100);
            }
        }

        return null;
    }

    protected function extractReceiptNumber(string $text): ?string
    {
        if (preg_match('/(?:receipt|invoice|ref|reference|no\.?)\s*[:#-]?\s*([a-z0-9\-\/]+)/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    protected function receiptConfidence(array $fields, bool $hasExtractedText, bool $hasOcrEngine): float
    {
        $confidence = $hasOcrEngine ? 0.35 : 0.1;
        $confidence += $hasExtractedText ? 0.2 : 0;
        $confidence += $fields['amount'] ? 0.2 : 0;
        $confidence += $fields['date'] ? 0.15 : 0;
        $confidence += $fields['vendor'] ? 0.1 : 0;

        return min(0.95, round($confidence, 2));
    }

    protected function availableOcrEngine(?string $mimeType): ?string
    {
        if ($mimeType === 'application/pdf' && $this->commandExists('pdftotext')) {
            return 'pdftotext';
        }

        if (str_starts_with((string) $mimeType, 'image/') && $this->commandExists('tesseract')) {
            return 'tesseract';
        }

        return null;
    }

    protected function commandExists(string $command): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }

        return trim((string) shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null')) !== '';
    }

    protected function requiresApproval($user, $amount)
    {
        $farmId = $this->currentFarmId();

        // Farm owners don't need approval
        if ($farmId && $user->ownsFarm($farmId)) {
            return false;
        }

        // Managers can auto-approve under certain limits
        if ($farmId && $user->getRoleOnFarm($farmId) === 'manager') {
            return $amount > 5000; // KES 5,000 limit for managers
        }

        // Workers always need approval
        return true;
    }

    protected function canApproveExpense($user, $expense)
    {
        $farmId = $this->currentFarmId();

        // Cannot approve own expense
        if ($user->id === $expense->created_by) {
            return false;
        }

        // Farm owners and managers can approve
        return $farmId && ($user->ownsFarm($farmId) || $user->getRoleOnFarm($farmId) === 'manager');
    }

    protected function canManageExpenseApprovals(Request $request): bool
    {
        $farmId = $this->currentFarmId();
        $role = $farmId ? $request->user()?->getRoleOnFarm($farmId) : null;

        return in_array($role, ['owner', 'manager'], true);
    }

    protected function expenseApprovalForbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Only farm owners and managers can review expense approvals',
        ], 403);
    }

    protected function currentFarmId(): ?string
    {
        return request()->header('X-Tenant-ID')
            ?: request()->header('X-Farm-ID')
            ?: request()->input('farm_id')
            ?: request()->input('farmId');
    }

    protected function expenseValuesDiffer(mixed $newValue, mixed $oldValue): bool
    {
        if (is_array($newValue) || is_array($oldValue)) {
            return json_encode($newValue) !== json_encode($oldValue);
        }

        return (string) $newValue !== (string) $oldValue;
    }
}
