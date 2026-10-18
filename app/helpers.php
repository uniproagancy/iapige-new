<?php

if (! function_exists('money')) {

    function money(int|float $amount): string
    {
        return number_format((float) $amount, 0, '.', ' ').' ₾';
    }
}

if (! function_exists('product_title')) {

    /* Supplier names usually open with the brand already. Repeating it reads
       as "Ttec Ttec USB-C" on invoices, in the cart and in the feeds. */
    function product_title(?string $brand, ?string $name): string
    {
        $brand = trim((string) $brand);
        $name = trim((string) $name);

        if ($brand === '' || $name === '' || mb_stripos($name, $brand) !== false) {
            return $name !== '' ? $name : $brand;
        }

        return $brand.' '.$name;
    }
}
