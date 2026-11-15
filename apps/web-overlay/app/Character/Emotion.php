<?php

declare(strict_types=1);

namespace App\Character;

/** Picks one of Avatar::EXPRESSIONS for a reply, from plain cue words (Bengali and English).  A guess for the face, never used for anything important. */
final class Emotion
{
    private const CUES = [
        'surprised' => ['অবাক', 'আশ্চর্য', 'বাহ', 'ওয়াও', 'সত্যি', 'wow', 'surpris', 'amazing', 'incredible', '!?'],
        'sad' => ['দুঃখিত', 'দুঃখ', 'খারাপ লাগ', 'দুর্ভাগ্য', 'ব্যর্থ', 'হারিয়ে', 'সমস্যা', 'sorry', 'unfortunately', 'sad', 'failed', 'cannot', "can't"],
        'happy' => ['ধন্যবাদ', 'অভিনন্দন', 'চমৎকার', 'দারুণ', 'খুশি', 'সফল', 'স্বাগত', 'শুভ', 'thank', 'great', 'congrat', 'welcome', 'happy', 'glad', '😊', '🎉'],
        'thinking' => ['ভাবছি', 'দেখি', 'সম্ভবত', 'হয়তো', 'মনে হয়', 'বিবেচনা', 'hmm', 'maybe', 'perhaps', 'let me think', 'depends', '...'],
    ];

    /** The first matching feeling in this order wins (surprise, then sad, happy, thinking); otherwise neutral. */
    public static function detect(string $text): string
    {
        $t = mb_strtolower($text);
        foreach (self::CUES as $emotion => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w)) {
                    return $emotion;
                }
            }
        }

        return 'neutral';
    }
}
