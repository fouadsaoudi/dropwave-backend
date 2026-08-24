<?php

namespace App\Services;

class PhoneService
{
    /**
     * Normalize any phone number into standardized international E.164 format (+961...).
     */
    public static function normalize(?string $phone, string $defaultCountryCode = '961'): ?string
    {
        if ($phone === null) {
            return null;
        }

        // 1. Remove all spaces, dashes, brackets, dots, and non-numeric chars except leading +
        $clean = preg_replace('/[^\d+]/', '', trim($phone));
        if (empty($clean)) {
            return null;
        }

        // 2. Convert leading 00 or +00 to +
        $clean = preg_replace('/^\+?00/', '+', $clean);

        $hasPlus = str_starts_with($clean, '+');
        $digits = ltrim($clean, '+');

        if (empty($digits)) {
            return null;
        }

        // 3. Handle numbers that already start with Lebanese country code 961
        if (str_starts_with($digits, '961')) {
            $local = substr($digits, 3);
            // Drop leading local trunk zero if present (e.g. +96107732015 -> +9617732015)
            if (str_starts_with($local, '0')) {
                $local = substr($local, 1);
            }
            return '+961' . $local;
        }

        // 4. Lebanese Local Numbers format handling:
        // Case A: 9 digits starting with 0 (e.g. 070..., 071..., 076..., 078..., 079..., 081...) -> drop 0, add +961
        if (strlen($digits) === 9 && str_starts_with($digits, '0')) {
            return '+961' . substr($digits, 1);
        }

        // Case B: 8 digits starting with 0 (e.g. 03xxxxxx, 07xxxxxx, 01xxxxxx) -> drop 0, add +961 -> 7 digits
        if (strlen($digits) === 8 && str_starts_with($digits, '0')) {
            return '+961' . substr($digits, 1);
        }

        // Case C: 8 digits starting with Lebanese mobile prefixes (70, 71, 76, 78, 79, 81) -> add +961
        if (strlen($digits) === 8 && preg_match('/^(70|71|76|78|79|81)/', $digits)) {
            return '+961' . $digits;
        }

        // Case D: 7 digits starting with 1-9 (e.g. 3xxxxxx, 7xxxxxx) -> add +961
        if (strlen($digits) === 7 && preg_match('/^[1-9]/', $digits)) {
            return '+961' . $digits;
        }

        // 5. Foreign / Full International numbers (has + and length >= 10)
        if ($hasPlus && strlen($digits) >= 10) {
            return '+' . $digits;
        }

        // 6. 8 digits general fallback -> prepend default country code (+961)
        if (strlen($digits) === 8) {
            return '+' . $defaultCountryCode . $digits;
        }

        // 7. General fallback with +
        return '+' . $digits;
    }

    /**
     * Clean phone number for Meta WhatsApp Cloud API (digits only, no +).
     */
    public static function toWhatsAppFormat(?string $phone, string $defaultCountryCode = '961'): ?string
    {
        $normalized = self::normalize($phone, $defaultCountryCode);
        return $normalized ? ltrim($normalized, '+') : null;
    }
}
