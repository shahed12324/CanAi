<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The workspace's brand kit: name, colours, logo, contact lines. One default kit; it is what proposals are branded with. */
final class BrandKitController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->can('proposals.view'), 403);
        $kit = DB::selectOne('select id, name, primary_color, accent_color, tone, email, phone, website, address, (logo_b64 is not null) as has_logo, logo_b64 from brand_kits where is_default limit 1');

        return view('brand.show', ['kit' => $kit, 'canManage' => $request->user()->can('proposals.manage')]);
    }

    public function update(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('proposals.manage'), 403);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'primary_color' => ['required', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'accent_color' => ['required', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'tone' => ['nullable', 'string', 'max:300'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:200'],
            'address' => ['nullable', 'string', 'max:240'],
            'logo' => ['nullable', 'file', 'max:600', 'mimetypes:image/png,image/jpeg'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        $logo = null;
        $setLogo = false;
        if ($request->hasFile('logo')) {
            $raw = (string) file_get_contents($request->file('logo')->getRealPath());
            if (! (str_starts_with($raw, "\x89PNG\r\n\x1a\n") || str_starts_with($raw, "\xFF\xD8\xFF")) || @getimagesizefromstring($raw) === false) {
                return back()->withErrors(['logo' => 'The logo must be a PNG or JPEG image.'])->withInput();
            }
            $logo = base64_encode($raw);
            $setLogo = true;
        } elseif ($request->boolean('remove_logo')) {
            $setLogo = true;
        }

        $vals = [trim($d['name']), strtolower($d['primary_color']), strtolower($d['accent_color']), $d['tone'] ?? null, $d['email'] ?? null, $d['phone'] ?? null, $d['website'] ?? null, $d['address'] ?? null];
        $existing = DB::selectOne('select id from brand_kits where is_default limit 1');
        if ($existing === null) {
            DB::insert('insert into brand_kits (id, tenant_id, name, primary_color, accent_color, tone, email, phone, website, address, logo_b64, is_default) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true)',
                [CreateWorkspace::newId(), $tenant->idOrFail(), ...$vals, $logo]);
        } elseif ($setLogo) {
            DB::update('update brand_kits set name = ?, primary_color = ?, accent_color = ?, tone = ?, email = ?, phone = ?, website = ?, address = ?, logo_b64 = ?, updated_at = now() where id = ?', [...$vals, $logo, $existing->id]);
        } else {
            DB::update('update brand_kits set name = ?, primary_color = ?, accent_color = ?, tone = ?, email = ?, phone = ?, website = ?, address = ?, updated_at = now() where id = ?', [...$vals, $existing->id]);
        }

        return redirect()->route('brand.show')->with('status', 'Brand kit saved.');
    }
}
