<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FarmUser extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'farm_user';

    protected $fillable = [
        'user_id',
        'farm_id',
        'role',
        'permissions',
        'salary',
        'hourly_rate',
        'hire_date',
        'employment_type',
        'status',
        'responsibilities',
        'working_hours',
        'invited_by',
        'invitation_sent_at',
        'invitation_accepted_at',
        'metadata'
    ];

    protected $casts = [
        'permissions' => 'array',
        'salary' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'hire_date' => 'date',
        'working_hours' => 'array',
        'invitation_sent_at' => 'datetime',
        'invitation_accepted_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'role' => 'worker',
        'employment_type' => 'casual',
        'status' => 'active',
    ];

    // Role constants
    public const ROLES = [
        'owner' => 'Farm Owner',
        'manager' => 'Farm Manager',
        'worker' => 'Farm Worker',
        'observer' => 'Observer'
    ];

    public const EMPLOYMENT_TYPES = [
        'full_time' => 'Full Time',
        'part_time' => 'Part Time',
        'casual' => 'Casual Worker',
        'contract' => 'Contract'
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopeManagers($query)
    {
        return $query->where('role', 'manager');
    }

    public function scopeWorkers($query)
    {
        return $query->where('role', 'worker');
    }

    // Accessors
    public function getRoleNameAttribute()
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function getEmploymentTypeNameAttribute()
    {
        return self::EMPLOYMENT_TYPES[$this->employment_type] ?? $this->employment_type;
    }

    public function getFormattedSalaryAttribute()
    {
        return $this->salary ? 'KES ' . number_format($this->salary, 2) : null;
    }

    public function getFormattedHourlyRateAttribute()
    {
        return $this->hourly_rate ? 'KES ' . number_format($this->hourly_rate, 2) : null;
    }

    // Business Logic Methods
    public function hasPermission($permission)
    {
        if ($this->role === 'owner') {
            return true; // Owners have all permissions
        }

        $permissions = $this->permissions ?? [];
        return in_array('*', $permissions) || in_array($permission, $permissions);
    }

    public function grantPermission($permission)
    {
        $permissions = $this->permissions ?? [];
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->permissions = array_unique($permissions);
            $this->save();
        }
    }

    public function revokePermission($permission)
    {
        $permissions = $this->permissions ?? [];
        if (($key = array_search($permission, $permissions)) !== false) {
            unset($permissions[$key]);
            $this->permissions = array_values($permissions);
            $this->save();
        }
    }

    public function setRole($role)
    {
        $this->role = $role;
        $this->permissions = User::getDefaultPermissions($role);
        $this->save();
    }

    public function activate()
    {
        $this->status = 'active';
        $this->save();
    }

    public function deactivate()
    {
        $this->status = 'inactive';
        $this->save();
    }

    public function suspend($reason = null)
    {
        $this->status = 'suspended';
        $this->metadata = array_merge($this->metadata ?? [], [
            'suspension_reason' => $reason,
            'suspended_at' => now()->toISOString(),
        ]);
        $this->save();
    }

    public function acceptInvitation()
    {
        $this->invitation_accepted_at = now();
        $this->status = 'active';
        $this->save();
    }

    // Static utility methods
    public static function getRoleOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::ROLES), self::ROLES);
    }

    public static function getEmploymentTypeOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::EMPLOYMENT_TYPES), self::EMPLOYMENT_TYPES);
    }

    public static function createForUser($userId, $farmId, $role, $permissions = null, $invitedBy = null)
    {
        return self::create([
            'user_id' => $userId,
            'farm_id' => $farmId,
            'role' => $role,
            'permissions' => $permissions ?? User::getDefaultPermissions($role),
            'invited_by' => $invitedBy,
            'invitation_sent_at' => now(),
        ]);
    }
}