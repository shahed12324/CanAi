<?php

declare(strict_types=1);

namespace App\DocTools;

/**
 * The "Photoshop AI agent" brain: reads a request in Bangla, English or Banglish plus the names of the attached files and picks one catalog tool with its settings.
 * Deterministic and free: no model call, so it never invents a tool and always answers the same way. Everything it returns is re-validated by Catalog::params().
 */
final class AgentPlanner
{
    private const COLORS = [
        '#ffffff' => ['white', 'সাদা', 'shada'], '#2a6fd6' => ['blue', 'নীল', 'nil'], '#d32f2f' => ['red', 'লাল', 'lal'],
        '#cfd3d8' => ['grey', 'gray', 'ধূসর'], '#1f9d55' => ['green', 'সবুজ'], '#000000' => ['black', 'কালো'],
    ];

    private const LANGS = ['Bengali' => ['bangla', 'bengali', 'বাংলা', 'bangla te', 'বাংলায়'], 'English' => ['english', 'ইংরেজি', 'ইংলিশ', 'ইংরেজিতে'], 'Hindi' => ['hindi', 'হিন্দি'], 'Arabic' => ['arabic', 'আরবি'], 'Urdu' => ['urdu', 'উর্দু'], 'French' => ['french', 'ফরাসি'], 'Chinese' => ['chinese', 'চীনা']];

