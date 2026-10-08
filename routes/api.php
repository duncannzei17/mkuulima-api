<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FarmController;
use App\Http\Controllers\FarmAnalyticsController;
use App\Http\Controllers\WeatherController;
use App\Http\Controllers\WeatherAlertController;
use App\Http\Controllers\CropController;
use App\Http\Controllers\CropTaskController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\LabourEntryController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\InventoryAlertController;
use App\Http\Controllers\HarvestController;
use App\Http\Controllers\HarvestNoteController;
use App\Http\Controllers\HarvestSalesAllocationController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\PriceHistoryController;
use App\Http\Controllers\SalesApprovalController;
use App\Http\Controllers\ProfitabilityController;
use App\Http\Controllers\ProfitabilityInsightController;
use App\Http\Controllers\FarmPerformanceBenchmarkController;
use App\Http\Controllers\FieldObservationController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\MarketInsightController;
use App\Http\Controllers\SellOrderController;
use App\Http\Controllers\LogisticsController;
use App\Http\Controllers\BedController;
use App\Http\Controllers\BedCropAssignmentController;
use App\Http\Controllers\BedNoteController;
use App\Http\Controllers\IrrigationController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\PendingPaymentController;
use App\Http\Controllers\FarmWalletController;
use App\Http\Controllers\MpesaController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| API Routes - farmOS Modules 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11 & 12
|--------------------------------------------------------------------------
|
| Module 1: Farm Identity & Registration
| Module 2: Crop Production Planning
| Module 3: Expense Ledger
| Module 4: Labour Management
| Module 5: Inventory Management
| Module 6: Harvest Tracking
| Module 7: Sales & Income Tracking
| Module 8: Profitability Engine
| Module 9: Field Observations & Insights
| Module 10: Market Linkage & Price Intelligence
| Module 11: Plot/Bed Mapping
| Module 12: Financial Transactions & Cashflow (M-Pesa Integrated)
| All routes except register/login require authenticated user.
|
*/

// Health check endpoint
Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

/*
|--------------------------------------------------------------------------
| Authentication Routes (Public)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('/check-phone', [AuthController::class, 'checkPhone'])->middleware('throttle:auth');
    Route::post('/refresh', [AuthController::class, 'refreshToken'])->middleware('throttle:token-refresh');
    
    // Protected auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
    });
});

/*
|--------------------------------------------------------------------------
| Public Options Routes (for registration/form dropdowns)
|--------------------------------------------------------------------------
*/
// Location data for Kenya - public for registration forms
Route::get('/options/counties', function () {
    $counties = [
        'Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet', 'Embu', 'Garissa', 
        'Homa Bay', 'Isiolo', 'Kajiado', 'Kakamega', 'Kericho', 'Kiambu', 'Kilifi', 
        'Kirinyaga', 'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu', 'Machakos', 
        'Makueni', 'Mandera', 'Marsabit', 'Meru', 'Migori', 'Mombasa', 'Murang\'a', 
        'Nairobi', 'Nakuru', 'Nandi', 'Narok', 'Nyamira', 'Nyandarua', 'Nyeri', 
        'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River', 'Tharaka-Nithi', 'Trans Nzoia', 
        'Turkana', 'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot'
    ];

    return response()->json([
        'success' => true,
        'data' => collect($counties)->map(function ($county) {
            return ['value' => $county, 'label' => $county];
        })->values()
    ]);
});

Route::get('/options/wards', function (Request $request) {
    $county = $request->query('county');
    if (!$county) {
        return response()->json([
            'success' => false,
            'message' => 'County parameter is required'
        ], 400);
    }

    // Sample ward data for major farming counties
    $wardsByCounty = [
        'Kiambu' => ['Githunguri', 'Kiambu', 'Kikuyu', 'Limuru', 'Lari', 'Kabete', 'Ruiru', 'Thika Town', 'Juja', 'Gatundu South', 'Gatundu North', 'Kiambaa'],
        'Nakuru' => ['Naivasha', 'Gilgil', 'Nakuru Town East', 'Nakuru Town West', 'Bahati', 'Rongai', 'Subukia', 'Njoro', 'Molo', 'Kuresoi South', 'Kuresoi North'],
        'Machakos' => ['Machakos Town', 'Athi River', 'Mavoko', 'Kathiani', 'Masinga', 'Yatta', 'Kangundo', 'Matungulu'],
        'Bomet' => ['Bomet East', 'Bomet Central', 'Chepalungu', 'Konoin', 'Sotik'],
        'Meru' => ['Imenti Central', 'Imenti North', 'Imenti South', 'Tigania East', 'Tigania West', 'North Imenti', 'Buuri', 'Igembe South', 'Igembe Central', 'Igembe North'],
        'Murang\'a' => ['Kangema', 'Mathioya', 'Kiharu', 'Kigumo', 'Kandara', 'Gatanga', 'Kahuro'],
        'Nyeri' => ['Tetu', 'Kieni', 'Mathira', 'Othaya', 'Mukurweini', 'Nyeri Town'],
        'Embu' => ['Manyatta', 'Runyenjes', 'Mbeere South', 'Mbeere North'],
        'Nairobi' => ['Westlands', 'Dagoretti North', 'Dagoretti South', 'Langata', 'Kibra', 'Roysambu', 'Kasarani', 'Ruaraka', 'Embakasi South', 'Embakasi North', 'Embakasi Central', 'Embakasi East', 'Embakasi West', 'Makadara', 'Kamukunji', 'Starehe', 'Mathare'],
        'Uasin Gishu' => ['Ainabkoi', 'Kapseret', 'Kesses', 'Moiben', 'Soy', 'Turbo'],
        'Trans Nzoia' => ['Kwanza', 'Endebess', 'Saboti', 'Kiminini', 'Cherangany'],
        'Bungoma' => ['Bumula', 'Kabuchai', 'Kanduyi', 'Kimilili', 'Mt Elgon', 'Sirisia', 'Tongaren', 'Webuye East', 'Webuye West'],
        'Kakamega' => ['Butere', 'Mumias East', 'Mumias West', 'Matungu', 'Khwisero', 'Shinyalu', 'Lurambi', 'Ikolomani', 'Lugari', 'Malava', 'Navakholo', 'Likuyani'],
        'Kericho' => ['Ainamoi', 'Bureti', 'Belgut', 'Sigowet/Soin', 'Soin/Sigowet', 'Kipkelion East', 'Kipkelion West']
    ];

    $wards = $wardsByCounty[$county] ?? [];
    
    return response()->json([
        'success' => true,
        'data' => collect($wards)->map(function ($ward) {
            return ['value' => $ward, 'label' => $ward];
        })->values()
    ]);
});

Route::get('/options/villages', function (Request $request) {
    $ward = $request->query('ward');
    if (!$ward) {
        return response()->json([
            'success' => false,
            'message' => 'Ward parameter is required'
        ], 400);
    }

    // Sample village data for major farming wards
    $villagesByWard = [
        'Githunguri' => ['Githunguri', 'Kihara', 'Ikinu', 'Ngewa', 'Kamburu', 'Muchatha', 'Komothai'],
        'Kiambu' => ['Township', 'Ndumberi', 'Riabai', 'Tinganga', 'Uthiru'],
        'Limuru' => ['Tigoni', 'Ndeiya', 'Limuru Central', 'Limuru East', 'Bibirioni'],
        'Thika Town' => ['Kamenu', 'Hospital', 'Township', 'Gatuanyaga', 'Ngoliba'],
        'Juja' => ['Kalimoni', 'Witeithie', 'Juja', 'Murera', 'Theta'],
        'Naivasha' => ['Naivasha East', 'Naivasha West', 'Viwandani', 'Hells Gate', 'Olkaria', 'Maiella'],
        'Machakos Town' => ['Kalama', 'Kola', 'Mumbuni North', 'Mumbuni South', 'Mutituni', 'Muvuti/Kiima-Kimwe'],
        'Athi River' => ['Athi River', 'Kinanie', 'Muthwani', 'Syokimau/Mulolongo'],
        'Bomet Central' => ['Singorwet', 'Chesoen', 'Mutarakwa', 'Silibwet Township'],
        'Bomet East' => ['Merigi', 'Kembu', 'Longisa', 'Kipreres', 'Chemaner']
    ];

    $villages = $villagesByWard[$ward] ?? [
        // Default villages if specific ward not found
        ucfirst($ward) . ' Central',
        ucfirst($ward) . ' East', 
        ucfirst($ward) . ' West',
        ucfirst($ward) . ' North',
        ucfirst($ward) . ' South'
    ];
    
    return response()->json([
        'success' => true,
        'data' => collect($villages)->map(function ($village) {
            return ['value' => $village, 'label' => $village];
        })->values()
    ]);
});

// Crop options - public for registration/form dropdowns
Route::get('/options/crop-names', function () {
    return response()->json([
        'success' => true,
        'data' => collect(\App\Models\CropCycle::getCropNames())->map(function ($crop) {
            return ['value' => strtolower(str_replace(' ', '_', $crop)), 'label' => $crop];
        })->values()
    ]);
});

Route::get('/options/crop-varieties', function () {
    $varieties = [
        'onions' => ['Red Creole', 'Texas Grano', 'White Globe', 'Red Globe'],
        'tomatoes' => ['Roma', 'Cherry', 'Beef', 'Determinate', 'Indeterminate'],
        'sukuma_wiki' => ['Thousand Headed Kale', 'Collard Greens'],
        'capsicum' => ['Bell Pepper', 'Hot Pepper', 'Sweet Pepper'],
        'coriander' => ['Slow Bolt', 'Santo', 'Leisure'],
    ];
    
    return response()->json([
        'success' => true,
        'data' => $varieties
    ]);
});

Route::get('/options/crop-statuses', function () {
    return response()->json([
        'success' => true,
        'data' => collect(\App\Models\CropCycle::getStatusLabels())->map(function ($label, $value) {
            return ['value' => $value, 'label' => $label];
        })->values()
    ]);
});

