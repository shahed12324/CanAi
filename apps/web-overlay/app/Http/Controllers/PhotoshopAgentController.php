<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DocTools\AgentPlanner;
use App\DocTools\Catalog;
use App\DocTools\ToolRunner;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * The Photoshop AI agent: one box for every document and photo tool. You write what you want and attach the files; the agent picks the tool and its settings
 * (AgentPlanner, rule based: it never invents a tool), checks the files fit, and runs it. Tools that need details (ID card, crop …) open their form pre-filled.
 */
final class PhotoshopAgentController extends Controller
{
    private const NEEDS_FORM = ['id_card', 'business_card', 'pdf_crop', 'image_crop', 'face_position'];

    public function __construct(private readonly ToolRunner $runner, private readonly TenantContext $tenant) {}

    public function index()
    {
        return view('photoshop.index', ['examples' => [
            'Passport size photo, white background, 4R sheet, 8 copies', 'Remove the background', 'Merge these PDFs', 'Compress this PDF',
            'NID front and back on one A4 page', 'Extract the text (Bangla)', 'Read the handwriting', 'Translate to English',
            'Resize to 100kb', 'QR code "https://example.com"', 'Watermark: CONFIDENTIAL', 'Enhance this blurry photo',
        ]]);
    }

    public function run(Request $request)
    {
        $d = $request->validate(['request' => ['required', 'string', 'max:500'], 'files' => ['nullable', 'array', 'max:40'], 'files.*' => ['file', 'max:25000'], 'mode' => ['nullable', 'in:run,plan']]);
        $files = [];
        foreach ($request->file('files') ?? [] as $f) {
            $files[] = ['name' => (string) $f->getClientOriginalName(), 'bytes' => (string) file_get_contents($f->getRealPath())];
        }
        $plan = AgentPlanner::plan($d['request'], array_column($files, 'name'));
        if (isset($plan['error'])) {
            return back()->withErrors(['request' => $plan['error']])->withInput();
        }
        $tool = Catalog::get($plan['tool']);
        $params = Catalog::params($tool, $plan['params']);
        $line = $tool['label'].' — '.$plan['say'];

        if (in_array($plan['tool'], self::NEEDS_FORM, true)) {
            return redirect()->route('tools.show', $plan['tool'])->withInput($params)->with('status', 'The agent chose “'.$tool['label'].'”. Fill in the details and press Make file.');
        }
        if (($d['mode'] ?? 'run') === 'plan') {
            $err = ToolRunner::check($tool, array_column($files, 'name'));

            return back()->withInput()->with('status', 'I would use: '.$line.($err ? '  ⚠ '.$err : ''));
        }
        $r = $this->runner->run($plan['tool'], $files, $params, $this->tenant->idOrFail(), (string) $request->user()->getKey());
        if (! $r['ok']) {
            return back()->withErrors(['request' => $tool['label'].': '.$r['error']])->withInput();
        }

        return DocToolsController::respondWith($plan['tool'], $r, 'photoshop.index');
    }
}
