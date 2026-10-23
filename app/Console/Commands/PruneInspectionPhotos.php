<?php

namespace App\Console\Commands;

use App\Enums\ShiftStatus;
use App\Models\InspectionPhoto;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PruneInspectionPhotos extends Command
{
    protected $signature = 'inspections:prune-photos {--force : Delete eligible files; without this flag only report a count}';

    protected $description = 'Preview or apply photo retention while preserving attempt records and referenced examples';

    private function eligible(): Builder
    {
        return InspectionPhoto::query()->whereNull('purged_at')->where(function (Builder $query): void {
            $query->where(function (Builder $attempts): void {
                $attempts->where('purpose', 'attempt')
                    ->where('created_at', '<', now()->subDays(max(1, (int) config('inspections.photo_retention_days'))))
                    ->whereHas('attempt.check.shift', fn (Builder $shifts) => $shifts->whereIn('status', [
                        ShiftStatus::Completed, ShiftStatus::CompletedWithRemarks,
                    ]));
            })->orWhere(function (Builder $references): void {
                $references->where('purpose', 'reference')
                    ->where('created_at', '<', now()->subDays(max(1, (int) config('inspections.orphan_reference_days'))))
                    ->whereDoesntHave('templateItems')->whereDoesntHave('shiftChecks');
            });
        });
    }

    public function handle(AuditLogger $audit): int
    {
        $this->info('Eligible photos: '.$this->eligible()->count());
        if (! $this->option('force')) {
            $this->comment('Dry run. No files deleted. Use --force to apply the retention policy.');

            return self::SUCCESS;
        }
        $this->eligible()->select('inspection_photos.id')->chunkById(100, function ($photos) use ($audit): void {
            foreach ($photos as $candidate) {
                DB::transaction(function () use ($candidate, $audit): void {
                    DB::table('administration_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
                    $photo = $this->eligible()->whereKey($candidate->id)->lockForUpdate()->first();
                    if (! $photo) {
                        return;
                    }
                    $photo->forceFill(['purged_at' => now()])->save();
                    $audit->record('inspection.photo_purged', resource: $photo);
                    if (! Storage::disk('inspection-photos')->delete($photo->path)) {
                        throw new RuntimeException('Could not remove an expired inspection photo.');
                    }
                });
            }
        });
        $this->info('Retention applied. Attempt records and referenced examples were preserved.');

        return self::SUCCESS;
    }
}
