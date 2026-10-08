<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

trait TenantScope
{
    protected static array $tenantScopeHasFarmId = [];

    protected static function bootTenantScope(): void
    {
        static::addGlobalScope('tenant_farm', function (Builder $builder) {
            $model = $builder->getModel();

            if (! static::tenantScopeShouldApply($model)) {
                return;
            }

            $farmId = static::tenantScopeFarmId();

            if ($farmId) {
                $builder->where($model->qualifyColumn('farm_id'), $farmId);
            }
        });

        static::creating(function ($model) {
            if (! static::tenantScopeShouldApply($model) || ! empty($model->farm_id)) {
                return;
            }

            $farmId = static::tenantScopeFarmId();

            if ($farmId) {
                $model->farm_id = $farmId;
            }
        });
    }

    protected static function tenantScopeFarmId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request->header('X-Tenant-ID')
            ?? $request->header('X-Farm-ID')
            ?? $request->header('X-Farm-Context')
            ?? $request->input('farm_id')
            ?? $request->user()?->current_farm_id
            ?? $request->user()?->farm_id;
    }

    protected static function tenantScopeShouldApply($model): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        $table = $model->getTable();

        if (! array_key_exists($table, static::$tenantScopeHasFarmId)) {
            try {
                static::$tenantScopeHasFarmId[$table] = Schema::hasColumn($table, 'farm_id');
            } catch (\Throwable) {
                static::$tenantScopeHasFarmId[$table] = false;
            }
        }

        return static::$tenantScopeHasFarmId[$table];
    }
}
