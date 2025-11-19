<?php
declare(strict_types=1);

namespace SRP\Controllers;

use SRP\Middleware\Session;

class ImonetizeItController
{
    private const BASE_URL = 'https://imonetizeit.com/api/';

    public static function handle(): void
    {
        Session::requireAuth();

        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_THROW_ON_ERROR);
            return;
        }

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid request'], JSON_THROW_ON_ERROR);
            return;
        }

        $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload'], JSON_THROW_ON_ERROR);
            return;
        }

        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        $apiKey = trim((string)($payload['apiKey'] ?? ''));
        $customUrl = trim((string)($payload['customUrl'] ?? ''));
        $requestBodyRaw = (string)($payload['requestBody'] ?? '');
        $timePeriod = trim((string)($payload['timePeriod'] ?? ''));

        if ($endpoint === '' || $apiKey === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Endpoint and API key are required'], JSON_THROW_ON_ERROR);
            return;
        }

        try {
            [$url, $method, $body] = self::buildRequest($endpoint, $apiKey, $customUrl, $requestBodyRaw, $timePeriod);
            $response = self::forwardRequest($url, $method, $body);

            echo json_encode($response, JSON_THROW_ON_ERROR);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to reach iMonetizeIt API'], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @return array{0:string,1:string,2:array<string,mixed>|null}
     */
    private static function buildRequest(string $endpoint, string $apiKey, string $customUrl, string $requestBodyRaw, string $timePeriod): array
    {
        $allowedEndpoints = ['getkey', 'stats', 'balance', 'points', 'custom'];
        if (!in_array($endpoint, $allowedEndpoints, true)) {
            throw new \InvalidArgumentException('Unsupported endpoint selected');
        }

        $method = 'GET';
        $url = '';
        $body = null;

        if ($endpoint === 'custom') {
            if ($customUrl === '' || !preg_match('#^https?://#i', $customUrl)) {
                throw new \InvalidArgumentException('Valid custom endpoint URL is required');
            }

            $url = $customUrl;
            $method = 'POST';
            $body = self::decodeBody($requestBodyRaw);
            $body['apikey'] = $apiKey;
        } elseif ($endpoint === 'getkey') {
            $url = self::BASE_URL . 'getkey?apikey=' . rawurlencode($apiKey);
        } else {
            $url = self::BASE_URL . $endpoint . '?apikey=' . rawurlencode($apiKey);

            if ($timePeriod !== '') {
                $url .= '&period=' . rawurlencode($timePeriod);
            }
        }

        return [$url, $method, $body];
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeBody(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid JSON in request body: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Request body must be a JSON object');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{ok:bool,status:int,response:mixed,error?:string}
     */
    private static function forwardRequest(string $url, string $method, ?array $body): array
    {
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $options[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('cURL error: ' . ($curlErr ?: 'Unknown error'));
        }

        $decoded = null;
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // If response isn't JSON, return raw string
            $decoded = $raw;
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'response' => $decoded,
            'error' => $status >= 400 ? 'HTTP error ' . $status : null,
        ];
    }
}
