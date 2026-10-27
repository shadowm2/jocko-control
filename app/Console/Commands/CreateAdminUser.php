<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('jocko:create-admin {--name= : Display name} {--email= : Login email}')]
#[Description('Create an administrator account (the password is always prompted for)')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email', required: true);

        $password = password(
            'Password (min. 12 characters)',
            required: true,
            validate: fn (string $value) => Validator::make(['password' => $value], ['password' => $this->passwordRules()])
                ->errors()->first('password') ?: null,
        );

        if ($password !== password('Confirm password', required: true)) {
            $this->components->error('The passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true]);

        $this->components->info("Administrator [{$email}] created.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, mixed>
     */
    private function passwordRules(): array
    {
        return ['required', 'string', Password::min(12)->letters()->numbers()];
    }
}
