<?php

namespace App\Models;

use App\Enums\PermissionName;
use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * The application's permissions are Spatie permissions. The module is derived
 * from the PermissionName enum (or the name prefix) because the Spatie schema
 * stores no module column.
 */
class Permission extends SpatiePermission
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    protected static function newFactory(): PermissionFactory
    {
        return PermissionFactory::new();
    }

    public function getModuleAttribute(): string
    {
        $known = PermissionName::tryFrom($this->name);

        if ($known !== null) {
            return $known->module();
        }

        $prefix = Str::before($this->name, '.');

        return in_array($prefix, ['ddm', 'pmms'], true) ? $prefix : 'shared';
    }
}
