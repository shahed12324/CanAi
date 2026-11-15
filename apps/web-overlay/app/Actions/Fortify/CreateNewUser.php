<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Tenancy\CreateWorkspace;
use App\Models\User;
use App\Support\SystemSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    public function __construct(private readonly CreateWorkspace $workspaces) {}

    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        abort_unless(SystemSettings::bool('system.signup_enabled', true), 403, 'New registration is closed right now.');

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
            $this->workspaces->personal($user);

            return $user;
        });
    }
}
