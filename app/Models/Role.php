<?php

namespace App\Models;

use App\Enums\RoleName;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The application's roles are Spatie roles. The display label is derived from
 * the RoleName enum (or the name) because the Spatie schema stores no label.
 */
class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }

    public function getLabelAttribute(): string
    {
        return RoleName::tryFrom($this->name)?->label() ?? Str::headline($this->name);
    }
}
