<?php

namespace App\Enums;

enum ShiftDecision: string
{
    case Complete = 'complete';
    case RequestReview = 'request_review';
    case ApproveWithRemarks = 'approve_with_remarks';
    case ReturnForCheck = 'return_for_check';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Completed by worker',
            self::RequestReview => 'Review requested',
            self::ApproveWithRemarks => 'Approved with remarks',
            self::ReturnForCheck => 'Returned for checking',
        };
    }

    public function isWorkerAction(): bool
    {
        return in_array($this, [self::Complete, self::RequestReview], true);
    }
}
