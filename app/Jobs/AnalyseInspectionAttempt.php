<?php

namespace App\Jobs;

use App\Services\InspectionAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyseInspectionAttempt implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public bool $failOnTimeout = true;

    public function __construct(public int $attemptId) {}

    /**
     * Execute the job.
     */
    public function handle(InspectionAnalysis $analysis): void
    {
        $analysis->run($this->attemptId);
    }
}
