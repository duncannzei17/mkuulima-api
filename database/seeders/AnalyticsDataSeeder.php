<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Farm;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestSalesAllocation;
use App\Models\Transaction;
use App\Models\InventoryItem;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AnalyticsDataSeeder extends Seeder
{
    private $demoFarmerId;
    private $demoFarmId;
    private $startDate;
    private $crops = [
        'Maize' => ['price_per_kg' => 45, 'expected_yield_per_acre' => 2800],
        'Beans' => ['price_per_kg' => 85, 'expected_yield_per_acre' => 1200],
        'Tomatoes' => ['price_per_kg' => 65, 'expected_yield_per_acre' => 15000],
        'Kale' => ['price_per_kg' => 25, 'expected_yield_per_acre' => 8000],
        'Cabbage' => ['price_per_kg' => 35, 'expected_yield_per_acre' => 12000]
    ];

    public function run(): void
    {
        $this->findOrCreateDemoData();
        $this->seedCropCycles();
        $this->seedInventoryData();
        // Skip labour data as tables don't exist
        // $this->seedLabourData();
        // Skip expense data - use transactions instead
        // $this->seedExpenseData();
        $this->seedHarvestData();
        $this->seedSalesData();
        // $this->seedTransactionData(); // Skip for now due to category constraints
        
        $this->command->info('✅ Analytics data seeding completed successfully!');
        $this->command->info("📊 Generated realistic data for 3-month period from {$this->startDate->format('Y-m-d')}");
    }

    private function findOrCreateDemoData(): void
    {
        // Find or create demo farmer
        $farmer = User::where('phone', '+254700123456')->first();
        if (!$farmer) {
            $this->command->error('Demo farmer not found. Please run DemoFarmerSeeder first.');
            return;
        }
        
        $this->demoFarmerId = $farmer->id;
        
        // Get demo farm
        $farm = $farmer->ownedFarms()->first();
        if (!$farm) {
            $this->command->error('Demo farm not found. Please run DemoFarmerSeeder first.');
            return;
        }
        
        $this->demoFarmId = $farm->id;
        $this->startDate = Carbon::now()->subMonths(3);
        
        $this->command->info("📍 Using Demo Farm: {$farm->name} (ID: {$this->demoFarmId})");
    }

    private function seedCropCycles(): void
    {
        $this->command->info('🌱 Seeding crop cycles...');
        
        foreach ($this->crops as $cropName => $details) {
            $areaPlanted = rand(2, 8); // 2-8 acres
            CropCycle::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'farm_id' => $this->demoFarmId,
                'crop_name' => $cropName,
                'variety' => $this->getCropVariety($cropName),
                'land_area' => $areaPlanted,
                'land_area_unit' => 'acres',
                'start_date' => $this->startDate->copy()->addDays(rand(0, 30)),
                'expected_harvest_date' => $this->startDate->copy()->addDays(rand(90, 120)),
                'expected_yield_kg' => $details['expected_yield_per_acre'] * $areaPlanted,
                'season_status' => ['growing', 'harvest', 'completed'][array_rand(['growing', 'harvest', 'completed'])],
                'notes' => "Planted {$areaPlanted} acres of {$cropName} variety {$this->getCropVariety($cropName)}",
                'health_rating' => rand(7, 10),
                'growth_progress' => rand(60, 95),
                'created_at' => $this->startDate->copy()->addDays(rand(0, 30)),
                'updated_at' => now()
            ]);
        }
    }

    private function seedInventoryData(): void
    {
        $this->command->info('📦 Seeding inventory items...');
        
        $inventoryItems = [
            ['name' => 'Maize Seeds (DH04)', 'category' => 'seeds', 'unit' => 'kg', 'cost_per_unit' => 850, 'quantity' => 25],
            ['name' => 'Bean Seeds (Rosecoco)', 'category' => 'seeds', 'unit' => 'kg', 'cost_per_unit' => 180, 'quantity' => 15],
            ['name' => 'Tomato Seedlings (Anna F1)', 'category' => 'seedlings', 'unit' => 'pieces', 'cost_per_unit' => 12, 'quantity' => 500],
            ['name' => 'DAP Fertilizer', 'category' => 'fertilizers', 'unit' => 'bags', 'cost_per_unit' => 3200, 'quantity' => 10],
            ['name' => 'CAN Fertilizer', 'category' => 'fertilizers', 'unit' => 'bags', 'cost_per_unit' => 2800, 'quantity' => 8],
            ['name' => 'NPK Fertilizer', 'category' => 'fertilizers', 'unit' => 'bags', 'cost_per_unit' => 3500, 'quantity' => 6],
            ['name' => 'Farmyard Manure', 'category' => 'fertilizers', 'unit' => 'tonnes', 'cost_per_unit' => 1500, 'quantity' => 20],
            ['name' => 'Pesticide (Thunder 143)', 'category' => 'chemicals', 'unit' => 'liters', 'cost_per_unit' => 1200, 'quantity' => 5],
            ['name' => 'Fungicide (Ridomil Gold)', 'category' => 'chemicals', 'unit' => 'kg', 'cost_per_unit' => 2500, 'quantity' => 3],
            ['name' => 'Hand Hoes', 'category' => 'tools', 'unit' => 'pieces', 'cost_per_unit' => 450, 'quantity' => 12],
            ['name' => 'Wheelbarrow', 'category' => 'tools', 'unit' => 'pieces', 'cost_per_unit' => 8500, 'quantity' => 2],
            ['name' => 'Water Pump', 'category' => 'tools', 'unit' => 'pieces', 'cost_per_unit' => 25000, 'quantity' => 1],
        ];

        foreach ($inventoryItems as $item) {
            InventoryItem::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'farm_id' => $this->demoFarmId,
                'name' => $item['name'],
                'category' => $item['category'],
                'unit' => $item['unit'],
                'current_quantity' => $item['quantity'],
                'min_quantity' => max(1, (int)($item['quantity'] * 0.2)), // 20% of current as minimum
                'cost_per_unit' => $item['cost_per_unit'],
                'supplier' => $this->getRandomSupplier(),
                'created_at' => $this->startDate->copy()->addDays(rand(1, 20)),
                'updated_at' => now()
            ]);
        }
    }

    private function seedLabourData(): void
    {
        $this->command->info('👨‍🌾 Seeding labour data...');
        
        // Create workers first
        $workers = [
            ['name' => 'John Kimani', 'type' => 'permanent', 'rate' => 500],
            ['name' => 'Mary Wanjiku', 'type' => 'permanent', 'rate' => 500],
            ['name' => 'Peter Mwangi', 'type' => 'casual', 'rate' => 350],
            ['name' => 'Grace Nyambura', 'type' => 'casual', 'rate' => 350],
            ['name' => 'David Kariuki', 'type' => 'specialist', 'rate' => 800],
        ];

        $workerIds = [];
        foreach ($workers as $worker) {
            $w = Worker::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'farm_id' => $this->demoFarmId,
                'name' => $worker['name'],
                'worker_type' => $worker['type'],
                'phone' => '+2547' . rand(10000000, 99999999),
                'daily_rate' => $worker['rate'],
                'status' => 'active',
                'hire_date' => $this->startDate->copy()->subDays(rand(30, 90)),
                'created_at' => $this->startDate->copy(),
                'updated_at' => now()
            ]);
            $workerIds[] = $w->id;
        }

        // Create labour entries over the period
        $activities = ['Land Preparation', 'Planting', 'Weeding', 'Fertilizer Application', 'Pest Control', 'Harvesting'];
        
        for ($i = 0; $i < 60; $i++) { // 60 labour entries over 3 months
            $workDate = $this->startDate->copy()->addDays(rand(1, 90));
            $workerId = $workerIds[array_rand($workerIds)];
            $worker = Worker::find($workerId);
            
            LabourEntry::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'farm_id' => $this->demoFarmId,
                'worker_id' => $workerId,
                'work_date' => $workDate,
                'activity' => $activities[array_rand($activities)],
                'hours_worked' => rand(6, 10),
                'amount' => $worker->daily_rate,
                'status' => rand(1, 10) > 2 ? 'approved' : 'pending', // 80% approved
                'created_at' => $workDate,
                'updated_at' => $workDate->copy()->addHours(rand(1, 24))
            ]);
        }
    }

    private function seedExpenseData(): void
    {
        $this->command->info('💳 Seeding expense data...');
        
        $expenseCategories = [
            'Seeds & Seedlings' => [1200, 5000],
            'Fertilizers' => [2500, 8000],
            'Chemicals & Pesticides' => [800, 3500],
            'Labour' => [500, 2000],
            'Equipment' => [1000, 15000],
            'Transport' => [300, 1500],
            'Utilities' => [500, 2500],
            'Miscellaneous' => [200, 1000]
        ];

        for ($i = 0; $i < 50; $i++) { // 50 expenses over 3 months
            $category = array_rand($expenseCategories);
            $amountRange = $expenseCategories[$category];
            $amount = rand($amountRange[0], $amountRange[1]);
            $expenseDate = $this->startDate->copy()->addDays(rand(1, 90));
            
            Expense::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'farm_id' => $this->demoFarmId,
                'category' => $category,
                'amount' => $amount,
                'description' => $this->getExpenseDescription($category),
                'expense_date' => $expenseDate,
                'payment_method' => ['cash', 'mpesa', 'bank', 'credit'][array_rand(['cash', 'mpesa', 'bank', 'credit'])],
                'vendor' => $this->getRandomSupplier(),
                'status' => rand(1, 10) > 1 ? 'approved' : 'pending', // 90% approved
                'created_at' => $expenseDate,
                'updated_at' => $expenseDate->copy()->addHours(rand(1, 24))
            ]);
        }
    }

    private function seedHarvestData(): void
    {
        $this->command->info('🌾 Seeding harvest data...');
        
        $cropCycles = CropCycle::where('farm_id', $this->demoFarmId)->get();
        
        foreach ($cropCycles as $cropCycle) {
            // Skip crops not in our predefined list
            if (!isset($this->crops[$cropCycle->crop_name])) {
                continue;
            }
            $cropInfo = $this->crops[$cropCycle->crop_name];
            $baseYield = $cropInfo['expected_yield_per_acre'] * $cropCycle->land_area;
            
            // Generate multiple harvests for each crop (simulating periodic harvesting)
            $harvestCount = in_array($cropCycle->crop_name, ['Tomatoes', 'Kale']) ? rand(4, 8) : rand(1, 3);
            
            for ($i = 0; $i < $harvestCount; $i++) {
                $harvestDate = $cropCycle->expected_harvest_date->copy()->addDays(rand(-10, 30));
                $actualYield = $baseYield * (rand(70, 130) / 100) / $harvestCount; // 70-130% of expected, divided by number of harvests
                
                $harvest = Harvest::create([
                    'id' => \Illuminate\Support\Str::uuid(),
                    'crop_cycle_id' => $cropCycle->id,
                    'harvest_date' => $harvestDate,
                    'total_quantity' => round($actualYield, 2),
                    'expected_quantity' => round($baseYield / $harvestCount, 2),
                    'unit' => 'kg',
                    'grade_type' => 'simple',
                    'simple_grade' => ['marketable', 'non_marketable'][array_rand(['marketable', 'non_marketable'])],
                    'variance_percentage' => round((($actualYield - ($baseYield / $harvestCount)) / ($baseYield / $harvestCount)) * 100, 2),
                    'status' => 'approved',
                    'notes' => 'Good harvest quality. Weather conditions favorable.',
                    'is_final_harvest' => $i === ($harvestCount - 1), // Mark last harvest as final
                    'created_by' => $this->demoFarmerId,
                    'created_at' => $harvestDate,
                    'updated_at' => $harvestDate->copy()->addHours(rand(1, 12))
                ]);
            }
        }
    }

    private function seedSalesData(): void
    {
        $this->command->info('💰 Seeding sales data...');
        
        $harvests = Harvest::join('crop_cycles', 'harvests.crop_cycle_id', '=', 'crop_cycles.id')
            ->where('crop_cycles.farm_id', $this->demoFarmId)
            ->select('harvests.*')
            ->get();
        
        foreach ($harvests as $harvest) {
            $cropCycle = CropCycle::find($harvest->crop_cycle_id);
            $cropInfo = $this->crops[$cropCycle->crop_name];
            
            // Not all harvests are sold immediately, simulate realistic sales patterns
            if (rand(1, 10) <= 8) { // 80% of harvests get sold
                $quantitySold = $harvest->total_quantity * (rand(70, 100) / 100); // Sell 70-100% of harvest
                $pricePerKg = $cropInfo['price_per_kg'] * (rand(85, 115) / 100); // Price variation ±15%
                $totalValue = $quantitySold * $pricePerKg;
                
                $saleDate = $harvest->harvest_date->copy()->addDays(rand(1, 14)); // Sell within 1-14 days
                
                HarvestSalesAllocation::create([
                    'id' => \Illuminate\Support\Str::uuid(),
                    'harvest_id' => $harvest->id,
                    'allocation_type' => 'sale',
                    'allocated_quantity' => round($quantitySold, 2),
                    'unit' => 'kg',
                    'destination' => $this->getRandomBuyer(),
                    'price_per_unit' => round($pricePerKg, 2),
                    'total_value' => round($totalValue, 2),
                    'allocation_date' => $saleDate,
                    'status' => rand(1, 10) > 2 ? 'delivered' : 'pending', // 80% delivered
                    'notes' => 'Market price good. Quality as expected.',
                    'created_by' => $this->demoFarmerId,
                    'created_at' => $saleDate,
                    'updated_at' => $saleDate->copy()->addHours(rand(1, 24))
                ]);
            }
        }
    }

    private function seedTransactionData(): void
    {
        $this->command->info('🏦 Seeding transaction data...');
        
        // Create income transactions from sales
        $sales = HarvestSalesAllocation::where('status', 'delivered')->get();
        foreach ($sales as $sale) {
            Transaction::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'transaction_reference' => 'TXN-' . date('Ymd') . '-' . strtoupper(substr(md5($sale->id), 0, 6)),
                'transaction_date' => $sale->allocation_date->copy()->addDays(rand(0, 7)),
                'type' => 'credit',
                'amount' => $sale->total_value,
                'net_amount' => $sale->total_value,
                'category' => 'income',
                'description' => "Payment for {$sale->destination} - " . CropCycle::find(Harvest::find($sale->harvest_id)->crop_cycle_id)->crop_name,
                'payment_method' => ['mpesa', 'bank', 'cash'][array_rand(['mpesa', 'bank', 'cash'])],
                'status' => 'approved',
                'created_by_user_id' => $this->demoFarmerId,
                'created_at' => $sale->allocation_date,
                'updated_at' => $sale->allocation_date->copy()->addHours(rand(1, 24))
            ]);
        }

        // Create expense transactions directly (since no Expense table)
        $expenseCategories = [
            'expense' => [1200, 5000],
            'supplies' => [2500, 8000],
            'equipment' => [800, 3500],
            'labour' => [500, 2000],
            'transport' => [300, 1500],
            'utilities' => [500, 2500]
        ];

        for ($i = 0; $i < 50; $i++) { // 50 expense transactions over 3 months
            $category = array_rand($expenseCategories);
            $amountRange = $expenseCategories[$category];
            $amount = rand($amountRange[0], $amountRange[1]);
            $expenseDate = $this->startDate->copy()->addDays(rand(1, 90));
            
            Transaction::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'transaction_reference' => 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
                'transaction_date' => $expenseDate,
                'type' => 'debit',
                'amount' => $amount,
                'net_amount' => $amount,
                'category' => $category,
                'description' => $this->getExpenseDescription($category),
                'payment_method' => ['cash', 'mpesa', 'bank', 'credit'][array_rand(['cash', 'mpesa', 'bank', 'credit'])],
                'status' => 'approved',
                'created_by_user_id' => $this->demoFarmerId,
                'created_at' => $expenseDate,
                'updated_at' => $expenseDate->copy()->addHours(rand(1, 24))
            ]);
        }

        // Add some labour payment transactions directly
        for ($i = 0; $i < 40; $i++) { // 40 labour payments
            $workDate = $this->startDate->copy()->addDays(rand(1, 90));
            $paymentDate = $workDate->copy()->addDays(rand(1, 14));
            $amount = [350, 500, 800][array_rand([350, 500, 800])]; // Different worker rates
            
            Transaction::create([
                'id' => \Illuminate\Support\Str::uuid(),
                'transaction_reference' => 'LAB-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
                'transaction_date' => $paymentDate,
                'type' => 'debit',
                'amount' => $amount,
                'net_amount' => $amount,
                'category' => 'labour',
                'description' => "Labour payment - " . ['Land Preparation', 'Planting', 'Weeding', 'Harvesting'][array_rand(['Land Preparation', 'Planting', 'Weeding', 'Harvesting'])],
                'payment_method' => ['mpesa', 'cash'][array_rand(['mpesa', 'cash'])],
                'status' => 'approved',
                'created_by_user_id' => $this->demoFarmerId,
                'created_at' => $workDate,
                'updated_at' => $paymentDate
            ]);
        }
    }

    private function getCropVariety(string $crop): string
    {
        $varieties = [
            'Maize' => ['DH04', 'H614', 'H629', 'KH500-20A'],
            'Beans' => ['Rosecoco', 'Canadian Wonder', 'Mwezi Moja', 'KAT B1'],
            'Tomatoes' => ['Anna F1', 'Kilele F1', 'Eden F1', 'Money Maker'],
            'Kale' => ['Thousand Headed', 'Siberian Kale', 'Red Russian'],
            'Cabbage' => ['Gloria F1', 'Batavia F1', 'Copenhagen Market']
        ];
        
        return $varieties[$crop][array_rand($varieties[$crop])];
    }

    private function getRandomSupplier(): string
    {
        $suppliers = [
            'Amiran Kenya Ltd',
            'Kenya Seed Company',
            'Simlaw Seeds',
            'Monsanto Kenya',
            'East African Seed Co Ltd',
            'Western Seed Co Ltd',
            'Freshco Kenya',
            'Kenya Farmers Association',
            'Agricultural Development Corporation'
        ];
        
        return $suppliers[array_rand($suppliers)];
    }

    private function getRandomBuyer(): string
    {
        $buyers = [
            'Naivas Supermarket',
            'Carrefour Kenya',
            'Nakumatt Holdings',
            'Tuskys Supermarket',
            'Uchumi Supermarket',
            'Kiambu Fresh Market',
            'Central Market Traders',
            'Green Valley Exporters',
            'Farm Fresh Distributors',
            'Local Market Vendor'
        ];
        
        return $buyers[array_rand($buyers)];
    }

    private function getExpenseDescription(string $category): string
    {
        $descriptions = [
            'Seeds & Seedlings' => ['Hybrid maize seeds purchase', 'Bean seeds for planting', 'Tomato seedlings from nursery'],
            'Fertilizers' => ['DAP fertilizer for base dressing', 'CAN for top dressing', 'Organic compost purchase'],
            'Chemicals & Pesticides' => ['Pesticide for pest control', 'Fungicide application', 'Herbicide for weed control'],
            'Labour' => ['Casual labour for weeding', 'Harvesting labour payment', 'Land preparation work'],
            'Equipment' => ['Farm tools purchase', 'Equipment maintenance', 'New irrigation equipment'],
            'Transport' => ['Transport to market', 'Fertilizer delivery', 'Equipment transport'],
            'Utilities' => ['Electricity bill', 'Water charges', 'Phone communication'],
            'Miscellaneous' => ['Farm registration fees', 'Insurance payment', 'Veterinary services']
        ];
        
        $categoryDescriptions = $descriptions[$category] ?? ['General farm expense'];
        return $categoryDescriptions[array_rand($categoryDescriptions)];
    }
}