<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Profile\CreateProviderProfile;
use App\Profile\ProfileDetails;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The only way to create a provider's account, now that nobody registers by themselves (issue
 * #58, docs/production.md). It creates the user, with the email already verified (the manager
 * typed it), and its provider, not published, with the same name and the city. The initial
 * password is random and written once in the output, never stored in clear nor logged: the
 * provider changes it from the settings (Security) or with "forgot password". Nothing forces the
 * change at the first login yet.
 */
#[Signature('provider:create
    {name : The name of the provider, also the name of their page}
    {email : The email of the account}
    {city : The city of the provider}')]
#[Description('Create a provider account (user and unpublished provider) with a random initial password')]
class CreateProviderCommand extends Command
{
    /**
     * The length of the initial password.
     */
    public const PASSWORD_LENGTH = 20;

    /**
     * Execute the console command.
     */
    public function handle(CreateProviderProfile $profiles): int
    {
        $data = [
            'name' => $this->text('name'),
            'email' => Str::lower($this->text('email')),
            'city' => $this->text('city'),
        ];

        // The same limits as the profile form (ProviderProfileRules) and a unique email.
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'city' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $password = Str::password(self::PASSWORD_LENGTH);

        $provider = DB::transaction(function () use ($data, $password, $profiles) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $password,
            ]);
            // The manager typed the address: verified at once (email_verified_at is not fillable).
            $user->markEmailAsVerified();

            return $profiles->create($user, new ProfileDetails($data['name'], $data['city']));
        });

        $this->info(__('console.provider_create.done', ['name' => $provider->name, 'slug' => $provider->slug]));
        $this->line(__('console.provider_create.password', ['password' => $password]));
        $this->warn(__('console.provider_create.change'));

        return self::SUCCESS;
    }

    /**
     * An argument of the command, trimmed; empty when it is not text.
     */
    private function text(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? trim($value) : '';
    }
}
