<?php

namespace App\Services;

use App\Models\WhatsAppErrorCode;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class WhatsAppErrorService
{
    /**
     * Resolve and lookup an error code against the database or cache.
     */
    public static function lookup($code, $subcode = null, ?string $rawDetails = null): array
    {
        $codeStr = (string) $code;
        $subcodeStr = $subcode !== null ? (string) $subcode : null;

        // Try exact match with subcode first, then without subcode
        $cacheKey = "wa_error_{$codeStr}_" . ($subcodeStr ?? 'none');

        $record = Cache::remember($cacheKey, 3600, function () use ($codeStr, $subcodeStr) {
            $query = WhatsAppErrorCode::where('code', $codeStr);
            if ($subcodeStr !== null) {
                $match = (clone $query)->where('subcode', $subcodeStr)->first();
                if ($match) {
                    return $match->toArray();
                }
            }

            $generic = (clone $query)->whereNull('subcode')->first();
            if ($generic) {
                return $generic->toArray();
            }

            $any = (clone $query)->first();
            if ($any) {
                return $any->toArray();
            }

            // Check range pattern (e.g. 200-299)
            if (is_numeric($codeStr)) {
                $codeInt = (int) $codeStr;
                if ($codeInt >= 200 && $codeInt <= 299) {
                    $range = WhatsAppErrorCode::where('code', '200-299')->first();
                    if ($range) {
                        return $range->toArray();
                    }
                }
            }

            return null;
        });

        $title = $record['title'] ?? 'WhatsApp API Error';
        $category = $record['category'] ?? 'General';
        $details = $rawDetails ?: ($record['details'] ?? 'An error occurred while communicating with Meta WhatsApp API.');
        $reasons = $record['possible_reasons'] ?? null;
        $solutions = $record['possible_solutions'] ?? null;
        $clientExplanation = $record['client_explanation'] ?? $reasons;
        $clientSolution = $record['client_solution'] ?? $solutions;
        $httpStatus = $record['http_status_code'] ?? 400;

        // Compose intuitive formatted explanation
        $formatted = "{$title} (Meta Error {$codeStr})";
        if ($details) {
            $formatted .= ": {$details}";
        }
        if ($clientExplanation) {
            $formatted .= " | Reason: {$clientExplanation}";
        }
        if ($clientSolution) {
            $formatted .= " | Solution: {$clientSolution}";
        }

        return [
            'code' => $codeStr,
            'subcode' => $subcodeStr,
            'category' => $category,
            'title' => $title,
            'details' => $details,
            'possible_reasons' => $reasons,
            'possible_solutions' => $solutions,
            'client_explanation' => $clientExplanation,
            'client_solution' => $clientSolution,
            'http_status_code' => $httpStatus,
            'formatted_message' => $formatted,
        ];
    }

    /**
     * Parse error details from a response, exception, or payload.
     */
    public static function parse($source): array
    {
        $code = null;
        $subcode = null;
        $details = null;
        $rawMessage = null;
        $fbtraceId = null;
        $type = null;

        if ($source instanceof Response) {
            $json = $source->json() ?? [];
            $error = $json['error'] ?? $json;
            $code = $error['code'] ?? null;
            $subcode = $error['error_subcode'] ?? null;
            $details = $error['error_data']['details'] ?? $error['details'] ?? null;
            $rawMessage = $error['error_user_msg'] ?? $error['message'] ?? null;
            $fbtraceId = $error['fbtrace_id'] ?? null;
            $type = $error['type'] ?? null;
        } elseif (is_array($source)) {
            $error = $source['error'] ?? $source;
            $code = $error['code'] ?? null;
            $subcode = $error['error_subcode'] ?? null;
            $details = $error['error_data']['details'] ?? $error['details'] ?? null;
            $rawMessage = $error['error_user_msg'] ?? $error['message'] ?? null;
            $fbtraceId = $error['fbtrace_id'] ?? null;
            $type = $error['type'] ?? null;
        } elseif ($source instanceof Throwable) {
            $rawMessage = $source->getMessage();
            // Attempt to regex extract error code from message if present like "(#130429)"
            if (preg_match('/\(#?(\d+)\)/', $rawMessage, $matches)) {
                $code = $matches[1];
            }
        } elseif (is_string($source)) {
            $rawMessage = $source;
            if (preg_match('/\(#?(\d+)\)/', $rawMessage, $matches)) {
                $code = $matches[1];
            }
        }

        if ($code !== null) {
            $resolved = self::lookup($code, $subcode, $details);
        } else {
            $resolved = [
                'code' => null,
                'subcode' => null,
                'category' => 'General',
                'title' => 'WhatsApp API Error',
                'details' => $rawMessage ?? 'Unknown error occurred with WhatsApp API.',
                'possible_reasons' => null,
                'possible_solutions' => null,
                'http_status_code' => 500,
                'formatted_message' => $rawMessage ?? 'Unknown WhatsApp API error.',
            ];
        }

        $resolved['raw_message'] = $rawMessage;
        $resolved['fbtrace_id'] = $fbtraceId;
        $resolved['type'] = $type;

        return $resolved;
    }

    /**
     * Format a descriptive error message from any WhatsApp API error response or exception.
     */
    public static function formatErrorMessage($source, ?string $fallback = null): string
    {
        $parsed = self::parse($source);

        if (!empty($parsed['formatted_message'])) {
            return $parsed['formatted_message'];
        }

        return $fallback ?? 'An error occurred while communicating with WhatsApp Cloud API.';
    }

    /**
     * Log structured WhatsApp error with full diagnostics.
     */
    public static function logError($source, array $context = []): void
    {
        $parsed = self::parse($source);

        Log::error("[WhatsApp API Error] {$parsed['title']} (#{$parsed['code']})", array_merge([
            'category' => $parsed['category'],
            'code' => $parsed['code'],
            'subcode' => $parsed['subcode'],
            'details' => $parsed['details'],
            'reasons' => $parsed['possible_reasons'],
            'solutions' => $parsed['possible_solutions'],
            'fbtrace_id' => $parsed['fbtrace_id'] ?? null,
            'formatted_message' => $parsed['formatted_message'],
        ], $context));
    }
}
