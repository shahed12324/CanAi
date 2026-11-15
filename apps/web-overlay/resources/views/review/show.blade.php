<!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">
<title>View design</title>
<style>body{font-family:system-ui,"Noto Sans Bengali",sans-serif;background:#f3f4f6;margin:0;padding:16px;color:#111827}main{max-width:720px;margin:4vh auto;background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 4px #0002}img{max-width:100%;max-height:70vh;display:block;margin:12px auto;border:1px solid #e5e7eb;border-radius:8px}textarea{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:8px;padding:8px;font:inherit}button{border:0;border-radius:8px;padding:10px 18px;font-size:16px;cursor:pointer;color:#fff}.ok{background:#16a34a}.ch{background:#d97706}.note{padding:10px 12px;border-radius:8px;margin:10px 0}</style></head>
<body><main>
@if ($v === null)
    <h1 style="font-size:20px">{{ $message }}</h1>
    <p>Check that the link is correct, or tell the person who sent it.</p>
@else
    <h1 style="font-size:20px">{{ $v['title'] }}@if ($v['brand_name'] !== '') <small style="color:#6b7280">— {{ $v['brand_name'] }}</small>@endif</h1>
    @if ($v['client_name'] !== '')<p style="color:#6b7280;margin:0">{{ $v['client_name'] }}-For</p>@endif
    @if ($message)<p class="note" style="background:#ecfdf5;color:#065f46">{{ $message }}</p>@endif
    @if (! in_array($v['status'], ['revoked', 'expired'], true))<img src="{{ route('review.image', ['token' => $token]) }}" alt="Design">@endif
    @switch($v['status'])
        @case('pending')
            <form method="POST" action="{{ route('review.decide', ['token' => $token]) }}">@csrf
                <label for="c">If you want changes, write what to change (writing is not required for approval):</label>
                <textarea id="c" name="comment" rows="3" maxlength="1000"></textarea>
                <p style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px"><button class="ok" name="decision" value="approved">✔ I approve</button><button class="ch" name="decision" value="changes">✎ I want changes</button></p>
                <small style="color:#6b7280">A reply can be given only once. Expires: {{ \Illuminate\Support\Carbon::parse($v['expires_at'])->format('Y-m-d') }}</small>
            </form>@break
        @case('approved')<p class="note" style="background:#ecfdf5;color:#065f46">✔ You have approved this design.</p>@if ($v['comment'])<p>"{{ $v['comment'] }}"</p>@endif @break
        @case('changes')<p class="note" style="background:#fffbeb;color:#92400e">✎ You have requested changes. You will get a new link when a new design arrives.</p><p>"{{ $v['comment'] }}"</p>@break
        @case('expired')<p class="note" style="background:#f3f4f6">This link has expired.</p>@break
        @default<p class="note" style="background:#f3f4f6">This link has been disabled.</p>
    @endswitch
@endif
</main></body></html>
