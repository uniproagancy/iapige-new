<?php

namespace App\Support;

class BankAccounts
{
    public static function all(): array
    {
        $parsed = self::parse((string) config('shop.company.accounts', ''));

        return $parsed ?: self::legacy();
    }

    public static function parse(?string $lines): array
    {
        $accounts = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $lines) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            $bank = $parts[0] ?? '';
            $iban = $parts[1] ?? '';
            $swift = $parts[2] ?? '';

            if ($iban === '' && $bank !== '') {
                [$bank, $iban] = ['', $bank];
            }

            if ($iban === '') {
                continue;
            }

            $accounts[] = [
                'bank' => $bank,
                'iban' => preg_replace('/\s+/', '', $iban),
                'swift' => $swift,
            ];
        }

        return $accounts;
    }

    protected static function legacy(): array
    {
        $iban = trim((string) config('shop.company.iban', ''));

        if ($iban === '') {
            return [];
        }

        return [[
            'bank' => (string) config('shop.company.bank', ''),
            'iban' => preg_replace('/\s+/', '', $iban),
            'swift' => (string) config('shop.company.swift', ''),
        ]];
    }
}
