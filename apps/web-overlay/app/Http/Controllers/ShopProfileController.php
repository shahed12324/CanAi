<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tenancy\CreateWorkspace;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** The shop's public details: name, address, contact and logo. Anyone in the workspace may view; owners/admins edit. */
final class ShopProfileController extends Controller
{
    private const MAX_LOGO_BYTES = 1_048_576;      // 1 MB
    private const MAX_LOGO_SIDE = 2000;
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function show(Request $request, TenantContext $tenant)
    {
        $id = $tenant->idOrFail();
        $profile = DB::table('shop_profiles')->where('tenant_id', $id)->first();
        $workspace = DB::table('tenants')->where('id', $id)->first(['name']);

        return view('settings.shop', [
            'p' => $profile, 'defaultName' => $workspace->name ?? '',
            'canManage' => $request->user()->can('workspace.manage'),
            'hasLogo' => $profile?->logo_path !== null,
        ]);
    }

    public function save(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('workspace.manage'), 403);
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'min:2', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+()\- ]{5,32}$/'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'website' => ['nullable', 'url', 'max:200', 'regex:/^https?:\/\//i'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], ['phone.regex' => 'Phone may contain digits, spaces, + ( ) and - only.']);
        $clean = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);
        $clean['shop_name'] = $data['shop_name'];

        $id = $tenant->idOrFail();
        DB::table('shop_profiles')->upsert([['tenant_id' => $id, ...$clean, 'updated_at' => now()]], ['tenant_id'], [...array_keys($clean), 'updated_at']);

        return back()->with('status', 'Shop profile saved.');
    }

    public function uploadLogo(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('workspace.manage'), 403);
        $request->validate(['logo' => ['required', 'file', 'max:1024']]);
        $file = $request->file('logo');
        abort_unless($file !== null && $file->isValid(), 422);

        // Trust the bytes, not the file name or the browser's claim.
        $info = @getimagesize($file->getRealPath());
        $mime = is_array($info) ? ($info['mime'] ?? '') : '';
        if (! isset(self::TYPES[$mime]) || $file->getSize() > self::MAX_LOGO_BYTES || $info[0] > self::MAX_LOGO_SIDE || $info[1] > self::MAX_LOGO_SIDE || $info[0] < 32 || $info[1] < 32) {
            return back()->withErrors(['logo' => 'Use a PNG, JPG or WebP image, 32–2000 px, up to 1 MB.']);
        }

        $id = $tenant->idOrFail();
        $profile = DB::table('shop_profiles')->where('tenant_id', $id)->first();
        if ($profile === null) {
            return back()->withErrors(['logo' => 'Save the shop name first, then add the logo.']);
        }
        $path = 'shop-logos/'.$id.'/'.CreateWorkspace::newId().'.'.self::TYPES[$mime];
        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));
        DB::table('shop_profiles')->where('tenant_id', $id)->update(['logo_path' => $path, 'logo_mime' => $mime, 'updated_at' => now()]);
        if ($profile->logo_path !== null) {
            Storage::disk('local')->delete($profile->logo_path);
        }

        return back()->with('status', 'Logo updated.');
    }

    public function removeLogo(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('workspace.manage'), 403);
        $id = $tenant->idOrFail();
        $profile = DB::table('shop_profiles')->where('tenant_id', $id)->first();
        if ($profile?->logo_path !== null) {
            DB::table('shop_profiles')->where('tenant_id', $id)->update(['logo_path' => null, 'logo_mime' => null, 'updated_at' => now()]);
            Storage::disk('local')->delete($profile->logo_path);
        }

        return back()->with('status', 'Logo removed.');
    }

    /** Served through the app (never a public folder) so only members of the workspace can fetch it. */
    public function logo(TenantContext $tenant)
    {
        $profile = DB::table('shop_profiles')->where('tenant_id', $tenant->idOrFail())->first(['logo_path', 'logo_mime']);
        abort_if($profile === null || $profile->logo_path === null || ! Storage::disk('local')->exists($profile->logo_path), 404);

        return response(Storage::disk('local')->get($profile->logo_path), 200, [
            'Content-Type' => $profile->logo_mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300', 'Content-Disposition' => 'inline',
        ]);
    }
}
