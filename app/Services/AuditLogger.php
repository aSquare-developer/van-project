<?php

namespace App\Services;

use App\Models\ActivityLog;

class AuditLogger
{
    public function record(string $event, ?int $actorId = null, ?int $subjectId = null, array $fields = []): void
    {
        ActivityLog::create([
            'event' => $event,
            'actor_id' => $actorId,
            'subject_id' => $subjectId,
            'metadata' => ['fields' => array_values(array_intersect($fields, ['name', 'email', 'role', 'is_active', 'password']))],
        ]);
    }
}
