<?php
declare(strict_types=1);

/**
 * Pedido HTTP simples (usa cURL se existir, senão streams do PHP).
 * Devolve ['status' => int, 'body' => string]. Lança RuntimeException se não houver ligação.
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
{
    $method = strtoupper($method);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Sem ligação ao serviço de IA: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$response];
    }

    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
        'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('Sem ligação ao serviço de IA.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; }
    }
    return ['status' => $status, 'body' => (string)$response];
}
