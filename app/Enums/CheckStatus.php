<?php

namespace App\Enums;

enum CheckStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case Inconclusive = 'inconclusive';
    case AnalysisError = 'analysis_error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not checked',
            self::Passed => 'Passed',
            self::Failed => 'Not passed',
            self::Inconclusive => 'Unable to verify',
            self::AnalysisError => 'Analysis error',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pending => 'This item has not been checked yet.',
            self::Passed => 'The required condition was confirmed.',
            self::Failed => 'The required condition was not met.',
            self::Inconclusive => 'The image did not provide enough information to verify this item.',
            self::AnalysisError => 'Analysis could not be completed. This does not mean the item is missing.',
        };
    }
}
