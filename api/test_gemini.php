<?php
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
error_reporting(0);

$geminiKey = getenv('GEMINI_API_KEY');

if (!$geminiKey) {
    echo json_encode(['error' => 'No se encontró la variable GEMINI_API_KEY en Vercel']);
    exit;
}

// Endpoint de la API para listar todos los modelos disponibles
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . trim($geminiKey);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPGET, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['error' => 'Error cURL: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);

// Filtramos solo los modelos que soportan el método 'generateContent'
$availableModels = [];
if (isset($data['models'])) {
    foreach ($data['models'] as $model) {
        if (isset($model['supportedGenerationMethods']) && in_array('generateContent', $model['supportedGenerationMethods'])) {
            $availableModels[] = [
                'name' => $model['name'],
                'displayName' => $model['displayName'] ?? '',
                'description' => $model['description'] ?? ''
            ];
        }
    }
}

echo json_encode([
    'total_modelos' => count($availableModels),
    'modelos_disponibles' => $availableModels,
    'raw_response' => $data
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
