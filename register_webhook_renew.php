<?php
// Script temporal seguro para renovar el webhook de Telegram.
// NO imprime el token del bot, el secreto del webhook ni URLs de api.telegram.org con el token.
$envFile = 'C:/laragon/www/chatbot-clinica-telegram/.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '//') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }
    }
}

$token = $env['TELEGRAM_BOT_TOKEN'] ?? '';
$secret = $env['TELEGRAM_WEBHOOK_SECRET'] ?? '';
$publicUrl = 'https://womens-asbestos-indianapolis-isbn.trycloudflare.com';
$webhookUrl = $publicUrl . '/api/telegram/webhook';

if (empty($token)) {
    echo "ERROR: TELEGRAM_BOT_TOKEN no encontrado en .env\n";
    exit(1);
}

// --- setWebhook ---
$setUrl = 'https://api.telegram.org/bot' . $token . '/setWebhook';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $setUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'url' => $webhookUrl,
    'secret_token' => $secret,
    'allowed_updates' => ['message', 'callback_query']
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$setResult = curl_exec($ch);
$setHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$setError = curl_error($ch);
curl_close($ch);

// --- getWebhookInfo ---
$infoUrl = 'https://api.telegram.org/bot' . $token . '/getWebhookInfo';
$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, $infoUrl);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$infoResult = curl_exec($ch2);
$infoHttp = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$infoError = curl_error($ch2);
curl_close($ch2);

// --- Resultados sanitizados (sin secretos, sin URLs de API con token) ---
echo "SET_WEBHOOK_HTTP: $setHttp\n";
echo "SET_WEBHOOK_ERROR: " . ($setError ?: 'ninguno') . "\n";
echo "SET_WEBHOOK_OK: " . ($setResult !== false ? 'si' : 'no') . "\n";
echo "\n";
echo "GET_WEBHOOKINFO_HTTP: $infoHttp\n";
echo "GET_WEBHOOKINFO_ERROR: " . ($infoError ?: 'ninguno') . "\n";
if ($infoResult !== false) {
    $info = json_decode($infoResult, true);
    if ($info && isset($info['result'])) {
        $r = $info['result'];
        echo "WEBHOOK_URL_CONFIGURADA: " . ($r['url'] ?? 'N/A') . "\n";
        echo "URL_COINCIDE_CON_ESPERADA: " . ((($r['url'] ?? '') === $webhookUrl) ? 'SI' : 'NO') . "\n";
        echo "HAS_CUSTOM_CERTIFICATE: " . ((!empty($r['has_certificate'])) ? 'si' : 'no') . "\n";
        echo "PENDING_UPDATE_COUNT: " . ($r['pending_update_count'] ?? 0) . "\n";
        echo "LAST_ERROR_DATE: " . ($r['last_error_date'] ?? 'ninguno') . "\n";
        echo "LAST_ERROR_MESSAGE: " . ($r['last_error_message'] ?? 'ninguno') . "\n";
        echo "MAX_CONNECTIONS: " . ($r['max_connections'] ?? 0) . "\n";
        echo "IP_ADDRESSES: " . implode(',', $r['ip_addresses'] ?? []) . "\n";
        echo "ALLOWED_UPDATES: " . implode(',', $r['allowed_updates'] ?? []) . "\n";
    } else {
        echo "RESPUESTA_GET_WEBHOOKINFO: " . substr($infoResult ?? '', 0, 200) . "\n";
    }
} else {
    echo "GET_WEBHOOKINFO_FALLO\n";
}