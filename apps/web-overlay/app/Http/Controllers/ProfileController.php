<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProfileController extends Controller
{
    public const LOCALES = ['bn' => 'Bengali', 'en' => 'English'];

    public function show(Request $request)
    {
        return view('settings.profile', ['user' => $request->user(), 'locales' => self::LOCALES, 'zones' => \DateTimeZone::listIdentifiers()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'locale' => ['required', Rule::in(array_keys(self::LOCALES))],
            'timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers())],
        ]);
        // Email and password are deliberately not editable here: they need re-verification (password reset / email change flow).
        $request->user()->forceFill([
            'name' => trim(strip_tags($data['name'])), 'locale' => $data['locale'], 'timezone' => $data['timezone'],
        ])->save();

        return back()->with('status', 'Profile saved.');
    }
}
