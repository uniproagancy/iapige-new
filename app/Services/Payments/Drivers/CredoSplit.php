<?php

namespace App\Services\Payments\Drivers;

/* Credo's split product: the same widget and the same payload, booked
   against a different shop. Only the configuration section changes. */
class CredoSplit extends CredoInstallment
{
    public function configKey(): string
    {
        return 'credo.split';
    }

    public function driverName(): string
    {
        return 'credo-split';
    }

    public function formRoute(): string
    {
        return 'payment.credo_split.form';
    }
}
