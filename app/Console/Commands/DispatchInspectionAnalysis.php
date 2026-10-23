<?php

namespace App\Console\Commands;

use App\Models\InspectionAttempt;
use App\Services\InspectionAnalysis;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inspections:dispatch-analysis')]
#[Description('Recover pending or abandoned photo analysis jobs')]
class DispatchInspectionAnalysis extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(InspectionAnalysis $analysis): int
    {
        $count = 0;
        InspectionAttempt::query()->whereIn('status', ['uploaded', 'processing'])
            ->where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(2)))
            ->orderBy('id')->limit(100)->pluck('id')->each(function (int $id) use ($analysis, &$count): void {
                $count += (int) $analysis->enqueue($id);
            });
        $this->info('Analysis jobs queued: '.$count);

        return self::SUCCESS;
    }
}
