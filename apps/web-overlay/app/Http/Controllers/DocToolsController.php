<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DocTools\Catalog;
use App\DocTools\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Document and photo tools: scans, PDFs, passport photos, OCR, cards. Nothing is stored: the files go to the engine and the result comes straight back.
 * Engine tools are free (rate limited: conversion is CPU work); the two AI tools (handwriting, translation) are charged in credits by their services.
 */
final class DocToolsController extends Controller
{
    private const MAX_FILE_KB = 25000;

    public function __construct(private readonly ToolRunner $runner, private readonly TenantContext $tenant) {}

    public function index()
    {
        return view('tools.index', ['groups' => Catalog::GROUPS, 'tools' => Catalog::all()]);
    }

    public function show(string $tool)
    {
        $t = Catalog::get($tool);
        abort_if($t === null, 404);

        return view('tools.show', ['key' => $tool, 'tool' => $t, 'accept' => $this->acceptAttr($t)]);
    }

    public function run(Request $request, string $tool)
    {
        $t = Catalog::get($tool);
        abort_if($t === null, 404);

        $request->validate(['files' => ['nullable', 'array', 'max:'.max(1, $t['max'])], 'files.*' => ['file', 'max:'.self::MAX_FILE_KB]], ['files.max' => 'This tool takes at most '.$t['max'].' file(s).']);
        $files = [];
        foreach ($request->file('files') ?? [] as $f) {
            $files[] = ['name' => (string) $f->getClientOriginalName(), 'bytes' => (string) file_get_contents($f->getRealPath())];
        }
        $r = $this->runner->run($tool, $files, Catalog::params($t, $request->all()), $this->tenant->idOrFail(), (string) $request->user()->getKey());

        return $this->respond($request, $tool, $r);
    }

    /** One place that turns a runner result into a download, a text page or an error. @param array<string, mixed> $r */
    public static function respondWith(string $tool, array $r, string $backRoute, array $backParams = [])
    {
        if (! $r['ok']) {
            return redirect()->route($backRoute, $backParams)->withErrors(['files' => $r['error']])->withInput();
        }
        if (isset($r['text'])) {
            return view('tools.text', ['tool' => Catalog::get($tool), 'key' => $tool, 'text' => $r['text']]);
        }
        $f = $r['file'];

        return response($f['body'], 200, [
            'Content-Type' => $f['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($tool, '-').'-'.now()->format('Ymd-His').'.'.$f['ext'].'"',
        ]);
    }

    /** @param array<string, mixed> $r */
    private function respond(Request $request, string $tool, array $r)
    {
        return self::respondWith($tool, $r, 'tools.show', ['tool' => $tool]);
    }

    /** @param array<string, mixed> $t */
    private function acceptAttr(array $t): string
    {
        return implode(',', array_map(fn ($e) => '.'.$e, Catalog::EXT[$t['accept']]));
    }
}
