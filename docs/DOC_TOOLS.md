# Document & photo tools (v0.110.0)

Sidebar → **Doc & photo tools** (`/app/tools`). 29 tools in four groups; the page and validation are generated from
`App\DocTools\Catalog`. Files are sent to the engine (`POST /internal/v1/doctools/run`, signed) and the result is returned
as a download; nothing is stored. Limits: 25 MB per file, 40 files, 200 PDF pages, 40 MP per image; 20 requests/minute.

Honest limits: background removal and color change work on plain backgrounds (it grows from the picture border; it is not a
person-cutout model). Face position is set by hand (zoom and shift). "Document to PDF" needs LibreOffice in the engine image
(default on; build with `--build-arg WITH_OFFICE=0` to save ~350 MB). PDF compress "medium/high" turns pages into pictures.

Tests: `apps/ai-engine/tests/test_doctools.py` (real PDFs/images), `tests/Unit/DocToolsCatalogTest.php`.

## v0.111.0 additions
43 tools. New groups: *OCR & convert* and *Create & stamp*. **Photoshop AI agent** (`/app/photoshop`) maps a sentence plus files to one tool
(`App\DocTools\AgentPlanner`, rule based, tested in `tests/Unit/AgentPlannerTest.php`). AI tools (handwriting, translation) use the workspace's models
and are charged through the normal credit reservation/settle path (`VisionReader`, `TranslateService`); everything else is free.
Bangla OCR needs `tesseract-ocr-ben` (in the engine Dockerfile). Translation is limited to 12,000 characters per document.
