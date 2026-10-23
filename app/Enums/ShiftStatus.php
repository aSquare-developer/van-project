<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case AwaitingReview = 'awaiting_review';
    case Completed = 'completed';
    case CompletedWithRemarks = 'completed_with_remarks';
    case ReturnedForCheck = 'returned_for_check';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned',
            self::InProgress => 'In progress',
            self::AwaitingReview => 'Awaiting review',
            self::Completed => 'Completed',
            self::CompletedWithRemarks => 'Completed with remarks',
            self::ReturnedForCheck => 'Returned for checking',
        };
    }
}
