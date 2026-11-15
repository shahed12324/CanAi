<?php

declare(strict_types=1);

namespace App\DocTools;

/** Every document / photo tool: what it needs, which settings it shows, and which engine operation it runs. Pure data; the page and the validation are both built from it. */
final class Catalog
{
    public const GROUPS = ['scan' => 'ID & document scans', 'pdf' => 'PDF & documents', 'photo' => 'Photos & passport photos', 'edit' => 'Image editing', 'ocr' => 'OCR & convert', 'make' => 'Create & stamp'];

    private const IMG = 'image';
    private const PDF = 'pdf';
    private const OFFICE = 'office';

    /** Accepted file extensions per kind. */
    public const EXT = [self::IMG => ['jpg', 'jpeg', 'png', 'webp'], self::PDF => ['pdf'], 'imgpdf' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 'anydoc' => ['pdf', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'webp'], self::OFFICE => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt']];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $paper = ['type' => 'select', 'name' => 'paper', 'label' => 'Paper', 'options' => ['a4' => 'A4', 'a5' => 'A5'], 'default' => 'a4'];
        $outPdf = ['type' => 'select', 'name' => 'format', 'label' => 'Result', 'options' => ['pdf' => 'PDF', 'jpeg' => 'JPG picture'], 'default' => 'pdf'];
        $outImg = ['type' => 'select', 'name' => 'format', 'label' => 'Result', 'options' => ['jpeg' => 'JPG', 'png' => 'PNG'], 'default' => 'jpeg'];
        $kb = ['type' => 'number', 'name' => 'max_kb', 'label' => 'Maximum file size in KB (optional, JPG only)', 'min' => 10, 'max' => 5000, 'default' => ''];
        $margins = fn (string $unit) => array_map(fn ($s) => ['type' => 'number', 'name' => $s, 'label' => ucfirst($s).' ('.$unit.')', 'min' => 0, 'max' => 49, 'default' => 0, 'step' => '0.5'], ['left', 'top', 'right', 'bottom']);
        $photoSize = ['type' => 'select', 'name' => 'size', 'label' => 'Photo size', 'options' => ['35x45' => '35 × 45 mm (passport)', '2x2in' => '2 × 2 inch (50.8 mm)', 'stamp' => '25 × 30 mm (stamp)', 'custom' => 'Custom size…'], 'default' => '35x45'];
        $custom = [
            ['type' => 'number', 'name' => 'width_mm', 'label' => 'Custom width (mm)', 'min' => 10, 'max' => 200, 'default' => '', 'step' => '0.1'],
            ['type' => 'number', 'name' => 'height_mm', 'label' => 'Custom height (mm)', 'min' => 10, 'max' => 300, 'default' => '', 'step' => '0.1'],
        ];
        $frame = [
            ['type' => 'number', 'name' => 'zoom', 'label' => 'Zoom (1 = whole picture, up to 4)', 'min' => 1, 'max' => 4, 'default' => 1, 'step' => '0.05'],
            ['type' => 'number', 'name' => 'dx', 'label' => 'Move face right (+) / left (−) %', 'min' => -50, 'max' => 50, 'default' => 0, 'step' => '1'],
            ['type' => 'number', 'name' => 'dy', 'label' => 'Move face down (+) / up (−) %', 'min' => -50, 'max' => 50, 'default' => 0, 'step' => '1'],
            ['type' => 'text', 'name' => 'bg', 'label' => 'Background color (optional, e.g. #ffffff; plain backgrounds only)', 'default' => ''],
        ];
        $sheetPaper = ['type' => 'select', 'name' => 'paper', 'label' => 'Print paper', 'options' => ['4r' => '4R (4×6 in)', '5r' => '5R (5×7 in)', '6r' => '6R (6×8 in)', 'a4' => 'A4'], 'default' => '4r'];
        $copies = ['type' => 'number', 'name' => 'copies', 'label' => 'Copies (empty = as many as fit)', 'min' => 1, 'max' => 100, 'default' => ''];
        $lang = ['type' => 'select', 'name' => 'lang', 'label' => 'Language in the picture', 'options' => ['eng+ben' => 'Bangla + English', 'ben' => 'Bangla', 'eng' => 'English'], 'default' => 'eng+ben'];
        $clean = ['type' => 'select', 'name' => 'mode', 'label' => 'Cleanup', 'options' => ['color' => 'Keep colors', 'gray' => 'Grey', 'bw' => 'Black & white (text)'], 'default' => 'color'];

        return [
            'nid_combine' => ['group' => 'scan', 'label' => 'NID front & back combine', 'help' => 'Front and back on one A4 page at real card size.', 'op' => 'scan', 'accept' => self::IMG, 'min' => 2, 'max' => 2, 'fixed' => ['kind' => 'nid'], 'fields' => [$outPdf, $paper, ['type' => 'checkbox', 'name' => 'cleanup', 'label' => 'Clean the background', 'default' => '']]],
            'licence_scan' => ['group' => 'scan', 'label' => 'Driving license scan', 'help' => 'Front (and back, if you add it) on one page at card size.', 'op' => 'scan', 'accept' => self::IMG, 'min' => 1, 'max' => 2, 'fixed' => ['kind' => 'licence'], 'fields' => [$outPdf, $paper, ['type' => 'checkbox', 'name' => 'cleanup', 'label' => 'Clean the background', 'default' => '']]],
            'passport_scan' => ['group' => 'scan', 'label' => 'Passport scan', 'help' => 'The data page (and a second page if you add it) on an A4 sheet.', 'op' => 'scan', 'accept' => self::IMG, 'min' => 1, 'max' => 2, 'fixed' => ['kind' => 'passport'], 'fields' => [$outPdf, $paper, ['type' => 'checkbox', 'name' => 'cleanup', 'label' => 'Clean the background', 'default' => '']]],
            'birth_scan' => ['group' => 'scan', 'label' => 'Birth certificate scan', 'help' => 'Fits the certificate on a clean A4 page.', 'op' => 'scan', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'fixed' => ['kind' => 'birth'], 'fields' => [$outPdf, $paper, ['type' => 'checkbox', 'name' => 'cleanup', 'label' => 'Clean the background', 'default' => '']]],

            'doc_to_pdf' => ['group' => 'pdf', 'label' => 'Document to PDF', 'help' => 'Word, Excel, PowerPoint, OpenDocument, RTF or text.', 'op' => 'doc_to_pdf', 'accept' => self::OFFICE, 'min' => 1, 'max' => 1, 'fields' => []],
            'image_to_pdf' => ['group' => 'pdf', 'label' => 'Image to PDF', 'help' => 'One page per picture, in the order you pick them.', 'op' => 'images_to_pdf', 'accept' => self::IMG, 'min' => 1, 'max' => 40, 'fields' => [$paper, ['type' => 'select', 'name' => 'fit', 'label' => 'Page', 'options' => ['a4' => 'Fit on paper', 'original' => 'Same size as the picture'], 'default' => 'a4']]],
            'pdf_to_image' => ['group' => 'pdf', 'label' => 'PDF to image', 'help' => 'Several pages come as a .zip.', 'op' => 'pdf_to_images', 'accept' => self::PDF, 'min' => 1, 'max' => 1, 'fields' => [$outImg, ['type' => 'number', 'name' => 'dpi', 'label' => 'Quality (DPI)', 'min' => 50, 'max' => 300, 'default' => 150], ['type' => 'text', 'name' => 'pages', 'label' => 'Pages (empty = all, e.g. 1-3,5)', 'default' => '']]],
            'pdf_merge' => ['group' => 'pdf', 'label' => 'PDF merge', 'help' => 'Joins PDFs in the order you pick them.', 'op' => 'pdf_merge', 'accept' => self::PDF, 'min' => 2, 'max' => 20, 'fields' => []],
            'pdf_split' => ['group' => 'pdf', 'label' => 'PDF split', 'help' => 'Pages like 1-3,5 become separate PDFs in a .zip. Empty = one file per page.', 'op' => 'pdf_split', 'accept' => self::PDF, 'min' => 1, 'max' => 1, 'fields' => [['type' => 'text', 'name' => 'pages', 'label' => 'Pages', 'default' => '']]],
            'pdf_compress' => ['group' => 'pdf', 'label' => 'PDF compress', 'help' => 'Low keeps text selectable. Medium/High turn pages into pictures: much smaller, text can no longer be selected.', 'op' => 'pdf_compress', 'accept' => self::PDF, 'min' => 1, 'max' => 1, 'fields' => [['type' => 'select', 'name' => 'level', 'label' => 'Strength', 'options' => ['low' => 'Low (lossless)', 'medium' => 'Medium', 'high' => 'High (smallest)'], 'default' => 'medium']]],
            'pdf_rotate' => ['group' => 'pdf', 'label' => 'PDF rotate', 'help' => 'Rotate all pages or only some.', 'op' => 'pdf_rotate', 'accept' => self::PDF, 'min' => 1, 'max' => 1, 'fields' => [['type' => 'select', 'name' => 'degrees', 'label' => 'Rotate', 'options' => ['90' => '90° right', '180' => '180°', '270' => '90° left'], 'default' => '90'], ['type' => 'text', 'name' => 'pages', 'label' => 'Pages (empty = all)', 'default' => '']]],
            'pdf_crop' => ['group' => 'pdf', 'label' => 'PDF crop', 'help' => 'Cut margins off every page (percent of the page).', 'op' => 'pdf_crop', 'accept' => self::PDF, 'min' => 1, 'max' => 1, 'fields' => $margins('%')],
            'a4_page' => ['group' => 'pdf', 'label' => 'A4 page maker', 'help' => 'Puts each picture on its own clean A4 page.', 'op' => 'a4_page', 'accept' => self::IMG, 'min' => 1, 'max' => 40, 'fields' => [$outPdf, $paper, ['type' => 'number', 'name' => 'margin_mm', 'label' => 'Margin (mm)', 'min' => 0, 'max' => 40, 'default' => 10]]],
            'multi_on_page' => ['group' => 'pdf', 'label' => 'Multiple documents on one page', 'help' => 'Several pictures on one sheet (2, 4, 6 …).', 'op' => 'multi_on_page', 'accept' => self::IMG, 'min' => 2, 'max' => 40, 'fields' => [$outPdf, $paper, ['type' => 'select', 'name' => 'per_page', 'label' => 'Per page', 'options' => ['2' => '2', '3' => '3', '4' => '4 (2×2)', '6' => '6 (2×3)', '8' => '8', '9' => '9 (3×3)'], 'default' => '4']]],
            'doc_resize' => ['group' => 'pdf', 'label' => 'Document resize', 'help' => 'Change pixel size, or shrink a scan below a KB limit for online forms.', 'op' => 'image_resize', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'fields' => [['type' => 'number', 'name' => 'width', 'label' => 'Width (px)', 'min' => 8, 'max' => 10000, 'default' => ''], ['type' => 'number', 'name' => 'height', 'label' => 'Height (px)', 'min' => 8, 'max' => 10000, 'default' => ''], ['type' => 'number', 'name' => 'percent', 'label' => 'or percent (100 = same size)', 'min' => 5, 'max' => 400, 'default' => ''], $kb, $outImg]],
            'doc_cleanup' => ['group' => 'pdf', 'label' => 'Document background cleanup', 'help' => 'Removes shadows and paper tint so the page looks scanned.', 'op' => 'doc_cleanup', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'fields' => [$clean, $outImg]],

            'passport_photo' => ['group' => 'photo', 'label' => 'Passport size photo maker', 'help' => 'Exact-size photo at 300 DPI. Use zoom and the move boxes to place the face.', 'op' => 'passport_photo', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fields' => [$photoSize, ...$custom, ...$frame, $kb, $outImg]],
            'photo_35x45' => ['group' => 'photo', 'label' => '35×45 mm photo', 'help' => 'The common passport size.', 'op' => 'passport_photo', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fixed' => ['size' => '35x45'], 'fields' => [...$frame, $kb, $outImg]],
            'photo_2x2' => ['group' => 'photo', 'label' => '2×2 inch photo', 'help' => 'US visa / OCI size.', 'op' => 'passport_photo', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fixed' => ['size' => '2x2in'], 'fields' => [...$frame, $kb, $outImg]],
            'photo_custom' => ['group' => 'photo', 'label' => 'Custom photo size', 'help' => 'Any size in millimetres.', 'op' => 'passport_photo', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fixed' => ['size' => 'custom'], 'fields' => [...$custom, ...$frame, $kb, $outImg]],
            'photo_sheet' => ['group' => 'photo', 'label' => 'Multiple passport photos on one sheet', 'help' => 'Tiles the photo with cut lines, ready to print.', 'op' => 'photo_sheet', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fields' => [$photoSize, ...$custom, $sheetPaper, $copies, ...$frame, $outImg]],
            'photo_print' => ['group' => 'photo', 'label' => '4R / 5R / 6R / A4 photo sheet', 'help' => 'Fills a print sheet with 35×45 mm photos.', 'op' => 'photo_sheet', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fixed' => ['size' => '35x45'], 'fields' => [$sheetPaper, $copies, ...$frame, $outImg]],
            'face_position' => ['group' => 'photo', 'label' => 'Face position adjustment', 'help' => 'Re-centre and zoom the face inside the passport frame.', 'op' => 'passport_photo', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fields' => [$photoSize, ...$custom, ...$frame, $outImg]],

            'bg_remove' => ['group' => 'edit', 'label' => 'Background remover', 'help' => 'Works on plain backgrounds (studio wall, paper). Result is a transparent PNG. Busy backgrounds need a person-cutout model, which is not included.', 'op' => 'bg_remove', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fields' => [['type' => 'number', 'name' => 'tolerance', 'label' => 'Strength (5–120; higher removes more)', 'min' => 5, 'max' => 120, 'default' => 40]]],
            'bg_color' => ['group' => 'edit', 'label' => 'Background color change', 'help' => 'Plain backgrounds only. Example: blue #1e6fd9, white #ffffff.', 'op' => 'bg_color', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fields' => [['type' => 'text', 'name' => 'color', 'label' => 'New color (hex)', 'default' => '#ffffff'], ['type' => 'number', 'name' => 'tolerance', 'label' => 'Strength (5–120)', 'min' => 5, 'max' => 120, 'default' => 40], $outImg]],
            'image_crop' => ['group' => 'edit', 'label' => 'Image crop', 'help' => 'Cut margins off each side (percent).', 'op' => 'image_crop', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'fields' => [...array_map(fn ($f) => ['max' => 99] + $f, $margins('%')), $outImg]],
            'image_resize' => ['group' => 'edit', 'label' => 'Image resize', 'help' => 'Set pixel size or percent; optionally a KB limit.', 'op' => 'image_resize', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'fields' => [['type' => 'number', 'name' => 'width', 'label' => 'Width (px)', 'min' => 8, 'max' => 10000, 'default' => ''], ['type' => 'number', 'name' => 'height', 'label' => 'Height (px)', 'min' => 8, 'max' => 10000, 'default' => ''], ['type' => 'number', 'name' => 'percent', 'label' => 'or percent', 'min' => 5, 'max' => 400, 'default' => ''], $kb, $outImg]],
            'image_rotate' => ['group' => 'edit', 'label' => 'Image rotate', 'help' => 'Rotate by any angle.', 'op' => 'image_rotate', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'fields' => [['type' => 'number', 'name' => 'degrees', 'label' => 'Degrees clockwise (negative = left)', 'min' => -360, 'max' => 360, 'default' => 90, 'step' => '1'], $outImg]],


            'ocr_text' => ['group' => 'ocr', 'label' => 'OCR – Image to Text', 'help' => 'Reads printed text from a picture or scanned PDF (free, on our server). For handwriting or difficult Bangla use “Handwriting to Text” (AI).', 'op' => 'ocr', 'accept' => 'imgpdf', 'min' => 1, 'max' => 1, 'fields' => [$lang, ['type' => 'select', 'name' => 'format', 'label' => 'Result', 'options' => ['txt' => 'Text file', 'docx' => 'Word file'], 'default' => 'txt']]],
            'ocr_bangla' => ['group' => 'ocr', 'label' => 'Bangla OCR', 'help' => 'Bangla (and English) printed text from a picture or scanned PDF.', 'op' => 'ocr', 'accept' => 'imgpdf', 'min' => 1, 'max' => 1, 'fixed' => ['lang' => 'eng+ben'], 'fields' => [['type' => 'select', 'name' => 'format', 'label' => 'Result', 'options' => ['txt' => 'Text file', 'docx' => 'Word file'], 'default' => 'txt']]],
            'ocr_english' => ['group' => 'ocr', 'label' => 'English OCR', 'help' => 'English printed text from a picture or scanned PDF.', 'op' => 'ocr', 'accept' => 'imgpdf', 'min' => 1, 'max' => 1, 'fixed' => ['lang' => 'eng'], 'fields' => [['type' => 'select', 'name' => 'format', 'label' => 'Result', 'options' => ['txt' => 'Text file', 'docx' => 'Word file'], 'default' => 'txt']]],
            'handwriting' => ['group' => 'ocr', 'label' => 'Handwriting to Text (AI)', 'help' => 'An AI vision model reads handwriting and hard Bangla. Uses AI credits; the text is shown on the page and can be copied.', 'runner' => 'vision', 'op' => 'vision', 'accept' => 'imgpdf', 'min' => 1, 'max' => 1, 'fields' => []],
            'image_to_word' => ['group' => 'ocr', 'label' => 'Image to Word', 'help' => 'Picture → editable .docx (text only; layout is not kept).', 'op' => 'image_to_word', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'fields' => [$lang]],
            'pdf_to_word' => ['group' => 'ocr', 'label' => 'PDF to Word', 'help' => 'PDF → editable .docx (text only; pictures and layout are not kept). Scans are read with OCR.', 'op' => 'pdf_to_word', 'accept' => self::PDF, 'min' => 1, 'max' => 1, 'fields' => [$lang]],
            'pdf_to_excel' => ['group' => 'ocr', 'label' => 'PDF to Excel', 'help' => 'Each page becomes a sheet; columns are guessed from spacing. Works best on simple tables; scans are not supported.', 'op' => 'pdf_to_excel', 'accept' => self::PDF, 'min' => 1, 'max' => 1, 'fields' => []],
            'doc_translate' => ['group' => 'ocr', 'label' => 'Document Translation (AI)', 'help' => 'Translates a PDF, Word, text file or picture and gives you a Word file. Uses AI credits; up to about 12,000 characters.', 'runner' => 'translate', 'op' => 'translate', 'accept' => 'anydoc', 'min' => 1, 'max' => 1, 'fields' => [['type' => 'select', 'name' => 'target', 'label' => 'Translate into', 'options' => ['Bengali' => 'Bangla', 'English' => 'English', 'Hindi' => 'Hindi', 'Arabic' => 'Arabic', 'Urdu' => 'Urdu', 'French' => 'French', 'Chinese' => 'Chinese'], 'default' => 'Bengali']]],

            'enhance' => ['group' => 'edit', 'label' => 'Photo Enhancement (automatic)', 'help' => 'Auto contrast, noise reduction, sharpening and optional 2× enlarge. Automatic image processing, not a generative AI.', 'op' => 'enhance', 'accept' => self::IMG, 'min' => 1, 'max' => 1, 'camera' => true, 'fields' => [['type' => 'select', 'name' => 'scale', 'label' => 'Enlarge', 'options' => ['1' => 'No', '1.5' => '1.5×', '2' => '2×'], 'default' => '1'], ['type' => 'checkbox', 'name' => 'denoise', 'label' => 'Reduce noise', 'default' => '1'], $outImg]],

            'qr_code' => ['group' => 'make', 'label' => 'QR Code Generator', 'help' => 'Link, text, phone number or payment details → QR picture.', 'op' => 'qr', 'accept' => self::IMG, 'min' => 0, 'max' => 0, 'fields' => [['type' => 'textarea', 'name' => 'text', 'label' => 'Text or link', 'default' => '', 'max' => 1000], ['type' => 'text', 'name' => 'color', 'label' => 'Color (hex)', 'default' => '#000000'], ['type' => 'text', 'name' => 'bg', 'label' => 'Background (hex)', 'default' => '#ffffff'], ['type' => 'number', 'name' => 'size', 'label' => 'Size (px)', 'min' => 200, 'max' => 3000, 'default' => 800]]],
            'barcode' => ['group' => 'make', 'label' => 'Barcode Generator', 'help' => 'Code 128, Code 39, EAN-13, EAN-8, UPC-A.', 'op' => 'barcode', 'accept' => self::IMG, 'min' => 0, 'max' => 0, 'fields' => [['type' => 'select', 'name' => 'type', 'label' => 'Type', 'options' => ['code128' => 'Code 128 (any text)', 'code39' => 'Code 39', 'ean13' => 'EAN-13 (12–13 digits)', 'ean8' => 'EAN-8 (7–8 digits)', 'upca' => 'UPC-A (11–12 digits)'], 'default' => 'code128'], ['type' => 'text', 'name' => 'text', 'label' => 'Number / text', 'default' => '', 'max' => 60]]],
            'signature' => ['group' => 'make', 'label' => 'Signature Maker', 'help' => 'Upload a photo of your signature on white paper (or leave empty and type your name). Result: transparent PNG.', 'op' => 'signature', 'accept' => self::IMG, 'min' => 0, 'max' => 1, 'camera' => true, 'fields' => [['type' => 'text', 'name' => 'text', 'label' => 'Or type your name (no photo)', 'default' => '', 'max' => 40], ['type' => 'text', 'name' => 'color', 'label' => 'Ink color (hex)', 'default' => '#111827'], ['type' => 'number', 'name' => 'width', 'label' => 'Width in px (optional)', 'min' => 100, 'max' => 3000, 'default' => '']]],
            'watermark' => ['group' => 'make', 'label' => 'Watermark Tool', 'help' => 'Text watermark on a picture or PDF. On a PDF the pages become pictures (text is no longer selectable).', 'op' => 'watermark', 'accept' => 'imgpdf', 'min' => 1, 'max' => 1, 'fields' => [['type' => 'text', 'name' => 'text', 'label' => 'Watermark text', 'default' => 'CONFIDENTIAL', 'max' => 40], ['type' => 'select', 'name' => 'layout', 'label' => 'Layout', 'options' => ['tile' => 'Repeated', 'center' => 'Centre'], 'default' => 'tile'], ['type' => 'number', 'name' => 'opacity', 'label' => 'Opacity % (5–100)', 'min' => 5, 'max' => 100, 'default' => 35], ['type' => 'number', 'name' => 'scale', 'label' => 'Text size % of the page (2–30)', 'min' => 2, 'max' => 30, 'default' => 8], $outImg]],
            'id_card' => ['group' => 'make', 'label' => 'ID Card Maker', 'help' => 'Staff / student ID card at 85.6 × 54 mm with photo, ready to print.', 'op' => 'id_card', 'accept' => self::IMG, 'min' => 0, 'max' => 1, 'camera' => true, 'fields' => [['type' => 'text', 'name' => 'name', 'label' => 'Name', 'default' => '', 'max' => 40], ['type' => 'text', 'name' => 'title', 'label' => 'Designation / class', 'default' => '', 'max' => 40], ['type' => 'text', 'name' => 'org', 'label' => 'Organization', 'default' => '', 'max' => 40], ['type' => 'text', 'name' => 'id_no', 'label' => 'ID number', 'default' => '', 'max' => 30], ['type' => 'text', 'name' => 'phone', 'label' => 'Phone', 'default' => '', 'max' => 30], ['type' => 'text', 'name' => 'blood', 'label' => 'Blood group', 'default' => '', 'max' => 30], ['type' => 'text', 'name' => 'valid', 'label' => 'Valid until', 'default' => '', 'max' => 30], ['type' => 'text', 'name' => 'address', 'label' => 'Address', 'default' => '', 'max' => 90], ['type' => 'text', 'name' => 'primary', 'label' => 'Main color (hex)', 'default' => '#1f3a8a'], ['type' => 'text', 'name' => 'accent', 'label' => 'Accent color (hex)', 'default' => '#f59e0b'], ['type' => 'select', 'name' => 'format', 'label' => 'Result', 'options' => ['png' => 'PNG', 'pdf' => 'PDF (card size)'], 'default' => 'png']]],
            'business_card' => ['group' => 'make', 'label' => 'Business Card Maker', 'help' => '90 × 54 mm card; optional logo upload.', 'op' => 'business_card', 'accept' => self::IMG, 'min' => 0, 'max' => 1, 'fields' => [['type' => 'text', 'name' => 'name', 'label' => 'Name', 'default' => '', 'max' => 40], ['type' => 'text', 'name' => 'title', 'label' => 'Job title', 'default' => '', 'max' => 50], ['type' => 'text', 'name' => 'company', 'label' => 'Company', 'default' => '', 'max' => 40], ['type' => 'text', 'name' => 'phone', 'label' => 'Phone', 'default' => '', 'max' => 40], ['type' => 'text', 'name' => 'email', 'label' => 'Email', 'default' => '', 'max' => 70], ['type' => 'text', 'name' => 'website', 'label' => 'Website', 'default' => '', 'max' => 70], ['type' => 'text', 'name' => 'address', 'label' => 'Address', 'default' => '', 'max' => 70], ['type' => 'text', 'name' => 'primary', 'label' => 'Main color (hex)', 'default' => '#111827'], ['type' => 'text', 'name' => 'accent', 'label' => 'Accent color (hex)', 'default' => '#f59e0b'], ['type' => 'select', 'name' => 'format', 'label' => 'Result', 'options' => ['png' => 'PNG', 'pdf' => 'PDF (card size)'], 'default' => 'png']]],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Settings the visitor may send: only declared fields, cast to the declared type. Fixed values always win. @param array<string, mixed> $input @return array<string, mixed> */
    public static function params(array $tool, array $input): array
    {
        $out = [];
        foreach ($tool['fields'] as $f) {
            $v = $input[$f['name']] ?? null;
            if ($f['type'] === 'checkbox') {
                $out[$f['name']] = $v !== null && $v !== '' && $v !== '0';
            } elseif ($v === null || $v === '') {
                continue;
            } elseif ($f['type'] === 'number') {
                if (! is_numeric($v)) {
                    continue;
                }
                $out[$f['name']] = $f['name'] === 'percent' || str_ends_with($f['name'], '_mm') || in_array($f['name'], ['zoom', 'dx', 'dy', 'left', 'top', 'right', 'bottom'], true) ? (float) $v : (int) $v;
            } elseif ($f['type'] === 'select') {
                if (array_key_exists((string) $v, $f['options'])) {
                    $out[$f['name']] = (string) $v;
                }
            } else {
                $out[$f['name']] = mb_substr(trim((string) $v), 0, (int) ($f['max'] ?? 40));
            }
        }

        return ($tool['fixed'] ?? []) + $out;
    }

    /** Checks a file name against what the tool takes. */
    public static function accepts(array $tool, string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXT[$tool['accept']], true);
    }
}
