<?php

if (! function_exists('money')) {

    function money(int|float $amount): string
    {
        return number_format((float) $amount, 0, '.', ' ').' ₾';
    }
}