/*
|--------------------------------------------------------------------------
| Farm Management Routes (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Farm CRUD operations
    Route::prefix('farms')->group(function () {
        Route::get('/', [FarmController::class, 'index']); // GET /api/farms - list my farms
        Route::post('/', [FarmController::class, 'store']); // POST /api/farms - create farm
        
        Route::prefix('{farmId}')->group(function () {
            Route::get('/', [FarmController::class, 'show']); // GET /api/farms/{id}
            Route::patch('/', [FarmController::class, 'update']); // PATCH /api/farms/{id}
            Route::post('/switch', [FarmController::class, 'switchFarm']); // POST /api/farms/{id}/switch
            Route::post('/archive', [FarmController::class, 'archive']); // POST /api/farms/{id}/archive
            
            // Worker management
            Route::prefix('workers')->group(function () {
                Route::get('/', [FarmController::class, 'getWorkers']); // GET /api/farms/{id}/workers
                Route::post('/', [FarmController::class, 'addWorker']); // POST /api/farms/{id}/workers
                Route::patch('/{userId}', [FarmController::class, 'updateWorker']); // PATCH /api/farms/{id}/workers/{userId}
                Route::delete('/{userId}', [FarmController::class, 'removeWorker']); // DELETE /api/farms/{id}/workers/{userId}
            });
        });
    });
    
});

/*
|--------------------------------------------------------------------------
| Analytics Dashboard Routes (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Analytics Dashboard - Main endpoints for dashboard metrics
    Route::prefix('analytics')->group(function () {
        Route::get('/metrics', [AnalyticsController::class, 'metrics']); // GET /api/analytics/metrics?timeRange=month
        Route::get('/activity', [AnalyticsController::class, 'activity']); // GET /api/analytics/activity?timeRange=week
        Route::get('/crop-yields', [AnalyticsController::class, 'cropYields']); // GET /api/analytics/crop-yields
        Route::get('/weather', [AnalyticsController::class, 'weather']); // GET /api/analytics/weather?timeRange=month
        Route::get('/trends', [AnalyticsController::class, 'trends']); // GET /api/analytics/trends?period=week
        Route::post('/export', [AnalyticsController::class, 'export']); // POST /api/analytics/export
        
        // Farm-specific analytics
        Route::get('/farm-metrics', [FarmAnalyticsController::class, 'getFarmMetrics']); // GET /api/analytics/farm-metrics?time_range=month
        Route::post('/farm-metrics/clear-cache', [FarmAnalyticsController::class, 'clearMetricsCache']); // POST /api/analytics/farm-metrics/clear-cache
    });
    
});

/*
|--------------------------------------------------------------------------
| Module 2: Crop Production Planning Routes (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Crop Cycle Management
    Route::prefix('crops')->group(function () {
        Route::get('/', [CropController::class, 'index']); // GET /api/crops - list crop cycles
        Route::post('/', [CropController::class, 'store']); // POST /api/crops - create crop cycle
        Route::get('/analytics', [CropController::class, 'analytics']); // GET /api/crops/analytics
        
        Route::prefix('{cropId}')->group(function () {
            Route::get('/', [CropController::class, 'show']); // GET /api/crops/{id}
            Route::patch('/', [CropController::class, 'update']); // PATCH /api/crops/{id}
            Route::post('/status', [CropController::class, 'updateStatus']); // POST /api/crops/{id}/status
            Route::post('/archive', [CropController::class, 'archive']); // POST /api/crops/{id}/archive
            
            // Crop Tasks
            Route::get('/tasks', [CropController::class, 'getTasks']); // GET /api/crops/{id}/tasks
            Route::post('/tasks', [CropController::class, 'createTask']); // POST /api/crops/{id}/tasks
        });
    });
    
    // Crop Task Management
    Route::prefix('tasks')->group(function () {
        Route::get('/', [CropTaskController::class, 'index']); // GET /api/tasks - list tasks
        Route::post('/', [CropTaskController::class, 'store']); // POST /api/tasks - create task
        Route::get('/dashboard', [CropTaskController::class, 'dashboard']); // GET /api/tasks/dashboard
        
        Route::prefix('{taskId}')->group(function () {
            Route::get('/', [CropTaskController::class, 'show']); // GET /api/tasks/{id}
            Route::patch('/', [CropTaskController::class, 'update']); // PATCH /api/tasks/{id}
            Route::delete('/', [CropTaskController::class, 'destroy']); // DELETE /api/tasks/{id}
            Route::post('/start', [CropTaskController::class, 'start']); // POST /api/tasks/{id}/start
            Route::post('/complete', [CropTaskController::class, 'complete']); // POST /api/tasks/{id}/complete
            Route::post('/status', [CropController::class, 'updateTaskStatus']); // POST /api/tasks/{id}/status
            Route::post('/cancel', [CropTaskController::class, 'cancel']); // POST /api/tasks/{id}/cancel
            Route::post('/skip', [CropTaskController::class, 'skip']); // POST /api/tasks/{id}/skip
            Route::post('/reschedule', [CropTaskController::class, 'reschedule']); // POST /api/tasks/{id}/reschedule
        });
    });
    
});

/*
|--------------------------------------------------------------------------
| Module 3: Expense Ledger Routes (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Expense Management
    Route::prefix('expenses')->group(function () {
        Route::get('/', [ExpenseController::class, 'index']); // GET /api/expenses - list expenses
        Route::post('/', [ExpenseController::class, 'store']); // POST /api/expenses - create expense
        Route::post('/bulk', [ExpenseController::class, 'bulkStore']); // POST /api/expenses/bulk - bulk create
        Route::patch('/bulk', [ExpenseController::class, 'bulkUpdate']); // PATCH /api/expenses/bulk - bulk update
        Route::delete('/bulk', [ExpenseController::class, 'bulkDestroy']); // DELETE /api/expenses/bulk - bulk delete
        Route::get('/pending', [ExpenseController::class, 'pending']); // GET /api/expenses/pending
        Route::post('/approvals', [ExpenseController::class, 'processApprovals']); // POST /api/expenses/approvals
        Route::get('/approvals/history', [ExpenseController::class, 'approvalHistory']); // GET /api/expenses/approvals/history
        Route::get('/stats', [ExpenseController::class, 'stats']); // GET /api/expenses/stats
        Route::get('/charts', [ExpenseController::class, 'charts']); // GET /api/expenses/charts
        Route::get('/trends', [ExpenseController::class, 'trends']); // GET /api/expenses/trends
        Route::get('/budget-analysis', [ExpenseController::class, 'budgetAnalysis']); // GET /api/expenses/budget-analysis
        Route::get('/dashboard', [ExpenseController::class, 'dashboard']); // GET /api/expenses/dashboard
        Route::get('/search', [ExpenseController::class, 'search']); // GET /api/expenses/search
        Route::get('/suggestions', [ExpenseController::class, 'suggestions']); // GET /api/expenses/suggestions
        Route::get('/audit', [ExpenseController::class, 'auditTrail']); // GET /api/expenses/audit
        Route::get('/audit/stats', [ExpenseController::class, 'auditStats']); // GET /api/expenses/audit/stats
        Route::post('/duplicates/check', [ExpenseController::class, 'checkDuplicates']); // POST /api/expenses/duplicates/check
        Route::get('/duplicates/groups', [ExpenseController::class, 'duplicateGroups']); // GET /api/expenses/duplicates/groups
        Route::post('/duplicates/resolve', [ExpenseController::class, 'resolveDuplicates']); // POST /api/expenses/duplicates/resolve
        Route::get('/duplicates/settings', [ExpenseController::class, 'duplicateSettings']); // GET /api/expenses/duplicates/settings
        Route::put('/duplicates/settings', [ExpenseController::class, 'duplicateSettingsUpdate']); // PUT /api/expenses/duplicates/settings
        Route::get('/performance', [ExpenseController::class, 'performance']); // GET /api/expenses/performance
        Route::get('/performance/suggestions', [ExpenseController::class, 'performanceSuggestions']); // GET /api/expenses/performance/suggestions
        Route::get('/permissions', [ExpenseController::class, 'permissions']); // GET /api/expenses/permissions
        Route::get('/permissions/roles/{role}', [ExpenseController::class, 'rolePermissions']); // GET /api/expenses/permissions/roles/{role}
        Route::get('/export', [ExpenseController::class, 'export']); // GET /api/expenses/export
        Route::post('/import', [ExpenseController::class, 'import']); // POST /api/expenses/import
        Route::get('/import/template', [ExpenseController::class, 'importTemplate']); // GET /api/expenses/import/template
        Route::get('/import-export/history', [ExpenseController::class, 'importExportHistory']); // GET /api/expenses/import-export/history
        Route::delete('/import-export/history/{historyId}', [ExpenseController::class, 'importExportHistoryDestroy']); // DELETE /api/expenses/import-export/history/{id}
        Route::post('/upload', [ExpenseController::class, 'uploadFile']); // POST /api/expenses/upload
        Route::delete('/files', [ExpenseController::class, 'deleteFile']); // DELETE /api/expenses/files
        Route::post('/ocr', [ExpenseController::class, 'processOcr']); // POST /api/expenses/ocr
        Route::get('/notifications', [ExpenseController::class, 'notifications']); // GET /api/expenses/notifications
        Route::patch('/notifications/read-all', [ExpenseController::class, 'markAllNotificationsRead']); // PATCH /api/expenses/notifications/read-all
        Route::get('/notifications/settings', [ExpenseController::class, 'notificationSettings']); // GET /api/expenses/notifications/settings
        Route::put('/notifications/settings', [ExpenseController::class, 'notificationSettingsUpdate']); // PUT /api/expenses/notifications/settings
        Route::patch('/notifications/{notificationId}/read', [ExpenseController::class, 'markNotificationRead']); // PATCH /api/expenses/notifications/{id}/read
        Route::delete('/notifications/{notificationId}', [ExpenseController::class, 'dismissNotification']); // DELETE /api/expenses/notifications/{id}
        Route::get('/realtime/metrics', [ExpenseController::class, 'realTimeMetrics']); // GET /api/expenses/realtime/metrics
        Route::get('/realtime/activity', [ExpenseController::class, 'realTimeActivity']); // GET /api/expenses/realtime/activity
        Route::get('/realtime/alerts', [ExpenseController::class, 'realTimeAlerts']); // GET /api/expenses/realtime/alerts
        Route::delete('/realtime/alerts/{alertId}', [ExpenseController::class, 'dismissRealTimeAlert']); // DELETE /api/expenses/realtime/alerts/{id}
        Route::get('/categories/budgets', [ExpenseCategoryController::class, 'budgets']); // GET /api/expenses/categories/budgets
        Route::get('/categories', [ExpenseCategoryController::class, 'index']); // GET /api/expenses/categories
        Route::post('/categories', [ExpenseCategoryController::class, 'store']); // POST /api/expenses/categories
        Route::put('/categories/{categoryId}', [ExpenseCategoryController::class, 'update']); // PUT /api/expenses/categories/{id}
        Route::patch('/categories/{categoryId}', [ExpenseCategoryController::class, 'update']); // PATCH /api/expenses/categories/{id}
        Route::delete('/categories/{categoryId}', [ExpenseCategoryController::class, 'destroy']); // DELETE /api/expenses/categories/{id}
        Route::put('/categories/{categoryId}/budget', [ExpenseCategoryController::class, 'setBudget']); // PUT /api/expenses/categories/{id}/budget
        Route::post('/categories/{categoryId}/budget', [ExpenseCategoryController::class, 'setBudget']); // POST /api/expenses/categories/{id}/budget
        
        Route::prefix('{expenseId}')->group(function () {
            Route::get('/', [ExpenseController::class, 'show']); // GET /api/expenses/{id}
            Route::put('/', [ExpenseController::class, 'update']); // PUT /api/expenses/{id}
            Route::patch('/', [ExpenseController::class, 'update']); // PATCH /api/expenses/{id}
            Route::delete('/', [ExpenseController::class, 'destroy']); // DELETE /api/expenses/{id}
            Route::post('/duplicate', [ExpenseController::class, 'duplicate']); // POST /api/expenses/{id}/duplicate
            Route::post('/approve', [ExpenseController::class, 'approve']); // POST /api/expenses/{id}/approve
            Route::post('/reject', [ExpenseController::class, 'reject']); // POST /api/expenses/{id}/reject
        });
    });
    
    // Expense Category Management
    Route::prefix('expense-categories')->group(function () {
        Route::get('/', [ExpenseCategoryController::class, 'index']); // GET /api/expense-categories
        Route::post('/', [ExpenseCategoryController::class, 'store']); // POST /api/expense-categories
        Route::get('/most-used', [ExpenseCategoryController::class, 'mostUsed']); // GET /api/expense-categories/most-used
        Route::post('/initialize-defaults', [ExpenseCategoryController::class, 'initializeDefaults']); // POST /api/expense-categories/initialize-defaults
        
        Route::prefix('{categoryId}')->group(function () {
            Route::get('/', [ExpenseCategoryController::class, 'show']); // GET /api/expense-categories/{id}
            Route::patch('/', [ExpenseCategoryController::class, 'update']); // PATCH /api/expense-categories/{id}
            Route::delete('/', [ExpenseCategoryController::class, 'destroy']); // DELETE /api/expense-categories/{id}
            Route::post('/budget', [ExpenseCategoryController::class, 'setBudget']); // POST /api/expense-categories/{id}/budget
            Route::get('/analytics', [ExpenseCategoryController::class, 'analytics']); // GET /api/expense-categories/{id}/analytics
        });
    });
    
});

/*
|--------------------------------------------------------------------------
| Module 4: Labour Management Routes (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Worker Management
    Route::prefix('workers')->group(function () {
        Route::get('/', [WorkerController::class, 'index']); // GET /api/workers - list workers
        Route::post('/', [WorkerController::class, 'store']); // POST /api/workers - create worker
        Route::get('/most-active', [WorkerController::class, 'mostActive']); // GET /api/workers/most-active
        
        Route::prefix('{workerId}')->group(function () {
            Route::get('/', [WorkerController::class, 'show']); // GET /api/workers/{id}
            Route::patch('/', [WorkerController::class, 'update']); // PATCH /api/workers/{id}
            Route::delete('/', [WorkerController::class, 'destroy']); // DELETE /api/workers/{id}
            Route::post('/activate', [WorkerController::class, 'activate']); // POST /api/workers/{id}/activate
            Route::post('/deactivate', [WorkerController::class, 'deactivate']); // POST /api/workers/{id}/deactivate
            Route::post('/update-rate', [WorkerController::class, 'updateRate']); // POST /api/workers/{id}/update-rate
        });
    });
    
    // Labour Entry Management
    Route::prefix('labour')->group(function () {
        Route::get('/', [LabourEntryController::class, 'index']); // GET /api/labour - list labour entries
        Route::post('/', [LabourEntryController::class, 'store']); // POST /api/labour - create labour entry
        Route::post('/bulk', [LabourEntryController::class, 'bulkStore']); // POST /api/labour/bulk - bulk create
        Route::get('/summary', [LabourEntryController::class, 'summary']); // GET /api/labour/summary
        Route::get('/analytics', [LabourEntryController::class, 'analytics']); // GET /api/labour/analytics

        // Static templates endpoint - must be before {entryId} wildcard
        Route::get('/templates', function () {
            return response()->json([
                'success' => true,
                'data' => [
                    'templates' => [
                        ['id' => 'weeding', 'label' => 'Weeding', 'labour_type' => 'weeding', 'default_payment_type' => 'daily'],
                        ['id' => 'bed_preparation', 'label' => 'Bed Preparation', 'labour_type' => 'bed_preparation', 'default_payment_type' => 'daily'],
                        ['id' => 'transplanting', 'label' => 'Transplanting', 'labour_type' => 'transplanting', 'default_payment_type' => 'piece_rate'],
                        ['id' => 'watering', 'label' => 'Watering', 'labour_type' => 'watering', 'default_payment_type' => 'daily'],
                        ['id' => 'spraying', 'label' => 'Spraying', 'labour_type' => 'spraying', 'default_payment_type' => 'daily'],
                        ['id' => 'fertilizer_application', 'label' => 'Fertilizer Application', 'labour_type' => 'fertilizer_application', 'default_payment_type' => 'daily'],
                        ['id' => 'harvest_labour', 'label' => 'Harvest Labour', 'labour_type' => 'harvest_labour', 'default_payment_type' => 'piece_rate'],
                        ['id' => 'general_work', 'label' => 'General Work', 'labour_type' => 'general_work', 'default_payment_type' => 'daily'],
                        ['id' => 'fence_repair', 'label' => 'Fence Repair', 'labour_type' => 'fence_repair', 'default_payment_type' => 'daily'],
                        ['id' => 'misc', 'label' => 'Miscellaneous', 'labour_type' => 'misc', 'default_payment_type' => 'daily'],
                    ]
                ]
            ]);
        }); // GET /api/labour/templates

        Route::prefix('{entryId}')->group(function () {
            Route::get('/', [LabourEntryController::class, 'show']); // GET /api/labour/{id}
            Route::patch('/', [LabourEntryController::class, 'update']); // PATCH /api/labour/{id}
            Route::delete('/', [LabourEntryController::class, 'destroy']); // DELETE /api/labour/{id}
            Route::post('/approve', [LabourEntryController::class, 'approve']); // POST /api/labour/{id}/approve
            Route::post('/reject', [LabourEntryController::class, 'reject']); // POST /api/labour/{id}/reject
            Route::post('/mark-paid', [LabourEntryController::class, 'markPaid']); // POST /api/labour/{id}/mark-paid
        });
    });
    
});

/*
|--------------------------------------------------------------------------
| Module 5: Inventory Management Routes (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Inventory Item Management
    Route::prefix('inventory')->group(function () {
        Route::get('/', [InventoryItemController::class, 'index']); // GET /api/inventory - list items
        Route::post('/', [InventoryItemController::class, 'store']); // POST /api/inventory - create item
        Route::get('/dashboard', [InventoryItemController::class, 'dashboard']); // GET /api/inventory/dashboard
        Route::get('/analytics', [InventoryItemController::class, 'analytics']); // GET /api/inventory/analytics
        Route::get('/export', [InventoryItemController::class, 'export']); // GET /api/inventory/export
        Route::get('/search', [InventoryItemController::class, 'search']); // GET /api/inventory/search
        Route::get('/movements', [InventoryItemController::class, 'movements']); // GET /api/inventory/movements
        Route::post('/bulk-delete', [InventoryItemController::class, 'bulkDelete']); // POST /api/inventory/bulk-delete
        Route::post('/bulk-update', [InventoryItemController::class, 'bulkUpdate']); // POST /api/inventory/bulk-update
        
        Route::prefix('{itemId}')->group(function () {
            Route::get('/', [InventoryItemController::class, 'show']); // GET /api/inventory/{id}
            Route::put('/', [InventoryItemController::class, 'update']); // PUT /api/inventory/{id}
            Route::patch('/', [InventoryItemController::class, 'update']); // PATCH /api/inventory/{id}
            Route::delete('/', [InventoryItemController::class, 'destroy']); // DELETE /api/inventory/{id}
            Route::post('/stock-in', [InventoryItemController::class, 'stockIn']); // POST /api/inventory/{id}/stock-in
            Route::post('/stock-out', [InventoryItemController::class, 'stockOut']); // POST /api/inventory/{id}/stock-out
            Route::post('/adjust', [InventoryItemController::class, 'adjustStock']); // POST /api/inventory/{id}/adjust
            Route::post('/mark-expired', [InventoryItemController::class, 'markExpired']); // POST /api/inventory/{id}/mark-expired
        });
    });
    
    // Stock Movement Management
    Route::prefix('stock-movements')->group(function () {
        Route::get('/', [StockMovementController::class, 'index']); // GET /api/stock-movements
        Route::get('/summary', [StockMovementController::class, 'summary']); // GET /api/stock-movements/summary
        Route::get('/crop/{cropId}/usage', [StockMovementController::class, 'cropUsage']); // GET /api/stock-movements/crop/{id}/usage
        Route::get('/worker/{workerId}/usage', [StockMovementController::class, 'workerUsage']); // GET /api/stock-movements/worker/{id}/usage
        
        Route::prefix('{movementId}')->group(function () {
            Route::get('/', [StockMovementController::class, 'show']); // GET /api/stock-movements/{id}
            Route::post('/cancel', [StockMovementController::class, 'cancel']); // POST /api/stock-movements/{id}/cancel
        });
    });
    
    // Inventory Alerts Management
    Route::prefix('inventory-alerts')->group(function () {
        Route::get('/', [InventoryAlertController::class, 'index']); // GET /api/inventory-alerts
        Route::get('/dashboard', [InventoryAlertController::class, 'dashboard']); // GET /api/inventory-alerts/dashboard
        Route::get('/summary', [InventoryAlertController::class, 'summary']); // GET /api/inventory-alerts/summary
        Route::post('/bulk-acknowledge', [InventoryAlertController::class, 'bulkAcknowledge']); // POST /api/inventory-alerts/bulk-acknowledge
        Route::post('/bulk-resolve', [InventoryAlertController::class, 'bulkResolve']); // POST /api/inventory-alerts/bulk-resolve
        Route::post('/auto-resolve', [InventoryAlertController::class, 'autoResolve']); // POST /api/inventory-alerts/auto-resolve
        
        Route::prefix('{alertId}')->group(function () {
            Route::get('/', [InventoryAlertController::class, 'show']); // GET /api/inventory-alerts/{id}
            Route::post('/acknowledge', [InventoryAlertController::class, 'acknowledge']); // POST /api/inventory-alerts/{id}/acknowledge
            Route::post('/resolve', [InventoryAlertController::class, 'resolve']); // POST /api/inventory-alerts/{id}/resolve
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Module 6: Harvest Tracking
    |--------------------------------------------------------------------------
    */
    
    // Harvest Management
    Route::prefix('harvests')->group(function () {
        Route::get('/', [HarvestController::class, 'index']); // GET /api/harvests
        Route::post('/', [HarvestController::class, 'store']); // POST /api/harvests
        Route::get('/pending-approvals', [HarvestController::class, 'pendingApprovals']); // GET /api/harvests/pending-approvals
        Route::get('/dashboard-metrics', [HarvestController::class, 'dashboardMetrics']); // GET /api/harvests/dashboard-metrics
        Route::get('/analytics', [HarvestController::class, 'analytics']); // GET /api/harvests/analytics
        Route::get('/export', [HarvestController::class, 'export']); // GET /api/harvests/export
        Route::get('/timeline/{cropCycleId}', [HarvestController::class, 'timeline']); // GET /api/harvests/timeline/{cropCycleId}
        
        Route::prefix('{harvestId}')->group(function () {
            Route::get('/', [HarvestController::class, 'show']); // GET /api/harvests/{id}
            Route::patch('/', [HarvestController::class, 'update']); // PATCH /api/harvests/{id}
            Route::delete('/', [HarvestController::class, 'destroy']); // DELETE /api/harvests/{id}
            Route::post('/approve', [HarvestController::class, 'approve']); // POST /api/harvests/{id}/approve
            Route::post('/reject', [HarvestController::class, 'reject']); // POST /api/harvests/{id}/reject
            
            // Harvest Notes Management
            Route::prefix('notes')->group(function () {
                Route::get('/', [HarvestNoteController::class, 'index']); // GET /api/harvests/{harvestId}/notes
                Route::post('/', [HarvestNoteController::class, 'store']); // POST /api/harvests/{harvestId}/notes
                
                Route::prefix('{noteId}')->group(function () {
                    Route::patch('/', [HarvestNoteController::class, 'update']); // PATCH /api/harvests/{harvestId}/notes/{noteId}
                    Route::delete('/', [HarvestNoteController::class, 'destroy']); // DELETE /api/harvests/{harvestId}/notes/{noteId}
                    Route::post('/mark-critical', [HarvestNoteController::class, 'markCritical']); // POST /api/harvests/{harvestId}/notes/{noteId}/mark-critical
                });
            });
            
            // Harvest Sales Allocation Management
            Route::prefix('allocations')->group(function () {
                Route::get('/', [HarvestSalesAllocationController::class, 'index']); // GET /api/harvests/{harvestId}/allocations
                Route::post('/', [HarvestSalesAllocationController::class, 'store']); // POST /api/harvests/{harvestId}/allocations
                
                Route::prefix('{allocationId}')->group(function () {
                    Route::patch('/', [HarvestSalesAllocationController::class, 'update']); // PATCH /api/harvests/{harvestId}/allocations/{allocationId}
                    Route::delete('/', [HarvestSalesAllocationController::class, 'destroy']); // DELETE /api/harvests/{harvestId}/allocations/{allocationId}
                    Route::post('/confirm', [HarvestSalesAllocationController::class, 'confirm']); // POST /api/harvests/{harvestId}/allocations/{allocationId}/confirm
                    Route::post('/mark-delivered', [HarvestSalesAllocationController::class, 'markDelivered']); // POST /api/harvests/{harvestId}/allocations/{allocationId}/mark-delivered
                });
            });
        });
    });
    
    // Harvest Notes Analysis
    Route::prefix('harvest-notes')->group(function () {
        Route::get('/critical', [HarvestNoteController::class, 'criticalNotes']); // GET /api/harvest-notes/critical
        Route::get('/recurring-issues/{cropCycleId}', [HarvestNoteController::class, 'recurringIssues']); // GET /api/harvest-notes/recurring-issues/{cropCycleId}
        Route::get('/improvement-suggestions/{cropCycleId}', [HarvestNoteController::class, 'improvementSuggestions']); // GET /api/harvest-notes/improvement-suggestions/{cropCycleId}
    });
    
    // Sales Analytics (from harvest allocations)
    Route::prefix('harvest-sales')->group(function () {
        Route::get('/analytics', [HarvestSalesAllocationController::class, 'salesAnalytics']); // GET /api/harvest-sales/analytics
        Route::get('/summary', [HarvestSalesAllocationController::class, 'allocationsSummary']); // GET /api/harvest-sales/summary
    });
    
    /*
    |--------------------------------------------------------------------------
    | Module 7: Sales & Income Tracking
    |--------------------------------------------------------------------------
    */
    
    // Buyer Management
    Route::prefix('buyers')->group(function () {
        Route::get('/', [BuyerController::class, 'index']); // GET /api/buyers
        Route::post('/', [BuyerController::class, 'store']); // POST /api/buyers
        Route::get('/stats', [BuyerController::class, 'getBuyerStats']); // GET /api/buyers/stats
        Route::get('/top', [BuyerController::class, 'getTopBuyers']); // GET /api/buyers/top
        Route::get('/export', [BuyerController::class, 'export']); // GET /api/buyers/export
        
        Route::prefix('{buyerId}')->group(function () {
            Route::get('/', [BuyerController::class, 'show']); // GET /api/buyers/{id}
            Route::patch('/', [BuyerController::class, 'update']); // PATCH /api/buyers/{id}
            Route::delete('/', [BuyerController::class, 'destroy']); // DELETE /api/buyers/{id}
            Route::post('/update-reliability', [BuyerController::class, 'updateReliabilityScore']); // POST /api/buyers/{id}/update-reliability
            Route::post('/deactivate', [BuyerController::class, 'deactivate']); // POST /api/buyers/{id}/deactivate
            Route::post('/reactivate', [BuyerController::class, 'reactivate']); // POST /api/buyers/{id}/reactivate
            Route::post('/check-credit', [BuyerController::class, 'checkCreditLimit']); // POST /api/buyers/{id}/check-credit
        });
    });
    
    // Sales Management
    Route::prefix('sales')->group(function () {
        Route::get('/', [SaleController::class, 'index']); // GET /api/sales
        Route::post('/', [SaleController::class, 'store']); // POST /api/sales
        Route::get('/stats', [SaleController::class, 'getSalesStats']); // GET /api/sales/stats
        Route::get('/summary', [SaleController::class, 'getSalesSummary']); // GET /api/sales/summary
        Route::get('/analytics', [SaleController::class, 'getSalesAnalytics']); // GET /api/sales/analytics
        Route::get('/export', [SaleController::class, 'export']); // GET /api/sales/export
        Route::get('/summary/export', [SaleController::class, 'exportSummary']); // GET /api/sales/summary/export
        
        Route::prefix('{saleId}')->group(function () {
            Route::get('/', [SaleController::class, 'show']); // GET /api/sales/{id}
            Route::patch('/', [SaleController::class, 'update']); // PATCH /api/sales/{id}
            Route::delete('/', [SaleController::class, 'destroy']); // DELETE /api/sales/{id}
            Route::post('/approve', [SaleController::class, 'approve']); // POST /api/sales/{id}/approve
            Route::post('/reject', [SaleController::class, 'reject']); // POST /api/sales/{id}/reject
            Route::post('/record-payment', [SaleController::class, 'recordPayment']); // POST /api/sales/{id}/record-payment
        });
    });
    
    // Price History Management
    Route::prefix('price-history')->group(function () {
        Route::get('/', [PriceHistoryController::class, 'index']); // GET /api/price-history
        Route::post('/', [PriceHistoryController::class, 'store']); // POST /api/price-history
        Route::post('/bulk-import', [PriceHistoryController::class, 'bulkImport']); // POST /api/price-history/bulk-import
        Route::get('/analytics', [PriceHistoryController::class, 'getPriceAnalytics']); // GET /api/price-history/analytics
        Route::get('/trends', [PriceHistoryController::class, 'getPriceTrends']); // GET /api/price-history/trends
        Route::get('/current-prices', [PriceHistoryController::class, 'getCurrentMarketPrices']); // GET /api/price-history/current-prices
        Route::get('/market-comparison', [PriceHistoryController::class, 'getMarketComparison']); // GET /api/price-history/market-comparison
        Route::get('/seasonal-patterns', [PriceHistoryController::class, 'getSeasonalPatterns']); // GET /api/price-history/seasonal-patterns
        Route::get('/forecast-data', [PriceHistoryController::class, 'getPriceForecastData']); // GET /api/price-history/forecast-data
        
        Route::prefix('{historyId}')->group(function () {
            Route::get('/', [PriceHistoryController::class, 'show']); // GET /api/price-history/{id}
            Route::patch('/', [PriceHistoryController::class, 'update']); // PATCH /api/price-history/{id}
            Route::delete('/', [PriceHistoryController::class, 'destroy']); // DELETE /api/price-history/{id}
        });
    });
    
    // Sales Approval Management
    Route::prefix('sales-approvals')->group(function () {
        Route::get('/', [SalesApprovalController::class, 'index']); // GET /api/sales-approvals
        Route::get('/stats', [SalesApprovalController::class, 'getApprovalStats']); // GET /api/sales-approvals/stats
        Route::get('/approver-metrics', [SalesApprovalController::class, 'getApproverMetrics']); // GET /api/sales-approvals/approver-metrics
        Route::get('/rejection-reasons', [SalesApprovalController::class, 'getRejectionReasons']); // GET /api/sales-approvals/rejection-reasons
        Route::get('/workflow-insights', [SalesApprovalController::class, 'getWorkflowInsights']); // GET /api/sales-approvals/workflow-insights
        Route::get('/approval-queue', [SalesApprovalController::class, 'getApprovalQueue']); // GET /api/sales-approvals/approval-queue
        Route::get('/history', [SalesApprovalController::class, 'getApprovalHistory']); // GET /api/sales-approvals/history
        Route::post('/bulk-approve', [SalesApprovalController::class, 'bulkApprove']); // POST /api/sales-approvals/bulk-approve
        Route::post('/bulk-reject', [SalesApprovalController::class, 'bulkReject']); // POST /api/sales-approvals/bulk-reject
        
        Route::prefix('{approvalId}')->group(function () {
            Route::get('/', [SalesApprovalController::class, 'show']); // GET /api/sales-approvals/{id}
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Module 8: Profitability Engine Routes
    |--------------------------------------------------------------------------
    */
    
    // Profitability Analysis Routes
    Route::prefix('profitability')->group(function () {
        Route::get('/dashboard', [ProfitabilityController::class, 'dashboard']);
        Route::get('/export', [ProfitabilityController::class, 'export']);
        Route::get('/crop/{id}', [ProfitabilityController::class, 'getCropProfitability']); // GET /api/profitability/crop/{id}
        Route::get('/farm-summary', [ProfitabilityController::class, 'getFarmSummary']); // GET /api/profitability/farm-summary
        Route::post('/refresh/{cropCycleId}', [ProfitabilityController::class, 'refreshProfitability']); // POST /api/profitability/refresh/{cropCycleId}
        Route::get('/comparison', [ProfitabilityController::class, 'getCropComparison']); // GET /api/profitability/comparison
        Route::get('/cost-breakdown/{cropCycleId}', [ProfitabilityController::class, 'getCostBreakdown']); // GET /api/profitability/cost-breakdown/{cropCycleId}
        Route::get('/trends', [ProfitabilityController::class, 'getTrends']); // GET /api/profitability/trends
    });

    // Profitability Insights Routes
    Route::prefix('profitability-insights')->group(function () {
        Route::get('/', [ProfitabilityInsightController::class, 'index']); // GET /api/profitability-insights
        Route::get('/summary', [ProfitabilityInsightController::class, 'getInsightSummary']); // GET /api/profitability-insights/summary
        Route::post('/generate', [ProfitabilityInsightController::class, 'generateInsights']); // POST /api/profitability-insights/generate
        Route::post('/bulk-action', [ProfitabilityInsightController::class, 'bulkAction']); // POST /api/profitability-insights/bulk-action
        
        Route::prefix('{insightId}')->group(function () {
            Route::get('/', [ProfitabilityInsightController::class, 'show']); // GET /api/profitability-insights/{id}
            Route::patch('/acknowledge', [ProfitabilityInsightController::class, 'acknowledge']); // PATCH /api/profitability-insights/{id}/acknowledge
            Route::patch('/act-upon', [ProfitabilityInsightController::class, 'markAsActedUpon']); // PATCH /api/profitability-insights/{id}/act-upon
            Route::delete('/dismiss', [ProfitabilityInsightController::class, 'dismiss']); // DELETE /api/profitability-insights/{id}/dismiss
        });
    });

    // Farm Performance Benchmark Routes
    Route::prefix('performance-benchmarks')->group(function () {
        Route::get('/', [FarmPerformanceBenchmarkController::class, 'index']); // GET /api/performance-benchmarks
        Route::post('/generate', [FarmPerformanceBenchmarkController::class, 'generate']); // POST /api/performance-benchmarks/generate
        Route::get('/scorecard', [FarmPerformanceBenchmarkController::class, 'getPerformanceScorecard']); // GET /api/performance-benchmarks/scorecard
        Route::get('/trends', [FarmPerformanceBenchmarkController::class, 'getPerformanceTrends']); // GET /api/performance-benchmarks/trends
        Route::post('/compare-farms', [FarmPerformanceBenchmarkController::class, 'compareFarms']); // POST /api/performance-benchmarks/compare-farms
        
        Route::prefix('{benchmarkId}')->group(function () {
            Route::get('/', [FarmPerformanceBenchmarkController::class, 'show']); // GET /api/performance-benchmarks/{id}
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Module 9: Field Observations & Insights
    |--------------------------------------------------------------------------
    */
    
    // Field Observation Management
    Route::prefix('field-observations')->group(function () {
        Route::get('/', [FieldObservationController::class, 'index']); // GET /api/field-observations
        Route::post('/', [FieldObservationController::class, 'store']); // POST /api/field-observations
        Route::get('/timeline', [FieldObservationController::class, 'timeline']); // GET /api/field-observations/timeline
        Route::get('/analytics', [FieldObservationController::class, 'analytics']); // GET /api/field-observations/analytics
        Route::get('/export', [FieldObservationController::class, 'export']);
        Route::post('/bulk-approval', [FieldObservationController::class, 'bulkApproval']);
        Route::get('/season-summary/{cropCycle}', [FieldObservationController::class, 'seasonSummary']);
        Route::get('/observation-types', [FieldObservationController::class, 'getObservationTypes']); // GET /api/field-observations/observation-types
        Route::post('/observation-types', [FieldObservationController::class, 'storeObservationType']);
        Route::delete('/observation-types/{type}', [FieldObservationController::class, 'destroyObservationType']);
        Route::get('/predefined-tags', [FieldObservationController::class, 'getPredefinedTags']); // GET /api/field-observations/predefined-tags
        
        Route::prefix('{observation}')->group(function () {
            Route::get('/', [FieldObservationController::class, 'show']); // GET /api/field-observations/{id}
            Route::patch('/', [FieldObservationController::class, 'update']); // PATCH /api/field-observations/{id}
            Route::delete('/', [FieldObservationController::class, 'destroy']); // DELETE /api/field-observations/{id}
            Route::post('/approve', [FieldObservationController::class, 'approve']); // POST /api/field-observations/{id}/approve
            Route::post('/reject', [FieldObservationController::class, 'reject']); // POST /api/field-observations/{id}/reject
            Route::post('/resolve', [FieldObservationController::class, 'resolve']); // POST /api/field-observations/{id}/resolve
            Route::post('/task', [FieldObservationController::class, 'createTask']);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Module 10: Market Linkage & Price Intelligence
    |--------------------------------------------------------------------------
    */
    
    // Market Management
    Route::get('/market-prices', [MarketController::class, 'collectionPrices']);
    Route::post('/market-prices', [MarketController::class, 'storePrice']);
    Route::get('/market-insights', [MarketInsightController::class, 'index']);
    Route::post('/market-insights/generate', [MarketInsightController::class, 'generate']);
    Route::delete('/market-insights/{marketInsight}', [MarketInsightController::class, 'destroy']);

    Route::prefix('markets')->group(function () {
        Route::get('/', [MarketController::class, 'index']); // GET /api/markets
        Route::post('/', [MarketController::class, 'store']); // POST /api/markets
        
        Route::prefix('{market}')->group(function () {
            Route::get('/', [MarketController::class, 'show']); // GET /api/markets/{id}
            Route::patch('/', [MarketController::class, 'update']); // PATCH /api/markets/{id}
            Route::delete('/', [MarketController::class, 'destroy']); // DELETE /api/markets/{id}
            Route::post('/verify', [MarketController::class, 'verify']); // POST /api/markets/{id}/verify
            Route::post('/rate', [MarketController::class, 'rate']); // POST /api/markets/{id}/rate
            Route::get('/prices', [MarketController::class, 'prices']); // GET /api/markets/{id}/prices
            Route::post('/prices', [MarketController::class, 'storePrice']);
            Route::get('/price-history', [MarketController::class, 'priceHistory']); // GET /api/markets/{id}/price-history
            Route::get('/analytics', [MarketController::class, 'analytics']); // GET /api/markets/{id}/analytics
        });
    });

    // Sell Order Management
    Route::prefix('sell-orders')->group(function () {
        Route::get('/', [SellOrderController::class, 'index']); // GET /api/sell-orders
        Route::post('/', [SellOrderController::class, 'store']); // POST /api/sell-orders
        Route::get('/analytics', [SellOrderController::class, 'analytics']); // GET /api/sell-orders/analytics
        Route::get('/buyer-recommendations', [SellOrderController::class, 'getBuyerRecommendations']); // GET /api/sell-orders/buyer-recommendations
        Route::get('/transport-cost/{pickupLat}/{pickupLng}/{deliveryLat}/{deliveryLng}', [SellOrderController::class, 'estimateTransportCost']); // GET /api/sell-orders/transport-cost
        
        Route::prefix('{sellOrder}')->group(function () {
            Route::get('/', [SellOrderController::class, 'show']); // GET /api/sell-orders/{id}
            Route::patch('/', [SellOrderController::class, 'update']); // PATCH /api/sell-orders/{id}
            Route::delete('/', [SellOrderController::class, 'destroy']); // DELETE /api/sell-orders/{id}
            Route::post('/confirm-buyer', [SellOrderController::class, 'confirmBuyer']); // POST /api/sell-orders/{id}/confirm-buyer
            Route::post('/agree-price', [SellOrderController::class, 'agreePrice']); // POST /api/sell-orders/{id}/agree-price
            Route::post('/assign-logistics', [SellOrderController::class, 'assignLogistics']); // POST /api/sell-orders/{id}/assign-logistics
            Route::post('/mark-picked-up', [SellOrderController::class, 'markPickedUp']); // POST /api/sell-orders/{id}/mark-picked-up
            Route::post('/mark-in-transit', [SellOrderController::class, 'markInTransit']); // POST /api/sell-orders/{id}/mark-in-transit
            Route::post('/mark-delivered', [SellOrderController::class, 'markDelivered']); // POST /api/sell-orders/{id}/mark-delivered
            Route::post('/mark-payment-received', [SellOrderController::class, 'markPaymentReceived']); // POST /api/sell-orders/{id}/mark-payment-received
            Route::post('/complete', [SellOrderController::class, 'complete']); // POST /api/sell-orders/{id}/complete
            Route::post('/cancel', [SellOrderController::class, 'cancel']); // POST /api/sell-orders/{id}/cancel
        });
    });

    // Logistics Management
    Route::prefix('logistics')->group(function () {
        Route::post('/estimate', [LogisticsController::class, 'estimateDelivery']); // POST /api/logistics/estimate
        Route::get('/available-vehicles', [LogisticsController::class, 'getAvailableVehicles']); // GET /api/logistics/available-vehicles
        Route::get('/service-areas', [LogisticsController::class, 'getServiceAreas']); // GET /api/logistics/service-areas
        Route::get('/status/{logisticsId}', [LogisticsController::class, 'getDeliveryStatus']); // GET /api/logistics/status/{id}
        Route::post('/cancel/{logisticsId}', [LogisticsController::class, 'cancelDelivery']); // POST /api/logistics/cancel/{id}
    });

    /*
    |--------------------------------------------------------------------------
    | Module 11: Plot/Bed Mapping
    |--------------------------------------------------------------------------
    */
    
    // Bed Management
    Route::prefix('beds')->group(function () {
        Route::get('/', [BedController::class, 'index']); // GET /api/beds
        Route::post('/', [BedController::class, 'store']); // POST /api/beds
        Route::get('/farm-layout', [BedController::class, 'farmLayout']); // GET /api/beds/farm-layout
        Route::get('/report', [BedController::class, 'report']); // GET /api/beds/report
        Route::get('/analytics', [BedController::class, 'analyticsOverview']); // GET /api/beds/analytics
        
        Route::prefix('{bed}')->group(function () {
            Route::get('/', [BedController::class, 'show']); // GET /api/beds/{id}
            Route::patch('/', [BedController::class, 'update']); // PATCH /api/beds/{id}
            Route::delete('/', [BedController::class, 'destroy']); // DELETE /api/beds/{id}
            Route::post('/assign-crop', [BedController::class, 'assignCrop']); // POST /api/beds/{id}/assign-crop
            Route::post('/complete-crop-cycle', [BedController::class, 'completeCropCycle']); // POST /api/beds/{id}/complete-crop-cycle
            Route::post('/recalculate-health', [BedController::class, 'recalculateHealth']); // POST /api/beds/{id}/recalculate-health
            Route::post('/archive', [BedController::class, 'archive']); // POST /api/beds/{id}/archive
            Route::post('/reactivate', [BedController::class, 'reactivate']); // POST /api/beds/{id}/reactivate
            Route::get('/analytics', [BedController::class, 'analytics']); // GET /api/beds/{id}/analytics
        });
    });

    Route::prefix('bed-crop-assignments')->group(function () {
        Route::get('/', [BedCropAssignmentController::class, 'index']);
        Route::get('/{bedCropAssignment}', [BedCropAssignmentController::class, 'show']);
        Route::post('/{bedCropAssignment}/abandon', [BedCropAssignmentController::class, 'abandon']);
    });

    // Bed Notes Management
    Route::prefix('bed-notes')->group(function () {
        Route::get('/', [BedNoteController::class, 'index']); // GET /api/bed-notes
        Route::post('/', [BedNoteController::class, 'store']); // POST /api/bed-notes
        Route::get('/analytics', [BedNoteController::class, 'analytics']); // GET /api/bed-notes/analytics
        Route::get('/critical-issues', [BedNoteController::class, 'criticalIssues']); // GET /api/bed-notes/critical-issues
        Route::get('/timeline/{bed}', [BedNoteController::class, 'timeline']); // GET /api/bed-notes/timeline/{bed}
        
        Route::prefix('{bedNote}')->group(function () {
            Route::get('/', [BedNoteController::class, 'show']); // GET /api/bed-notes/{id}
            Route::patch('/', [BedNoteController::class, 'update']); // PATCH /api/bed-notes/{id}
            Route::delete('/', [BedNoteController::class, 'destroy']); // DELETE /api/bed-notes/{id}
            Route::post('/approve', [BedNoteController::class, 'approve']); // POST /api/bed-notes/{id}/approve
            Route::post('/reject', [BedNoteController::class, 'reject']); // POST /api/bed-notes/{id}/reject
            Route::post('/resolve', [BedNoteController::class, 'resolve']); // POST /api/bed-notes/{id}/resolve
        });
    });

    // Irrigation Management
    Route::prefix('irrigation')->group(function () {
        Route::get('/', [IrrigationController::class, 'index']); // GET /api/irrigation
        Route::post('/systems', [IrrigationController::class, 'storeSystem']); // POST /api/irrigation/systems
        Route::patch('/systems/bulk-status', [IrrigationController::class, 'bulkStatus']); // PATCH /api/irrigation/systems/bulk-status
        Route::patch('/systems/{system}', [IrrigationController::class, 'updateSystem']); // PATCH /api/irrigation/systems/{id}
        Route::delete('/systems/{system}', [IrrigationController::class, 'destroySystem']); // DELETE /api/irrigation/systems/{id}
        Route::patch('/systems/{system}/status', [IrrigationController::class, 'setSystemStatus']); // PATCH /api/irrigation/systems/{id}/status
        Route::post('/schedules', [IrrigationController::class, 'storeSchedule']); // POST /api/irrigation/schedules
        Route::patch('/schedules/{schedule}', [IrrigationController::class, 'updateSchedule']); // PATCH /api/irrigation/schedules/{id}
        Route::delete('/schedules/{schedule}', [IrrigationController::class, 'destroySchedule']); // DELETE /api/irrigation/schedules/{id}
    });

    /*
    |--------------------------------------------------------------------------
    | Module 13: USSD Management (Authenticated)
    |--------------------------------------------------------------------------
    */
    
    // USSD Session Management
    Route::prefix('ussd')->group(function () {
        Route::get('/sessions', [\App\Http\Controllers\UssdController::class, 'getSessions']); // GET /api/ussd/sessions
        Route::get('/sessions/{sessionId}', [\App\Http\Controllers\UssdController::class, 'getSession']); // GET /api/ussd/sessions/{id}
        Route::get('/analytics', [\App\Http\Controllers\UssdController::class, 'getAnalytics']); // GET /api/ussd/analytics
        Route::post('/test-flow', [\App\Http\Controllers\UssdController::class, 'testFlow']); // POST /api/ussd/test-flow
        Route::get('/settings', [\App\Http\Controllers\UssdController::class, 'getSettings']);
        Route::put('/pin', [\App\Http\Controllers\UssdController::class, 'setPin']);
        Route::delete('/pin', [\App\Http\Controllers\UssdController::class, 'disablePin']);
    });

    /*
    |--------------------------------------------------------------------------
    | Weather & Environmental Data
    |--------------------------------------------------------------------------
    */
    Route::prefix('weather')->group(function () {
        Route::get('/current', [WeatherController::class, 'getCurrentWeather']); // GET /api/weather/current?lat=X&lon=Y
        Route::get('/forecast', [WeatherController::class, 'getForecast']); // GET /api/weather/forecast?lat=X&lon=Y
        Route::get('/data', [WeatherController::class, 'getWeatherData']); // GET /api/weather/data?lat=X&lon=Y
        Route::get('/insights', [WeatherController::class, 'getFarmingInsights']); // GET /api/weather/insights?lat=X&lon=Y
        Route::post('/cache/clear', [WeatherController::class, 'clearCache']); // POST /api/weather/cache/clear
        
        // Weather Alerts
        Route::prefix('alerts')->group(function () {
            Route::get('/', [WeatherAlertController::class, 'index']); // GET /api/weather/alerts
            Route::get('/active', [WeatherAlertController::class, 'active']); // GET /api/weather/alerts/active
            Route::post('/', [WeatherAlertController::class, 'store']); // POST /api/weather/alerts
            Route::post('/generate', [WeatherAlertController::class, 'generateAlerts']); // POST /api/weather/alerts/generate
            Route::patch('/{alertId}/acknowledge', [WeatherAlertController::class, 'acknowledge']); // PATCH /api/weather/alerts/{id}/acknowledge
            Route::patch('/{alertId}/dismiss', [WeatherAlertController::class, 'dismiss']); // PATCH /api/weather/alerts/{id}/dismiss
        });
    });
    
});

/*
|--------------------------------------------------------------------------
| Logistics Webhook Routes (Public)
|--------------------------------------------------------------------------
*/
Route::post('/logistics/webhook', [LogisticsController::class, 'webhook']); // POST /api/logistics/webhook

/*
|--------------------------------------------------------------------------
| Module 13: USSD Access Layer (Public Routes)
|--------------------------------------------------------------------------
*/
Route::post('/ussd/callback', [\App\Http\Controllers\UssdController::class, 'handleUssdRequest']); // POST /api/ussd/callback
Route::get('/ussd/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'USSD service is running',
        'timestamp' => now()->toISOString()
    ]);
}); // GET /api/ussd/test

/*
|--------------------------------------------------------------------------
| Utility Routes for Frontend Development
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Get available options for dropdowns
    Route::get('/options/farm-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'vegetables', 'label' => 'Vegetables'],
                ['value' => 'mixed', 'label' => 'Mixed Farming'],
                ['value' => 'dairy', 'label' => 'Dairy'],
                ['value' => 'poultry', 'label' => 'Poultry'],
                ['value' => 'crops', 'label' => 'Crops'],
                ['value' => 'livestock', 'label' => 'Livestock'],
                ['value' => 'fruits', 'label' => 'Fruits'],
                ['value' => 'herbs', 'label' => 'Herbs'],
                ['value' => 'flowers', 'label' => 'Flowers'],
                ['value' => 'other', 'label' => 'Other'],
            ]
        ]);
    });

    Route::get('/options/ownership-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'owned', 'label' => 'Owned'],
                ['value' => 'leased', 'label' => 'Leased'],
                ['value' => 'shared', 'label' => 'Shared'],
                ['value' => 'other', 'label' => 'Other'],
            ]
        ]);
    });

    Route::get('/options/size-units', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'acres', 'label' => 'Acres'],
                ['value' => 'hectares', 'label' => 'Hectares'],
                ['value' => 'square_meters', 'label' => 'Square Meters'],
            ]
        ]);
    });

    Route::get('/options/worker-roles', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'manager', 'label' => 'Farm Manager'],
                ['value' => 'worker', 'label' => 'Farm Worker'],
                ['value' => 'observer', 'label' => 'Observer'],
            ]
        ]);
    });

    Route::get('/options/employment-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'full_time', 'label' => 'Full Time'],
                ['value' => 'part_time', 'label' => 'Part Time'],
                ['value' => 'casual', 'label' => 'Casual Worker'],
                ['value' => 'contract', 'label' => 'Contract'],
            ]
        ]);
    });

    // Module 2: Crop Production Planning Options (moved to public section)

    Route::get('/options/health-statuses', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\CropCycle::getHealthStatusLabels())->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/task-types', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\CropTask::getTaskTypes())->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/yield-units', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'kg', 'label' => 'Kilograms'],
                ['value' => 'tons', 'label' => 'Tons'],
                ['value' => 'bags', 'label' => 'Bags (90kg)'],
                ['value' => 'pieces', 'label' => 'Pieces'],
                ['value' => 'crates', 'label' => 'Crates'],
                ['value' => 'bundles', 'label' => 'Bundles'],
            ]
        ]);
    });

    Route::get('/options/growth-stages', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\CropProgressLog::getGrowthStages())->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    // Module 3: Expense Ledger Options
    Route::get('/options/payment-methods', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'cash', 'label' => 'Cash'],
                ['value' => 'm_pesa', 'label' => 'M-Pesa'],
                ['value' => 'bank_transfer', 'label' => 'Bank Transfer'],
                ['value' => 'cheque', 'label' => 'Cheque'],
                ['value' => 'credit', 'label' => 'Credit/Loan'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
                ['value' => 'other', 'label' => 'Other'],
            ]
        ]);
    });

    Route::get('/options/expense-statuses', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'pending', 'label' => 'Pending Approval'],
                ['value' => 'rejected', 'label' => 'Rejected'],
                ['value' => 'draft', 'label' => 'Draft'],
            ]
        ]);
    });

    Route::get('/options/expense-units', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'kg', 'label' => 'Kilograms'],
                ['value' => 'g', 'label' => 'Grams'],
                ['value' => 'l', 'label' => 'Liters'],
                ['value' => 'ml', 'label' => 'Milliliters'],
                ['value' => 'bags', 'label' => 'Bags'],
                ['value' => 'packets', 'label' => 'Packets'],
                ['value' => 'bottles', 'label' => 'Bottles'],
                ['value' => 'pieces', 'label' => 'Pieces'],
                ['value' => 'hours', 'label' => 'Hours'],
                ['value' => 'days', 'label' => 'Days'],
            ]
        ]);
    });

    Route::get('/options/recurrence-patterns', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'weekly', 'label' => 'Weekly'],
                ['value' => 'monthly', 'label' => 'Monthly'],
                ['value' => 'quarterly', 'label' => 'Quarterly'],
                ['value' => 'yearly', 'label' => 'Yearly'],
                ['value' => 'every_2_weeks', 'label' => 'Every 2 Weeks'],
                ['value' => 'every_3_months', 'label' => 'Every 3 Months'],
                ['value' => 'every_6_months', 'label' => 'Every 6 Months'],
            ]
        ]);
    });

    // Module 4: Labour Management Options
    Route::get('/options/worker-roles', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'permanent', 'label' => 'Permanent Worker'],
                ['value' => 'casual', 'label' => 'Casual Worker'],
                ['value' => 'seasonal', 'label' => 'Seasonal Worker'],
                ['value' => 'contractor', 'label' => 'Contractor'],
            ]
        ]);
    });

    Route::get('/options/labour-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'weeding', 'label' => 'Weeding'],
                ['value' => 'bed_preparation', 'label' => 'Bed Preparation'],
                ['value' => 'transplanting', 'label' => 'Transplanting'],
                ['value' => 'watering', 'label' => 'Watering/Irrigation'],
                ['value' => 'spraying', 'label' => 'Spraying'],
                ['value' => 'fertilizer_application', 'label' => 'Fertilizer Application'],
                ['value' => 'harvest_labour', 'label' => 'Harvest Labour'],
                ['value' => 'general_work', 'label' => 'General Work'],
                ['value' => 'fence_repair', 'label' => 'Fence/Repair Work'],
                ['value' => 'misc', 'label' => 'Miscellaneous'],
            ]
        ]);
    });

    Route::get('/options/payment-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'daily', 'label' => 'Daily Rate'],
                ['value' => 'piece_rate', 'label' => 'Piece Rate'],
                ['value' => 'group_labour', 'label' => 'Group Labour'],
                ['value' => 'multi_day', 'label' => 'Multi-Day Labour'],
            ]
        ]);
    });

    Route::get('/options/labour-statuses', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'pending', 'label' => 'Pending Approval'],
                ['value' => 'rejected', 'label' => 'Rejected'],
            ]
        ]);
    });

    Route::get('/options/payment-statuses', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'paid', 'label' => 'Paid'],
                ['value' => 'unpaid', 'label' => 'Unpaid'],
                ['value' => 'partial', 'label' => 'Partially Paid'],
            ]
        ]);
    });

    // Module 5: Inventory Management Options
    Route::get('/options/inventory-categories', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'chemical', 'label' => 'Chemical'],
                ['value' => 'fertilizer', 'label' => 'Fertilizer'],
                ['value' => 'seed', 'label' => 'Seed'],
                ['value' => 'seedling', 'label' => 'Seedling'],
                ['value' => 'tool', 'label' => 'Tool'],
                ['value' => 'material', 'label' => 'Material'],
                ['value' => 'packaging', 'label' => 'Packaging'],
                ['value' => 'fuel', 'label' => 'Fuel'],
                ['value' => 'other', 'label' => 'Other'],
            ]
        ]);
    });

    Route::get('/options/inventory-units', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'kg', 'label' => 'Kilograms'],
                ['value' => 'g', 'label' => 'Grams'],
                ['value' => 'l', 'label' => 'Liters'],
                ['value' => 'ml', 'label' => 'Milliliters'],
                ['value' => 'bags', 'label' => 'Bags'],
                ['value' => 'packets', 'label' => 'Packets'],
                ['value' => 'bottles', 'label' => 'Bottles'],
                ['value' => 'pieces', 'label' => 'Pieces'],
                ['value' => 'sachets', 'label' => 'Sachets'],
                ['value' => 'boxes', 'label' => 'Boxes'],
                ['value' => 'meters', 'label' => 'Meters'],
                ['value' => 'rolls', 'label' => 'Rolls'],
            ]
        ]);
    });

    Route::get('/options/stock-movement-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'in', 'label' => 'Stock In'],
                ['value' => 'out', 'label' => 'Stock Out'],
                ['value' => 'adjustment', 'label' => 'Adjustment'],
                ['value' => 'expired_removal', 'label' => 'Expired Removal'],
            ]
        ]);
    });

    Route::get('/options/alert-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'low_stock', 'label' => 'Low Stock'],
                ['value' => 'out_of_stock', 'label' => 'Out of Stock'],
                ['value' => 'expiring_soon', 'label' => 'Expiring Soon'],
                ['value' => 'expired', 'label' => 'Expired'],
                ['value' => 'negative_stock', 'label' => 'Negative Stock'],
            ]
        ]);
    });

    Route::get('/options/alert-severities', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'high', 'label' => 'High'],
                ['value' => 'critical', 'label' => 'Critical'],
            ]
        ]);
    });

    // Module 6: Harvest Tracking Options
    Route::get('/options/harvest-grades', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'simple', 'label' => 'Simple Grading', 'options' => [
                    ['value' => 'marketable', 'label' => 'Marketable'],
                    ['value' => 'non_marketable', 'label' => 'Non-Marketable'],
                ]],
                ['value' => 'detailed', 'label' => 'Detailed Grading', 'options' => [
                    ['value' => 'A', 'label' => 'Grade A (Premium)'],
                    ['value' => 'B', 'label' => 'Grade B (Mixed)'],
                    ['value' => 'C', 'label' => 'Grade C (Low Quality)'],
                    ['value' => 'reject', 'label' => 'Rejects'],
                ]]
            ]
        ]);
    });

    Route::get('/options/harvest-units', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'kg', 'label' => 'Kilograms'],
                ['value' => 'tons', 'label' => 'Tons'],
                ['value' => 'bunches', 'label' => 'Bunches'],
                ['value' => 'bags', 'label' => 'Bags'],
                ['value' => 'crates', 'label' => 'Crates'],
                ['value' => 'pieces', 'label' => 'Pieces'],
                ['value' => 'baskets', 'label' => 'Baskets'],
                ['value' => 'sacks', 'label' => 'Sacks'],
            ]
        ]);
    });

    Route::get('/options/note-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'general', 'label' => 'General Note'],
                ['value' => 'health_condition', 'label' => 'Health Condition'],
                ['value' => 'pest_issue', 'label' => 'Pest Issue'],
                ['value' => 'weather_impact', 'label' => 'Weather Impact'],
                ['value' => 'quality_issue', 'label' => 'Quality Issue'],
                ['value' => 'anomaly', 'label' => 'Anomaly'],
                ['value' => 'improvement', 'label' => 'Improvement Suggestion'],
            ]
        ]);
    });

    Route::get('/options/allocation-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'sale', 'label' => 'Sale'],
                ['value' => 'storage', 'label' => 'Storage'],
                ['value' => 'personal_use', 'label' => 'Personal Use'],
                ['value' => 'loss', 'label' => 'Loss/Spoilage'],
            ]
        ]);
    });

    Route::get('/options/harvest-statuses', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'pending', 'label' => 'Pending Approval'],
                ['value' => 'rejected', 'label' => 'Rejected'],
            ]
        ]);
    });

    // Module 7: Sales & Income Tracking Options
    Route::get('/options/buyer-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'individual', 'label' => 'Individual'],
                ['value' => 'aggregator', 'label' => 'Aggregator'],
                ['value' => 'broker', 'label' => 'Broker'],
                ['value' => 'neighbour', 'label' => 'Neighbour'],
                ['value' => 'supermarket', 'label' => 'Supermarket'],
                ['value' => 'restaurant', 'label' => 'Restaurant'],
                ['value' => 'hotel', 'label' => 'Hotel'],
                ['value' => 'wholesaler', 'label' => 'Wholesaler'],
                ['value' => 'processor', 'label' => 'Processor'],
                ['value' => 'export_company', 'label' => 'Export Company'],
                ['value' => 'other', 'label' => 'Other'],
            ]
        ]);
    });

    Route::get('/options/sale-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'farmgate', 'label' => 'Farm Gate'],
                ['value' => 'market', 'label' => 'Market'],
                ['value' => 'door_to_door', 'label' => 'Door to Door'],
                ['value' => 'pickup', 'label' => 'Pickup'],
                ['value' => 'delivery', 'label' => 'Delivery'],
                ['value' => 'online', 'label' => 'Online'],
                ['value' => 'bulk_order', 'label' => 'Bulk Order'],
                ['value' => 'spot_sale', 'label' => 'Spot Sale'],
            ]
        ]);
    });

    Route::get('/options/deduction-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'transport_fare', 'label' => 'Transport Fare'],
                ['value' => 'airtime', 'label' => 'Airtime'],
                ['value' => 'packaging', 'label' => 'Packaging'],
                ['value' => 'labour', 'label' => 'Labour'],
                ['value' => 'handling_fees', 'label' => 'Handling Fees'],
                ['value' => 'market_fees', 'label' => 'Market Fees'],
                ['value' => 'commission', 'label' => 'Commission'],
                ['value' => 'taxes', 'label' => 'Taxes'],
                ['value' => 'storage', 'label' => 'Storage'],
                ['value' => 'loading_offloading', 'label' => 'Loading/Offloading'],
                ['value' => 'weighing', 'label' => 'Weighing'],
                ['value' => 'grading_sorting', 'label' => 'Grading & Sorting'],
                ['value' => 'other', 'label' => 'Other'],
            ]
        ]);
    });

    Route::get('/options/price-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'wholesale', 'label' => 'Wholesale'],
                ['value' => 'retail', 'label' => 'Retail'],
                ['value' => 'farmgate', 'label' => 'Farm Gate'],
            ]
        ]);
    });

    Route::get('/options/quality-grades', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'A', 'label' => 'Grade A (Premium)'],
                ['value' => 'B', 'label' => 'Grade B (Good)'],
                ['value' => 'C', 'label' => 'Grade C (Standard)'],
                ['value' => 'mixed', 'label' => 'Mixed Quality'],
                ['value' => 'unknown', 'label' => 'Unknown'],
            ]
        ]);
    });

    Route::get('/options/seasons', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'dry', 'label' => 'Dry Season'],
                ['value' => 'wet', 'label' => 'Wet Season'],
                ['value' => 'harvest', 'label' => 'Harvest Season'],
            ]
        ]);
    });

    Route::get('/options/payment-terms', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'immediate', 'label' => 'Immediate Payment'],
                ['value' => 'credit', 'label' => 'Credit Terms'],
            ]
        ]);
    });

    // Module 9: Field Observations & Insights Options
    Route::get('/options/observation-types', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\FieldObservation::OBSERVATION_TYPES)->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/observation-severities', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\FieldObservation::SEVERITY_LEVELS)->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/observation-statuses', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\FieldObservation::STATUSES)->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/observation-area-units', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'sqm', 'label' => 'Square Meters'],
                ['value' => 'hectares', 'label' => 'Hectares'],
                ['value' => 'acres', 'label' => 'Acres'],
            ]
        ]);
    });

    Route::get('/options/observation-tag-categories', function () {
        return response()->json([
            'success' => true,
            'data' => array_keys(\App\Models\ObservationTag::PREDEFINED_TAGS)
        ]);
    });

    // Module 11: Plot/Bed Mapping Options
    Route::get('/options/bed-types', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\Bed::BED_TYPES)->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/bed-statuses', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\Bed::STATUSES)->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/bed-note-types', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\BedNote::NOTE_TYPES)->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/bed-note-severities', function () {
        return response()->json([
            'success' => true,
            'data' => collect(\App\Models\BedNote::SEVERITIES)->map(function ($label, $value) {
                return ['value' => $value, 'label' => $label];
            })->values()
        ]);
    });

    Route::get('/options/soil-types', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'clay', 'label' => 'Clay'],
                ['value' => 'loam', 'label' => 'Loam'],
                ['value' => 'sandy', 'label' => 'Sandy'],
                ['value' => 'silt', 'label' => 'Silt'],
                ['value' => 'mixed', 'label' => 'Mixed'],
            ]
        ]);
    });

    Route::get('/options/drainage-qualities', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'poor', 'label' => 'Poor'],
                ['value' => 'fair', 'label' => 'Fair'],
                ['value' => 'good', 'label' => 'Good'],
                ['value' => 'excellent', 'label' => 'Excellent'],
            ]
        ]);
    });

    Route::get('/options/sun-exposures', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'full_sun', 'label' => 'Full Sun'],
                ['value' => 'partial_sun', 'label' => 'Partial Sun'],
                ['value' => 'shade', 'label' => 'Shade'],
                ['value' => 'partial_shade', 'label' => 'Partial Shade'],
            ]
        ]);
    });

    Route::get('/options/area-units', function () {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'square_meters', 'label' => 'Square Meters'],
                ['value' => 'acres', 'label' => 'Acres'],
                ['value' => 'hectares', 'label' => 'Hectares'],
            ]
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | Module 12: Financial Transactions & Cashflow (M-Pesa Integrated)
    |--------------------------------------------------------------------------
    */
    
    // Farm Wallet Management
    Route::prefix('wallet')->group(function () {
        Route::get('/', [FarmWalletController::class, 'show']); // GET /api/wallet
        Route::get('/dashboard', [FarmWalletController::class, 'dashboard']); // GET /api/wallet/dashboard
        Route::post('/update-balance', [FarmWalletController::class, 'updateBalance']); // POST /api/wallet/update-balance
        Route::post('/reconcile', [FarmWalletController::class, 'reconcile']); // POST /api/wallet/reconcile
        Route::post('/lock', [FarmWalletController::class, 'lock']); // POST /api/wallet/lock
        Route::post('/unlock', [FarmWalletController::class, 'unlock']); // POST /api/wallet/unlock
        Route::patch('/limits', [FarmWalletController::class, 'updateLimits']); // PATCH /api/wallet/limits
        Route::get('/cashflow-trend', [FarmWalletController::class, 'cashflowTrend']); // GET /api/wallet/cashflow-trend
        Route::get('/transactions', [FarmWalletController::class, 'transactions']); // GET /api/wallet/transactions
        Route::post('/snapshot', [FarmWalletController::class, 'createSnapshot']); // POST /api/wallet/snapshot
        Route::get('/snapshots', [FarmWalletController::class, 'snapshots']); // GET /api/wallet/snapshots
    });
    
    // Transaction Management
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']); // GET /api/transactions
        Route::post('/', [TransactionController::class, 'store']); // POST /api/transactions
        Route::get('/cashflow-summary', [TransactionController::class, 'cashflowSummary']); // GET /api/transactions/cashflow-summary
        Route::get('/outstanding-payments', [TransactionController::class, 'outstandingPayments']); // GET /api/transactions/outstanding-payments
        Route::get('/categories', [TransactionController::class, 'categories']); // GET /api/transactions/categories
        Route::get('/analytics', [TransactionController::class, 'analytics']); // GET /api/transactions/analytics
        
        Route::prefix('{transaction}')->group(function () {
            Route::get('/', [TransactionController::class, 'show']); // GET /api/transactions/{id}
            Route::patch('/', [TransactionController::class, 'update']); // PATCH /api/transactions/{id}
            Route::post('/approve', [TransactionController::class, 'approve']); // POST /api/transactions/{id}/approve
            Route::post('/void', [TransactionController::class, 'void']); // POST /api/transactions/{id}/void
            Route::post('/reconcile', [TransactionController::class, 'reconcile']); // POST /api/transactions/{id}/reconcile
        });
    });
    
    // Pending Payments Management
    Route::prefix('pending-payments')->group(function () {
        Route::get('/', [PendingPaymentController::class, 'index']); // GET /api/pending-payments
        Route::post('/', [PendingPaymentController::class, 'store']); // POST /api/pending-payments
        Route::get('/summary', [PendingPaymentController::class, 'summary']); // GET /api/pending-payments/summary
        Route::get('/analytics', [PendingPaymentController::class, 'analytics']); // GET /api/pending-payments/analytics
        Route::get('/due-payments', [PendingPaymentController::class, 'duePayments']); // GET /api/pending-payments/due-payments
        
        Route::prefix('{pendingPayment}')->group(function () {
            Route::get('/', [PendingPaymentController::class, 'show']); // GET /api/pending-payments/{id}
            Route::patch('/', [PendingPaymentController::class, 'update']); // PATCH /api/pending-payments/{id}
            Route::post('/approve-manager', [PendingPaymentController::class, 'approveByManager']); // POST /api/pending-payments/{id}/approve-manager
            Route::post('/approve-owner', [PendingPaymentController::class, 'approveByOwner']); // POST /api/pending-payments/{id}/approve-owner
            Route::post('/reject', [PendingPaymentController::class, 'reject']); // POST /api/pending-payments/{id}/reject
            Route::post('/execute', [PendingPaymentController::class, 'execute']); // POST /api/pending-payments/{id}/execute
        });
    });
    
    // M-Pesa Integration
    Route::prefix('mpesa')->group(function () {
        // M-Pesa STK Push
        Route::post('/stk-push', [MpesaController::class, 'stkPush']); // POST /api/mpesa/stk-push
        
        // M-Pesa B2C (Business to Customer)
        Route::post('/b2c', [MpesaController::class, 'businessToCustomer']); // POST /api/mpesa/b2c
        
        // M-Pesa Transaction Status
        Route::post('/transaction-status', [MpesaController::class, 'transactionStatus']); // POST /api/mpesa/transaction-status
        Route::get('/balance', [MpesaController::class, 'accountBalance']); // GET /api/mpesa/balance
        
        // M-Pesa Logs and Analytics
        Route::get('/logs', [MpesaController::class, 'logs']); // GET /api/mpesa/logs
        Route::get('/analytics', [MpesaController::class, 'analytics']); // GET /api/mpesa/analytics
    });

    // Utility Routes for Module 12
    Route::prefix('financial-utils')->group(function () {
        Route::get('/payment-methods', function () {
            return response()->json([
                'success' => true,
                'data' => [
                    ['value' => 'cash', 'label' => 'Cash'],
                    ['value' => 'mpesa', 'label' => 'M-Pesa'],
                    ['value' => 'bank_transfer', 'label' => 'Bank Transfer'],
                    ['value' => 'cheque', 'label' => 'Cheque'],
                    ['value' => 'mobile_money', 'label' => 'Mobile Money'],
                ]
            ]);
        });
        
        Route::get('/transaction-statuses', function () {
            return response()->json([
                'success' => true,
                'data' => [
                    ['value' => 'draft', 'label' => 'Draft'],
                    ['value' => 'pending_approval', 'label' => 'Pending Approval'],
                    ['value' => 'approved', 'label' => 'Approved'],
                    ['value' => 'processing', 'label' => 'Processing'],
                    ['value' => 'completed', 'label' => 'Completed'],
                    ['value' => 'failed', 'label' => 'Failed'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                    ['value' => 'partially_paid', 'label' => 'Partially Paid'],
                    ['value' => 'refunded', 'label' => 'Refunded'],
                ]
            ]);
        });
        
        Route::get('/payment-priorities', function () {
            return response()->json([
                'success' => true,
                'data' => [
                    ['value' => 'low', 'label' => 'Low'],
                    ['value' => 'medium', 'label' => 'Medium'],
                    ['value' => 'high', 'label' => 'High'],
                    ['value' => 'urgent', 'label' => 'Urgent'],
                ]
            ]);
        });
    });
    

});

/*
|--------------------------------------------------------------------------
| M-Pesa Callback Routes (No Authentication Required)
|--------------------------------------------------------------------------
*/
Route::prefix('mpesa/callbacks')->group(function () {
    Route::post('/c2b/confirmation', [MpesaController::class, 'c2bConfirmation'])->name('mpesa.c2b-confirmation');
    Route::post('/c2b/validation', [MpesaController::class, 'c2bValidation'])->name('mpesa.c2b-validation');
    Route::post('/stk-callback', [MpesaController::class, 'stkCallback'])->name('mpesa.stk-callback');
    Route::post('/b2c-callback', [MpesaController::class, 'b2cCallback'])->name('mpesa.b2c-callback');
    Route::post('/b2c-timeout', function(Request $request) {
        Log::info('M-Pesa B2C Timeout Callback', $request->all());
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Timeout processed']);
    })->name('mpesa.b2c-timeout');
    Route::post('/status-callback', function(Request $request) {
        Log::info('M-Pesa Status Callback', $request->all());
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Status callback processed']);
    })->name('mpesa.status-callback');
    Route::post('/status-timeout', function(Request $request) {
        Log::info('M-Pesa Status Timeout', $request->all());
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Timeout processed']);
    })->name('mpesa.status-timeout');
    Route::post('/balance-callback', function(Request $request) {
        Log::info('M-Pesa Balance Callback', $request->all());
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Balance callback processed']);
    })->name('mpesa.balance-callback');
    Route::post('/balance-timeout', function(Request $request) {
        Log::info('M-Pesa Balance Timeout', $request->all());
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Timeout processed']);
    })->name('mpesa.balance-timeout');
});

/*
|--------------------------------------------------------------------------
| Admin Routes (Future)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Future admin routes for tenant management, user administration, etc.
    // Route::get('/tenants', [AdminController::class, 'getTenants']);
    // Route::post('/tenants/{id}/provision', [AdminController::class, 'provisionTenant']);
});

/*
|--------------------------------------------------------------------------
| Fallback Route
|--------------------------------------------------------------------------
*/
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found. Please check your request URL.',
        'available_endpoints' => [
            'POST /api/auth/register',
            'POST /api/auth/login',
            'GET /api/farms',
            'POST /api/farms',
            'GET /api/farms/{id}',
            'POST /api/farms/{id}/switch',
            'POST /api/farms/{id}/workers',
            'GET /api/crops',
            'POST /api/crops',
            'GET /api/crops/{id}',
            'POST /api/crops/{id}/status',
            'POST /api/crops/{id}/tasks',
            'GET /api/tasks',
            'POST /api/tasks/{id}/start',
            'POST /api/tasks/{id}/complete',
            'GET /api/expenses',
            'POST /api/expenses',
            'POST /api/expenses/bulk',
            'POST /api/expenses/{id}/approve',
            'GET /api/expense-categories',
            'POST /api/expense-categories',
            'GET /api/workers',
            'POST /api/workers',
            'GET /api/workers/{id}',
            'POST /api/workers/{id}/activate',
            'GET /api/labour',
            'POST /api/labour',
            'POST /api/labour/bulk',
            'POST /api/labour/{id}/approve',
            'POST /api/labour/{id}/mark-paid',
            'GET /api/inventory',
            'POST /api/inventory',
            'GET /api/inventory/{id}',
            'POST /api/inventory/{id}/stock-in',
            'POST /api/inventory/{id}/stock-out',
            'GET /api/stock-movements',
            'GET /api/inventory-alerts',
            'POST /api/inventory-alerts/bulk-acknowledge',
        ]
    ], 404);
});
