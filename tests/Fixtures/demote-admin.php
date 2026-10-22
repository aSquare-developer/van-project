<?php

use App\Models\User;
use App\Services\UserManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$user = User::findOrFail((int) $argv[1]);
file_put_contents($argv[2].'/ready-'.$user->id, 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($argv[2].'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    $app->make(UserManager::class)->update($user, $user->id, [
        'name' => $user->name, 'email' => $user->email, 'role' => 'user', 'is_active' => true,
    ]);
    echo 'changed';
} catch (ValidationException) {
    echo 'protected';
}
