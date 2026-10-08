<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Services\GeocodingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixFarmCoordinates extends Command
{
    protected $signature = 'farm:fix-coordinates {--farm-id= : Specific farm ID to fix}';
    protected $description = 'Fix farm coordinates and location formatting';

    protected $geocodingService;

    public function __construct(GeocodingService $geocodingService)
    {
        parent::__construct();
        $this->geocodingService = $geocodingService;
    }

    public function handle()
    {
        $this->info('Starting farm coordinate fix...');

        if ($this->option('farm-id')) {
            $farm = Farm::find($this->option('farm-id'));
            if (!$farm) {
                $this->error('Farm not found with ID: ' . $this->option('farm-id'));
                return 1;
            }
            $this->fixFarm($farm);
        } else {
            $farms = Farm::all();
            $this->info("Processing {$farms->count()} farms...");
            
            foreach ($farms as $farm) {
                $this->fixFarm($farm);
            }
        }

        $this->info('Farm coordinate fix completed!');
        return 0;
    }

    protected function fixFarm(Farm $farm)
    {
        $this->info("Processing farm: {$farm->name} (ID: {$farm->id})");
        
        $needsUpdate = false;
        $updateData = [];

        // Fix location format if it's JSON
        if (is_array($farm->location) || $this->isJson($farm->location)) {
            $locationArray = is_array($farm->location) ? $farm->location : json_decode($farm->location, true);
            
            if (is_array($locationArray)) {
                $locationString = $this->buildLocationString($locationArray);
                $updateData['location'] = $locationString;
                $needsUpdate = true;
                $this->info("  - Updated location format: {$locationString}");
            }
        }

        // Fix coordinates if missing
        if (!$farm->latitude || !$farm->longitude) {
            $locationForGeocoding = $updateData['location'] ?? $farm->location;
            
            if (is_string($locationForGeocoding)) {
                $coordinates = $this->geocodingService->getCoordinatesFromLocation($locationForGeocoding);
                
                if ($coordinates) {
                    $updateData['latitude'] = $coordinates['latitude'];
                    $updateData['longitude'] = $coordinates['longitude'];
                    $needsUpdate = true;
                    $this->info("  - Added coordinates: {$coordinates['latitude']}, {$coordinates['longitude']}");
                }
            }
        }

        // Fix timezone if missing
        if ((!$farm->timezone || $farm->timezone === 'Africa/Nairobi') && ($farm->latitude || isset($updateData['latitude']))) {
            $lat = $updateData['latitude'] ?? $farm->latitude;
            $lng = $updateData['longitude'] ?? $farm->longitude;
            
            if ($lat && $lng) {
                $timezone = $this->geocodingService->getTimezoneFromCoordinates($lat, $lng);
                if ($timezone) {
                    $updateData['timezone'] = $timezone;
                    $needsUpdate = true;
                    $this->info("  - Updated timezone: {$timezone}");
                }
            }
        }

        if ($needsUpdate) {
            $farm->update($updateData);
            $this->info("  ✓ Farm updated successfully");
        } else {
            $this->info("  - No updates needed");
        }
    }

    protected function buildLocationString(array $location): string
    {
        $parts = [];
        
        // Build from specific to general: Village, Ward, County
        if (isset($location['village']) && !empty($location['village'])) {
            $parts[] = $location['village'];
        }
        
        if (isset($location['ward']) && !empty($location['ward'])) {
            $parts[] = $location['ward'];
        }
        
        if (isset($location['county']) && !empty($location['county'])) {
            $parts[] = $location['county'];
        }
        
        // Add Kenya if not already specified
        $locationStr = implode(', ', $parts);
        if (!str_contains(strtolower($locationStr), 'kenya')) {
            $locationStr .= ', Kenya';
        }
        
        return $locationStr;
    }

    protected function isJson($string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}