<?php
header('Content-Type: application/json; charset=utf-8');

// Desactivar despliegue de errores HTML en pantalla
ini_set('display_errors', '0');
error_reporting(0);

$geminiKey = getenv('GEMINI_API_KEY');

if (!$geminiKey) {
    echo json_encode(['status' => 'error', 'mensaje' => 'La variable GEMINI_API_KEY no está configurada en Vercel.']);
    exit;
}

// Usamos la API de Gemini 1.5 Flash estable
$geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . trim($geminiKey);

$payload = json_encode([
    "contents" => [[
        "parts" => [
            ["text" => "Responde solo la palabra: FUNCIONANDO"]
        ]
    ]]
]);

$opts = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ],
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $payload,
        'ignore_errors' => true
    ]
];

$context  = stream_context_create($opts);
$response = @file_get_contents($geminiUrl, false, $context);

if ($response === false) {
    echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo conectar con el servidor de Google AI.']);
    exit;
}

$decoded = json_decode($response, true);

// Devuelve el JSON tal cual para inspeccionar la respuesta de Google
echo json_encode([
    'status_code_raw' => $http_response_header[0] ?? 'Desconocido',
    'respuesta_google' => $decoded
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
