<?php

namespace App\Support;

/*
 | Half the specifications a shop receives answer yes or no, and they do it in
 | four ways at once: "Yes", "დიახ", "No", "არა". Offered as they came, the
 | question "wireless charging" turns into four boxes for two answers, and
 | picking either Georgian one hides every product whose supplier wrote in
 | English.
 |
 | Only a label that is the whole answer and nothing else is read this way.
 | "დიახ, 15 W" says more than yes, so it keeps its own words.
 */
class Answer
{
    protected const YES = [
        'yes', 'y', 'true', 'available', 'supported', 'support', 'with',
        'დიახ', 'აქვს', 'არის', 'მხარდაჭერილია', 'მხარდაჭერით',
    ];

    protected const NO = [
        'no', 'none', 'false', 'not available', 'unsupported', 'without',
        'არა', 'არ აქვს', 'არ არის', 'არ არი', 'არ გააჩნია', 'არ აქვს მხარდაჭერა',
    ];

    /* True or false where the label says only that, and null where it says
       anything more. */
    public static function yesNo(?string $label): ?bool
    {
        $label = Text::words($label);

        if ($label === '') {
            return null;
        }

        if (in_array($label, self::YES, true)) {
            return true;
        }

        return in_array($label, self::NO, true) ? false : null;
    }

    public static function label(bool $yes): string
    {
        return $yes ? __('catalog.yes') : __('catalog.no');
    }
}
