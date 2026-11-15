<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Vision\VisionText;

final class KnowledgeText
{
    /** Bengali message for a result of KnowledgeService::add / the repo; null = fine. */
    public static function error(string $result, ?string $reason = null): ?string
    {
        return match ($result) {
            'ok' => null,
            'duplicate' => 'This text is already in the knowledge base.',
            'limit' => 'The knowledge base limit is full (max 50 documents) — delete some old ones.',
            'too_big' => 'The file is very large — max 5 MB and about 480,000 characters. Split it into smaller parts.',
            'empty' => 'No readable text was found in the file.',
            'unsupported' => 'This type of file cannot be read now. Accepted: PDF, DOCX, TXT, MD, CSV, JSON, HTML.',
            'encoding' => 'The file’s encoding could not be understood — save as UTF-8 and give it again.',
            'pdf_unavailable' => 'This server has no PDF-reading tool (pdftotext) — convert the PDF to DOCX or TXT and give it.',
            'needs_ocr' => 'This is an image or scanned file — "Read images/scans" tick it and give again (AI will read, credits will be used; no cost for PDFs with a text layer).',
            'no_model', 'no_credits', 'provider', 'rejected' => VisionText::error($result, $reason),
            'unreadable' => 'The file could not be opened — it may be corrupted or password-protected.',
            'not_found' => 'The document was not found.',
            'bad_input' => 'The name cannot be empty or over 200 characters.',
            default => 'Could not be done.',
        };
    }
}
