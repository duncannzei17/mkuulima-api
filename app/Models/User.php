<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasUuids;

    protected $fillable = [
        'name',
        'phone', // Primary identifier as per PRD
        'email',
        'county',
        'password',
        'profile_photo',
        'language',
        'timezone',
        'date_of_birth',
        'id_number',
        'emergency_contact',
        'status',
        'preferences'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'ussd_pin_hash',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'date_of_birth' => 'date',
        'emergency_contact' => 'array',
        'preferences' => 'array',
        'last_login_at' => 'datetime',
        'ussd_pin_enabled_at' => 'datetime',
        'ussd_locked_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'active',
        'language' => 'en',
        'timezone' => 'Africa/Nairobi',
    ];

    /**
     * Get the username field for authentication (phone instead of email)
     */
    public function username()
    {
        return 'phone';
    }

    /**
     * Override the default email field for password reset (use phone)
     */
    public function getEmailForPasswordReset()
    {
        return $this->phone;
    }

    // Relationships
    /**
     * Farms owned by this user
     */
    public function ownedFarms()
    {
        return $this->hasMany(Farm::class, 'user_id');
    }

    /**
     * Farms this user has access to (as worker/manager)
     */
    public function farms()
    {
        return $this->belongsToMany(Farm::class, 'farm_user')
                    ->wherePivotNull('deleted_at')
                    ->withPivot([
                        'role', 'permissions', 'status', 'hire_date', 'invited_by',
                        'invitation_sent_at', 'invitation_accepted_at',
                    ])
                    ->withTimestamps();
    }

    /**
     * All farms including owned and worker access
     */
    public function allFarms()
    {
        return $this->ownedFarms->merge($this->farms);
    }

    /**
     * Get farm-user pivot records
     */
    public function farmUsers()
    {
        return $this->hasMany(FarmUser::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByPhone($query, $phone)
    {
        return $query->where('phone', $phone);
    }

    // Business Logic Methods
    /**
     * Check if user owns a specific farm
     */
    public function ownsFarm($farmId)
    {
        return $this->ownedFarms()->where('id', $farmId)->exists();
    }

    /**
     * Check if user has access to a farm (owner or worker)
     */
    public function hasAccessToFarm($farmId)
    {
        return $this->ownsFarm($farmId) || 
               $this->farms()->where('farm_id', $farmId)->exists();
    }

    /**
     * Get user's role on a specific farm
     */
    public function getRoleOnFarm($farmId)
    {
        if ($this->ownsFarm($farmId)) {
            return 'owner';
        }

        $farmUser = $this->farms()->where('farm_id', $farmId)->first();
        return $farmUser ? $farmUser->pivot->role : null;
    }

    /**
     * Get user's permissions on a specific farm
     */
    public function getPermissionsOnFarm($farmId)
    {
        if ($this->ownsFarm($farmId)) {
            return ['*']; // Full permissions for owners
        }

        $farmUser = $this->farms()->where('farm_id', $farmId)->first();
        $permissions = $farmUser?->pivot->permissions;

        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true);
        }

        return is_array($permissions) ? $permissions : [];
    }

    /**
     * Check if user has specific permission on farm
     */
    public function hasPermissionOnFarm($farmId, $permission)
    {
        $permissions = $this->getPermissionsOnFarm($farmId);
        return in_array('*', $permissions) || in_array($permission, $permissions);
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin()
    {
        $this->last_login_at = now();
        $this->save();
    }

    // Static Methods
    /**
     * Find user by phone number
     */
    public static function findByPhone($phone)
    {
        return static::where('phone', $phone)->first();
    }

    /**
     * Check if phone number is already registered
     */
    public static function phoneExists($phone)
    {
        return static::where('phone', $phone)->exists();
    }

    /**
     * Permission constants for farm access
     */
    public const PERMISSIONS = [
        'view_dashboard' => 'View Dashboard',
        'manage_crops' => 'Manage Crops',
        'manage_expenses' => 'Manage Expenses',
        'view_expenses' => 'View Expenses',
        'manage_sales' => 'Manage Sales',
        'view_sales' => 'View Sales',
        'manage_labour' => 'Manage Labour',
        'add_labour' => 'Add Labour',
        'manage_inventory' => 'Manage Inventory',
        'add_harvest' => 'Add Harvest',
        'view_analytics' => 'View Analytics',
        'manage_workers' => 'Manage Workers',
        'manage_farm_settings' => 'Manage Farm Settings',
    ];

    /**
     * Default permissions by role
     */
    public static function getDefaultPermissions($role)
    {
        switch ($role) {
            case 'owner':
                return ['*']; // All permissions
            case 'manager':
                return [
                    'view_dashboard', 'manage_crops', 'view_expenses', 'manage_sales', 
                    'view_sales', 'manage_labour', 'add_labour', 'manage_inventory', 
                    'add_harvest', 'view_analytics'
                ];
            case 'worker':
                return [
                    'view_dashboard', 'add_labour', 'add_harvest'
                ];
            default:
                return [];
        }
    }
}
