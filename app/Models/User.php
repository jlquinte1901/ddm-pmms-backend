<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['full_name', 'email', 'password', 'section'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Per-user permission grants. Grants only add access; they never remove
     * the permissions supplied by the user's role.
     */
    public function grantedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user')
            ->withPivot('granted_by');
    }

    public function projectsOwned(): HasMany
    {
        return $this->hasMany(Project::class, 'assigned_pe_id');
    }

    public function projectsCreated(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    public function documentsCreated(): HasMany
    {
        return $this->hasMany(Document::class, 'created_by');
    }

    public function documentsArchived(): HasMany
    {
        return $this->hasMany(Document::class, 'archived_by');
    }

    public function documentVersionsUploaded(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'uploaded_by');
    }

    public function downloadRequests(): HasMany
    {
        return $this->hasMany(DocumentDownloadRequest::class, 'requested_by');
    }

    public function downloadDecisions(): HasMany
    {
        return $this->hasMany(DocumentDownloadRequest::class, 'decided_by');
    }

    public function projectUpdates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class, 'reported_by');
    }

    public function projectHistoryEntries(): HasMany
    {
        return $this->hasMany(ProjectHistory::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
