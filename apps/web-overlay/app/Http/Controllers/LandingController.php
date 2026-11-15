<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SystemSettings;
use Illuminate\Support\Facades\DB;

/** Public front page: what the product does, plans (from Admin → Plans) and sign-in links. */
final class LandingController extends Controller
{
    public function show()
    {
        if (auth()->check()) {
            return redirect('/app');
        }

        return view('landing', ['plans' => $this->plans(), 'signup' => SystemSettings::bool('system.signup_enabled', true)]);
    }

    /** @return list<array{name:string,description:?string,price:?string,trial:int,credits:string,free:bool}> */
    private function plans(): array
    {
        try {
            $rows = DB::table('plans')->where('is_public', true)->where('status', 'active')->orderBy('sort')->limit(4)
                ->get(['id', 'name', 'description', 'is_lifetime_free', 'trial_days', 'included_credits']);
            $out = [];
            foreach ($rows as $p) {
                $price = DB::table('plan_prices')->where('plan_id', $p->id)->where('interval', 'month')->orderBy('currency')->first(['currency', 'amount']);
                $out[] = [
                    'name' => (string) $p->name,
                    'description' => $p->description,
                    'price' => $price === null ? null : (rtrim(rtrim(number_format((float) $price->amount, 2, '.', ','), '0'), '.').' '.$price->currency.' / month'),
                    'trial' => (int) $p->trial_days,
                    'credits' => rtrim(rtrim(number_format((float) $p->included_credits, 2, '.', ','), '0'), '.'),
                    'free' => (bool) $p->is_lifetime_free,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
