<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case ProjectEngineer = 'project_engineer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::ProjectEngineer => 'Project Engineer',
            self::Viewer => 'Viewer',
        };
    }
}
