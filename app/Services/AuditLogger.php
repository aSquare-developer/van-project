<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function record(string $event, ?int $actorId = null, ?int $subjectId = null, array $fields = [], ?Model $resource = null): void
    {
        ActivityLog::create([
            'event' => $event,
            'actor_id' => $actorId,
            'subject_id' => $subjectId,
            'metadata' => [
                'fields' => array_values(array_intersect($fields, ['name', 'email', 'role', 'is_active', 'password'])),
                ...($resource ? ['resource' => ['type' => $resource->getTable(), 'id' => $resource->getKey()]] : []),
            ],
        ]);
    }
}
