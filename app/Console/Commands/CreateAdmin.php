<?php

namespace App\Console\Commands;

use App\Services\UserManager;
use App\Support\PasswordRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {--name=} {--email=}';

    protected $description = 'Create the first administrator with a hidden password prompt';

    public function handle(UserManager $manager): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively to enter the password securely.');

            return self::FAILURE;
        }
        $data = [
            'name' => $this->option('name') ?: $this->ask('Name'),
            'email' => strtolower($this->option('email') ?: $this->ask('Email')),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        try {
            Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:254', 'unique:users,email'],
                'password' => ['required', 'confirmed', PasswordRules::rule()],
            ])->validate();
            $manager->bootstrap($data);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
