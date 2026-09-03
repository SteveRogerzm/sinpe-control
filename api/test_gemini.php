<?php
header('Content-Type: application/json');

$geminiKey = getenv('GEMINI_API_KEY');

if (!$geminiKey) {
    echo json_encode(['error' => 'No se encontró la variable GEMINI_API_KEY en Vercel']);
    exit;
}

$geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . trim($geminiKey);

$payload = json_encode([
    "contents" => [[
        "parts" => [
            ["text" => "Responde solo con la palabra OK si estás funcionando."]
        ]
    ]]
]);

$opts = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'ignore_errors' => true
    ]
];

$response = @file_get_contents($geminiUrl, false, stream_context_create($opts));
echo $response;
