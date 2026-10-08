<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ExpenseCategoryController extends Controller
{
    /**
     * Get all expense categories
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $includeInactive = $request->query('include_inactive', false);
            $withStats = $request->query('with_stats', false);

            $query = ExpenseCategory::with(['creator']);

            if (!$includeInactive) {
                $query->active();
            }

            if ($withStats) {
                $query->withCount('expenses');
            }

            $categories = $query->orderBy('name', 'asc')->get();

            // Transform categories with additional data
            $categories->transform(function ($category) use ($withStats) {
                $data = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'color' => $category->color,
                    'icon' => $category->icon,
                    'is_system_default' => $category->is_system_default,
                    'is_active' => $category->is_active,
                    'monthly_budget' => $category->monthly_budget,
                    'seasonal_budget' => $category->seasonal_budget,
                    'total_spent' => $category->total_spent,
                    'expense_count' => $category->expense_count,
                    'last_used_date' => $category->last_used_date,
                    'budget_utilization' => $category->budget_utilization,
                    'remaining_budget' => $category->remaining_budget,
                    'is_over_budget' => $category->is_over_budget,
                    'average_expense_amount' => $category->average_expense_amount,
                    'days_since_last_used' => $category->days_since_last_used,
                    'created_by' => [
                        'id' => $category->creator->id,
                        'name' => $category->creator->name,
                    ],
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ];

                if ($withStats && isset($category->expenses_count)) {
                    $data['expenses_count'] = $category->expenses_count;
                }

                return $data;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'categories' => $categories,
                    'total' => $categories->count(),
                    'system_defaults' => $categories->where('is_system_default', true)->count(),
                    'custom' => $categories->where('is_system_default', false)->count(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense categories',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a new expense category
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->canManageCategories($request)) {
            return $this->categoryManagementForbidden();
        }

        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:100|unique:expense_categories,name',
                'description' => 'nullable|string|max:1000',
                'color' => 'nullable|string|regex:/^#[0-9A-F]{6}$/i',
                'icon' => 'nullable|string|max:50',
                'monthly_budget' => 'nullable|numeric|min:0|max:10000000',
                'seasonal_budget' => 'nullable|numeric|min:0|max:100000000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $categoryData = $validator->validated();
            $categoryData['created_by'] = $request->user()->id;
            $categoryData['color'] = $categoryData['color'] ?? '#6B7280';
            $categoryData['is_system_default'] = false; // Custom categories are never system defaults

            $category = ExpenseCategory::create($categoryData);

            return response()->json([
                'success' => true,
                'message' => 'Expense category created successfully',
                'data' => [
                    'category' => $category->load('creator')
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category creation failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get specific category details
     */
    public function show(Request $request, string $categoryId): JsonResponse
    {
        try {
            $category = ExpenseCategory::with(['creator', 'expenses' => function($query) {
                $query->approved()->orderBy('expense_date', 'desc')->limit(10);
            }])->findOrFail($categoryId);

            // Get spending trend for last 12 months
            $monthlySpending = $category->getMonthlySpending();

            // Get recent expenses trend (last 30 days)
            $recentTrend = $category->getExpensesTrend(30);

            return response()->json([
                'success' => true,
                'data' => [
                    'category' => $category,
                    'monthly_spending' => $monthlySpending,
                    'recent_trend' => $recentTrend,
                    'budget_alert' => $category->checkBudgetAlert(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve category details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update category details
     */
    public function update(Request $request, string $categoryId): JsonResponse
    {
        if (!$this->canManageCategories($request)) {
            return $this->categoryManagementForbidden();
        }

        try {
            $category = ExpenseCategory::findOrFail($categoryId);

            // System default categories cannot be renamed but can have budgets updated
            $rules = [
                'description' => 'sometimes|nullable|string|max:1000',
                'color' => 'sometimes|string|regex:/^#[0-9A-F]{6}$/i',
                'icon' => 'sometimes|nullable|string|max:50',
                'monthly_budget' => 'sometimes|nullable|numeric|min:0|max:10000000',
                'seasonal_budget' => 'sometimes|nullable|numeric|min:0|max:100000000',
                'is_active' => 'sometimes|boolean',
            ];

            if (!$category->is_system_default) {
                $rules['name'] = 'sometimes|string|max:100|unique:expense_categories,name,' . $categoryId;
            }

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => [
                    'category' => $category->load('creator')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Delete category
     */
    public function destroy(Request $request, string $categoryId): JsonResponse
    {
        if (!$this->canManageCategories($request)) {
            return $this->categoryManagementForbidden();
        }

        try {
            $category = ExpenseCategory::findOrFail($categoryId);

            if (!$category->canBeDeleted()) {
                $reason = $category->is_system_default ? 
                         'System default categories cannot be deleted' :
                         'Categories with existing expenses cannot be deleted';
                
                return response()->json([
                    'success' => false,
                    'message' => $reason
                ], 403);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Category deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Category deletion failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Set category budget
     */
    public function setBudget(Request $request, string $categoryId): JsonResponse
    {
        if (!$this->canManageCategories($request)) {
            return $this->categoryManagementForbidden();
        }

        try {
            $category = ExpenseCategory::findOrFail($categoryId);

            $validator = Validator::make($request->all(), [
                'budget_type' => 'sometimes|string|in:monthly,seasonal',
                'amount' => 'required_without:budget|numeric|min:0|max:100000000',
                'budget' => 'required_without:amount|numeric|min:0|max:100000000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $budgetType = $request->input('budget_type', 'monthly');
            $amount = $request->input('amount', $request->input('budget'));

            $category->setBudget($amount, $budgetType);

            return response()->json([
                'success' => true,
                'message' => ucfirst($budgetType) . ' budget set successfully',
                'data' => [
                    'category' => $category
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Budget setting failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get category budget summary.
     */
    public function budgets(Request $request): JsonResponse
    {
        $categories = ExpenseCategory::active()
            ->orderBy('name')
            ->get()
            ->map(function (ExpenseCategory $category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'monthly_budget' => (float) ($category->monthly_budget ?? 0),
                    'seasonal_budget' => (float) ($category->seasonal_budget ?? 0),
                    'total_spent' => (float) ($category->total_spent ?? 0),
                    'budget_utilization' => $category->budget_utilization,
                    'remaining_budget' => $category->remaining_budget,
                    'is_over_budget' => $category->is_over_budget,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'budgets' => $categories,
                'total_monthly_budget' => round($categories->sum('monthly_budget'), 2),
                'total_seasonal_budget' => round($categories->sum('seasonal_budget'), 2),
            ],
        ]);
    }

    /**
     * Get category analytics
     */
    public function analytics(Request $request, string $categoryId): JsonResponse
    {
        try {
            $category = ExpenseCategory::findOrFail($categoryId);
            $period = $request->query('period', 'year');

            // Get spending trend
            $trend = $category->getExpensesTrend($period === 'year' ? 365 : 30);

            // Get monthly spending for the year
            $monthlySpending = $category->getMonthlySpending();

            // Get expense distribution by payment method
            $paymentMethods = $category->expenses()
                                     ->approved()
                                     ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                                     ->groupBy('payment_method')
                                     ->orderBy('total', 'desc')
                                     ->get();

            // Get recent large expenses
            $largeExpenses = $category->expenses()
                                    ->approved()
                                    ->where('amount', '>', $category->average_expense_amount * 2)
                                    ->orderBy('amount', 'desc')
                                    ->limit(10)
                                    ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'category' => $category,
                    'trend' => $trend,
                    'monthly_spending' => $monthlySpending,
                    'payment_methods' => $paymentMethods,
                    'large_expenses' => $largeExpenses,
                    'budget_alert' => $category->checkBudgetAlert(),
                    'generated_at' => now(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate category analytics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get most used categories
     */
    public function mostUsed(Request $request): JsonResponse
    {
        try {
            $limit = $request->query('limit', 10);
            $period = $request->query('period', '30_days');

            $categories = ExpenseCategory::getMostUsedCategories($limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'categories' => $categories,
                    'period' => $period,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve most used categories',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Initialize system default categories for new farm
     */
    public function initializeDefaults(Request $request): JsonResponse
    {
        if (!$this->canManageCategories($request)) {
            return $this->categoryManagementForbidden();
        }

        try {
            $user = $request->user();

            // Check if defaults already exist
            $existingDefaults = ExpenseCategory::systemDefaults()->count();
            
            if ($existingDefaults > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Default categories already exist for this farm'
                ], 409);
            }

            DB::beginTransaction();

            ExpenseCategory::createSystemDefaults($user->id);

            DB::commit();

            $categories = ExpenseCategory::systemDefaults()->get();

            return response()->json([
                'success' => true,
                'message' => 'Default expense categories created successfully',
                'data' => [
                    'categories' => $categories,
                    'count' => $categories->count(),
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create default categories',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    protected function canManageCategories(Request $request): bool
    {
        $farmId = $request->header('X-Tenant-ID')
            ?: $request->header('X-Farm-ID')
            ?: $request->input('farm_id')
            ?: $request->input('farmId');
        $role = $farmId ? $request->user()?->getRoleOnFarm($farmId) : null;

        return in_array($role, ['owner', 'manager'], true);
    }

    protected function categoryManagementForbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Only farm owners and managers can manage expense categories.',
        ], 403);
    }
}
