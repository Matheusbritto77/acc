<?php

namespace App\Service;

class CharacterBazaarClient
{
    public static function post(string $path, array $payload): array
    {
        return self::request('POST', $path, $payload);
    }

    public static function get(string $path): array
    {
        return self::request('GET', $path);
    }

    public static function createRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private static function request(string $method, string $path, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'cURL extension is not available in AAC runtime.',
                'data' => null,
            ];
        }

        $url = rtrim((string) ($_ENV['CHAR_BAZAAR_INTERNAL_URL'] ?? 'http://127.0.0.1:8089'), '/')
            . '/'
            . ltrim($path, '/');
        $token = trim((string) ($_ENV['CHAR_BAZAAR_INTERNAL_TOKEN'] ?? ''));

        if ($token === '') {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'CHAR_BAZAAR_INTERNAL_TOKEN is not configured in AAC.',
                'data' => null,
            ];
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Failed to initialize bazaar client.',
                'data' => null,
            ];
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $error = curl_error($curl) ?: 'unknown bazaar client error';
            curl_close($curl);

            return [
                'ok' => false,
                'status' => 502,
                'error' => 'Character Bazaar service request failed: ' . $error,
                'data' => null,
            ];
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $decoded = null;
        if ($responseBody !== '') {
            $decoded = json_decode($responseBody, true);
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return [
                'ok' => true,
                'status' => $statusCode,
                'error' => null,
                'data' => is_array($decoded) ? $decoded : null,
            ];
        }

        $message = is_array($decoded) && !empty($decoded['message'])
            ? (string) $decoded['message']
            : 'Character Bazaar service returned an unexpected error.';

        return [
            'ok' => false,
            'status' => $statusCode > 0 ? $statusCode : 502,
            'error' => $message,
            'data' => is_array($decoded) ? $decoded : null,
        ];
    }
}
