<?php

use App\Enums\ShiftDecision;
use App\Models\Shift;
use App\Models\User;
use App\Services\InspectionPhotoSubmission;
use App\Services\ShiftWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['filesystems.disks.inspection-photos.root' => $argv[9]]);
$actor = User::findOrFail((int) $argv[3]);
$shift = Shift::findOrFail((int) $argv[4]);
file_put_contents($argv[2].'/ready-'.$argv[1], 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($argv[2].'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    if ($argv[5] === 'upload') {
        $app->make(InspectionPhotoSubmission::class)->submit(
            $actor, $shift->checks()->firstOrFail(),
            new UploadedFile($argv[10], 'race.png', 'image/png', null, true), $argv[7],
        );
    } else {
        $decision = ShiftDecision::from($argv[5]);
        $app->make(ShiftWorkflow::class)->act($actor, $shift, $decision, [
            'revision' => (int) $argv[6], 'request_key' => $argv[7],
            ...($decision !== ShiftDecision::Complete ? ['comment' => $argv[8]] : []),
        ]);
    }
    echo 'recorded';
} catch (ValidationException|AuthorizationException) {
    echo 'protected';
}