    /** @param list<string> $names @return array{tool?: string, params?: array<string, mixed>, say?: string, error?: string} */
    public static function plan(string $text, array $names): array
    {
        $t = ' '.mb_strtolower(trim($text)).' ';
        if (trim($t) === '') {
            return ['error' => 'Tell me what to do, for example: “passport photo with white background, 4R sheet” or “merge these PDFs”.'];
        }
        $exts = array_map(fn ($n) => strtolower(pathinfo($n, PATHINFO_EXTENSION)), $names);
        $allPdf = $exts !== [] && count(array_unique($exts)) === 1 && $exts[0] === 'pdf';
        $anyPdf = in_array('pdf', $exts, true);
        $has = fn (array $w) => self::has($t, $w);
        $pick = fn (string $tool, array $params, string $say) => ['tool' => $tool, 'params' => $params, 'say' => $say];
        $quoted = self::quoted($text);

        // ---- photos
        $photo = $has(['passport size', 'passport photo', 'পাসপোর্ট সাইজ', 'পাসপোর্ট ছবি', 'পাসপোর্ট ফটো', '35x45', '35×45', '2x2', '2×2', 'stamp size', 'স্ট্যাম্প সাইজ', 'photo sheet', 'ফটো শিট', '4r', '5r', '6r']) || preg_match('/\d{2,3}(?:\.\d+)?\s*[x×*]\s*\d{2,3}(?:\.\d+)?\s*mm/u', $t) === 1;
        if ($photo) {
            $params = self::photoSize($t);
            $bg = self::color($t);
            if ($bg !== null) {
                $params['bg'] = $bg;
            }
            if ($has(['sheet', 'শিট', 'print', 'প্রিন্ট', '4r', '5r', '6r', 'copies', 'কপি', 'a4'])) {
                $params['paper'] = preg_match('/\b(4r|5r|6r)\b/', $t, $m) ? $m[1] : 'a4';
                if (preg_match('/(\d{1,3})\s*(?:copies|copy|টি|কপি|pcs)/u', $t, $m)) {
                    $params['copies'] = (int) $m[1];
                }

                return $pick('photo_sheet', $params, 'Making the photo and tiling it on a '.strtoupper($params['paper']).' sheet.');
            }

            return $pick('passport_photo', $params, 'Making an exact-size photo'.($bg ? ' with a new background.' : '.'));
        }
        if ($has(['remove background', 'background remove', 'bg remove', 'ব্যাকগ্রাউন্ড রিমুভ', 'ব্যাকগ্রাউন্ড মুছ', 'ব্যাকগ্রাউন্ড সরা', 'ব্যাকগ্রাউন্ড বাদ', 'background মুছ'])) {
            return $pick('bg_remove', [], 'Removing the background (plain backgrounds work best). Result is a transparent PNG.');
        }
        if ($has(['background color', 'background colour', 'ব্যাকগ্রাউন্ড কালার', 'ব্যাকগ্রাউন্ডের রং', 'ব্যাকগ্রাউন্ড রং', 'ব্যাকগ্রাউন্ড নীল', 'ব্যাকগ্রাউন্ড সাদা', 'ব্যাকগ্রাউন্ড লাল', 'background blue', 'background white', 'background red']) && ($c = self::color($t))) {
            return $pick('bg_color', ['color' => $c], 'Changing the background color.');
        }

        // ---- ID scans
        if ($has(['nid', 'এনআইডি', 'জাতীয় পরিচয়'])) {
            return $pick('nid_combine', [], 'Putting the NID front and back on one A4 page.');
        }
        if ($has(['driving licen', 'ড্রাইভিং লাইসেন্স', 'driving license', 'লাইসেন্স স্ক্যান'])) {
            return $pick('licence_scan', [], 'Putting the licence on a clean A4 page.');
        }
        if ($has(['birth certificate', 'জন্ম নিবন্ধন', 'জন্মনিবন্ধন', 'জন্মসনদ'])) {
            return $pick('birth_scan', [], 'Fitting the certificate on a clean A4 page.');
        }
        if ($has(['passport scan', 'পাসপোর্ট স্ক্যান', 'passport copy']) || ($has(['passport', 'পাসপোর্ট']) && $has(['scan', 'স্ক্যান']))) {
            return $pick('passport_scan', [], 'Putting the passport page on a clean A4 sheet.');
        }

        // ---- create
        if ($has(['qr code', 'qr কোড', 'কিউআর', 'qrcode', ' qr '])) {
            $v = $quoted ?? self::url($text) ?? self::after($text);

            return $v === null ? ['error' => 'What should the QR code say? Write it in quotes, like: QR code “https://example.com”.'] : $pick('qr_code', ['text' => $v], 'Making a QR code.');
        }
        if ($has(['barcode', 'বারকোড', 'bar code'])) {
            $v = $quoted ?? self::after($text) ?? (preg_match('/\b\d{8,13}\b/', $text, $m) ? $m[0] : null);
            if ($v === null) {
                return ['error' => 'What number or text should the barcode carry? Write it in quotes.'];
            }

            return $pick('barcode', ['text' => $v, 'type' => preg_match('/^\d{13}$/', $v) ? 'ean13' : 'code128'], 'Making a barcode.');
        }
        if ($has(['signature', 'সিগনেচার', 'সই', 'স্বাক্ষর'])) {
            return $pick('signature', $quoted !== null && $names === [] ? ['text' => $quoted] : [], 'Turning the signature into a transparent PNG.');
        }
        if ($has(['watermark', 'ওয়াটারমার্ক'])) {
            return $pick('watermark', ['text' => $quoted ?? self::after($text) ?? 'CONFIDENTIAL'], 'Adding the watermark.');
        }
        if ($has(['id card', 'আইডি কার্ড', 'identity card', 'পরিচয়পত্র'])) {
            return $pick('id_card', $quoted !== null ? ['name' => $quoted] : [], 'Opening the ID card maker with your details. Fill the rest on the form.');
        }
        if ($has(['business card', 'visiting card', 'ভিজিটিং কার্ড', 'বিজনেস কার্ড'])) {
            return $pick('business_card', $quoted !== null ? ['name' => $quoted] : [], 'Opening the business card maker with your details. Fill the rest on the form.');
        }

        // ---- language and text
        if ($has(['translate', 'অনুবাদ', 'anubad', 'ট্রান্সলেট'])) {
            return $pick('doc_translate', ['target' => self::language($t) ?? 'Bengali'], 'Translating the document with AI (uses credits).');
        }
        if ($has(['handwriting', 'handwritten', 'হাতের লেখা', 'হ্যান্ডরাইটিং'])) {
            return $pick('handwriting', [], 'Reading the handwriting with an AI vision model (uses credits).');
        }
        if ($has(['pdf to word', 'pdf থেকে word', 'pdf to docx', 'পিডিএফ থেকে ওয়ার্ড', 'word এ', 'ওয়ার্ডে']) && $anyPdf) {
            return $pick('pdf_to_word', [], 'Converting the PDF to an editable Word file.');
        }
        if ($has(['to word', 'image to word', 'ওয়ার্ড']) && ! $anyPdf) {
            return $pick('image_to_word', [], 'Reading the text and writing it into a Word file.');
        }
        if ($has(['excel', 'এক্সেল', 'xlsx', 'spreadsheet']) && $anyPdf) {
            return $pick('pdf_to_excel', [], 'Turning the PDF tables into an Excel file.');
        }
        if ($has(['ocr', 'extract text', 'text বের', 'টেক্সট বের', 'লেখা বের', 'লেখা পড়', 'read the text', 'image to text', 'টেক্সট']) ) {
            $b = $has(['bangla', 'bengali', 'বাংলা']);
            $e = $has(['english', 'ইংরেজি']);

            return $pick($b && ! $e ? 'ocr_bangla' : ($e && ! $b ? 'ocr_english' : 'ocr_text'), [], 'Reading the printed text.');
        }

        // ---- PDF
        if ($has(['merge', 'মার্জ', 'একসাথে জোড়া', 'জোড়া লাগা', 'combine pdf', 'join pdf', 'একটি pdf']) && ($allPdf || count($names) > 1) && $anyPdf) {
            return $pick('pdf_merge', [], 'Merging the PDFs in the order you attached them.');
        }
        if ($has(['split', 'স্প্লিট', 'ভাগ', 'আলাদা পাতা', 'separate pages']) && $anyPdf) {
            return $pick('pdf_split', preg_match('/pages?\s+([\d,\-\s]+)/', $t, $m) ? ['pages' => trim(preg_replace('/\s+/', '', $m[1]))] : [], 'Splitting the PDF.');
        }
        if ($has(['compress', 'কমপ্রেস', 'ছোট করো', 'ছোট কর', 'size কম', 'সাইজ কম', 'reduce size', 'smaller']) && $anyPdf) {
            return $pick('pdf_compress', ['level' => $has(['maximum', 'smallest', 'সবচেয়ে', 'high']) ? 'high' : 'medium'], 'Compressing the PDF. Pages become pictures, so text is no longer selectable.');
        }
        if ($has(['rotate', 'ঘোরা', 'রোটেট']) && $anyPdf) {
            return $pick('pdf_rotate', ['degrees' => $has(['180']) ? '180' : ($has(['left', 'বামে', '270']) ? '270' : '90')], 'Rotating the PDF.');
        }
        if ($has(['crop', 'ক্রপ']) && $anyPdf) {
            return $pick('pdf_crop', [], 'Opening PDF crop. Set the margins on the form.');
        }
        if ($has(['pdf to image', 'pdf to jpg', 'pdf to png', 'pdf থেকে ছবি', 'pdf থেকে jpg']) && $anyPdf) {
            return $pick('pdf_to_image', [], 'Turning each PDF page into a picture.');
        }
        if ($has(['to pdf', 'pdf বানা', 'pdf করো', 'pdf কর', 'pdf এ', 'পিডিএফ']) && ! $anyPdf) {
            $office = array_intersect($exts, Catalog::EXT['office']) !== [];

            return $pick($office ? 'doc_to_pdf' : 'image_to_pdf', [], $office ? 'Converting the document to PDF.' : 'Putting the pictures into one PDF.');
        }
        if ($has(['a4 page', 'a4 পেজ', 'a4 এ', 'a4 করো'])) {
            return $pick('a4_page', [], 'Placing each picture on its own A4 page.');
        }
        if ($has(['one page', 'এক পাতায়', 'এক পেজে', 'একই পেজে', 'multiple documents'])) {
            return $pick('multi_on_page', preg_match('/\b(2|3|4|6|8|9)\b/', $t, $m) ? ['per_page' => $m[1]] : [], 'Putting several documents on one page.');
        }

        // ---- picture fixes
        if ($has(['enhance', 'sharpen', 'ঝকঝকে', 'পরিষ্কার', 'উন্নত', 'hd', 'quality বাড়', 'কোয়ালিটি বাড়', 'blurry', 'ঝাপসা'])) {
            return $pick('enhance', $has(['2x', 'বড়', 'enlarge', 'upscale']) ? ['scale' => '2'] : [], 'Enhancing the picture (automatic contrast, noise reduction, sharpening).');
        }
        if ($has(['cleanup', 'clean up', 'ক্লিন', 'shadow', 'ছায়া', 'scan করো', 'স্ক্যান করো', 'scanned look'])) {
            return $pick('doc_cleanup', ['mode' => $has(['black and white', 'b&w', 'সাদা কালো', 'সাদাকালো']) ? 'bw' : 'color'], 'Cleaning the background so the page looks scanned.');
        }
        if (preg_match('/(\d{1,4})\s*kb/', $t, $m) || $has(['resize', 'রিসাইজ', 'সাইজ ছোট'])) {
            $params = [];
            if (isset($m[1])) {
                $params['max_kb'] = (int) $m[1];
                $params['percent'] = 100;
            }
            if (preg_match('/(\d{2,5})\s*[x×*]\s*(\d{2,5})\s*(?:px|pixel)?/u', $t, $d)) {
                $params['width'] = (int) $d[1];
                $params['height'] = (int) $d[2];
                unset($params['percent']);
            }

            return $pick('doc_resize', $params, 'Resizing'.(isset($params['max_kb']) ? ' to under '.$params['max_kb'].' KB.' : '.'));
        }
        if ($has(['crop', 'ক্রপ'])) {
            return $pick('image_crop', [], 'Opening image crop. Set the margins on the form.');
        }
        if ($has(['rotate', 'ঘোরা', 'রোটেট'])) {
            return $pick('image_rotate', ['degrees' => $has(['left', 'বামে']) ? -90 : ($has(['180']) ? 180 : 90)], 'Rotating the picture.');
        }
        if ($has(['face position', 'ফেস', 'মুখ', 'center the face'])) {
            return $pick('face_position', [], 'Opening the face position tool. Use zoom and move to place the face.');
        }

        return ['error' => 'I could not tell which tool you need. Try words like: passport photo, remove background, merge PDF, compress PDF, NID, OCR, translate, QR code, watermark, enhance.'];
    }

