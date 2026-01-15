<?php

declare(strict_types=1);

function attendance_api_base(): string {
    $base = getenv('ATTENDANCE_API_BASE');
    if (!$base) {
        $base = 'http://192.168.32.33:3003/v2';
    }
    return rtrim($base, '/');
}

function attendance_api_get(string $path, array $query = [], int $timeoutSeconds = 8): array {
    $base = attendance_api_base();
    $path = '/' . ltrim($path, '/');

    $filtered = [];
    foreach ($query as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        if (is_bool($value)) {
            $filtered[$key] = $value ? 'true' : 'false';
            continue;
        }
        $filtered[$key] = $value;
    }

    $url = $base . $path;
    if (!empty($filtered)) {
        $url .= '?' . http_build_query($filtered);
    }

    $body = null;
    $status = null;
    $error = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => null, 'data' => null, 'error' => 'curl_init_failed', 'url' => $url];
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'header' => "Accept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = 'request_failed';
        } elseif (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/HTTP\/\S+\s+(\d+)/', $line, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
        }
    }

    if ($error !== null) {
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $error, 'url' => $url];
    }

    $data = null;
    $trimmed = trim((string) $body);
    if ($trimmed !== '') {
        $data = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'error' => 'invalid_json_response',
                'url' => $url,
            ];
        }
    }

    $ok = ($status === null || ($status >= 200 && $status < 300));
    return [
        'ok' => $ok,
        'status' => $status,
        'data' => $data,
        'error' => $ok ? null : 'http_error',
        'url' => $url,
    ];
}

?>
