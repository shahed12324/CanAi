<!doctype html>
<html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Unsubscribed</title>
<style>body{font-family:system-ui,"Noto Sans Bengali",sans-serif;background:#f3f4f6;margin:0;padding:24px}main{max-width:420px;margin:12vh auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 1px 4px #0002}button{background:#4f46e5;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:16px;cursor:pointer}</style></head>
<body><main><h1 style="font-size:20px">No longer want emails?</h1>
<p>Press the button below and no more emails will go to this address.<br><small>Press the button to stop receiving emails from us.</small></p>
<form method="POST" action="{{ route('outreach.unsubscribe.confirm', ['token' => $token]) }}"><button type="submit">Unsubscribe</button></form></main></body></html>
