<?php

if (! function_exists('money')) {
    /** 4780 → "4 780 ₾" (thin-space-free, matches the design). */
    function money(int|float $amount): string
    {
        return number_format((float) $amount, 0, '.', ' ').' ₾';
    }
}
