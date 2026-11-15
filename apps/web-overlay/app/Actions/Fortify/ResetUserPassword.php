<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    /** @param  array<string, mixed>  $input */
    public function reset($user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ])->validate();

        $user->forceFill(['password' => $input['password']])->save();
    }
}
