<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the system-wide, append-only audit trail. Callers are responsible for
 * wrapping this write in the same transaction as the change it records.
 */
final class AuditService
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $module,
        string $action,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        array $context = [],
        ?User $actor = null,
        ?int $userId = null,
    ): AuditLog {
        $request = request();
        $resolvedUserId = $userId ?? $actor?->getKey() ?? auth()->id();

        return AuditLog::query()->create([
            'user_id' => $resolvedUserId,
            'module' => $module,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $this->sanitize($oldValues) ?: null,
            'new_values' => $this->sanitize($newValues) ?: null,
            'context' => $this->sanitize($context) ?: null,
            'ip_address' => $request->ip(),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    /**
     * Remove values that must never reach the audit trail even if a caller
     * accidentally includes them.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sanitize(array $values): array
    {
        $forbiddenKeys = [
            'password',
            'password_confirmation',
            'remember_token',
            'token',
            'access_token',
            'plain_text_token',
            'secret',
        ];

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $forbiddenKeys, true)) {
                unset($values[$key]);

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->sanitize($value);
            }
        }

        return $values;
    }
}