    /** @param list<string> $words */
    private static function has(string $t, array $words): bool
    {
        foreach ($words as $w) {
            if (str_contains($t, mb_strtolower($w))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function photoSize(string $t): array
    {
        if (preg_match('/(\d{2,3}(?:\.\d+)?)\s*[x×*]\s*(\d{2,3}(?:\.\d+)?)\s*mm/u', $t, $m)) {
            return ['size' => 'custom', 'width_mm' => (float) $m[1], 'height_mm' => (float) $m[2]];
        }
        if (preg_match('/2\s*[x×]\s*2/u', $t)) {
            return ['size' => '2x2in'];
        }
        if (self::has($t, ['stamp', 'স্ট্যাম্প'])) {
            return ['size' => 'stamp'];
        }

        return ['size' => '35x45'];
    }

    private static function color(string $t): ?string
    {
        if (preg_match('/#([0-9a-f]{6})\b/', $t, $m)) {
            return '#'.$m[1];
        }
        foreach (self::COLORS as $hex => $words) {
            if (self::has($t, $words)) {
                return $hex;
            }
        }

        return null;
    }

    private static function language(string $t): ?string
    {
        // the language after "to"/"into" or before "te/এ" wins over the language the document is in
        $best = null;
        $pos = -1;
        foreach (self::LANGS as $name => $words) {
            foreach ($words as $w) {
                $p = mb_strrpos($t, mb_strtolower($w));
                if ($p !== false && $p > $pos) {
                    $best = $name;
                    $pos = $p;
                }
            }
        }

        return $best;
    }

    /** First “quoted” or "quoted" chunk. */
    private static function quoted(string $text): ?string
    {
        return preg_match('/["“”\'‘’]([^"“”\'‘’]{1,1000})["“”\'‘’]/u', $text, $m) ? trim($m[1]) : null;
    }

    /** Text after a colon: "watermark: DRAFT". */
    private static function after(string $text): ?string
    {
        return preg_match('/:\s*(\S[^\n]{0,999})$/u', trim($text), $m) ? trim($m[1]) : null;
    }

    private static function url(string $text): ?string
    {
        return preg_match('~https?://\S+~i', $text, $m) ? $m[0] : null;
    }
}
