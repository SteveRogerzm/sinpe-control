<?php
// Desactivar despliegue de errores HTML en pantalla
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    $supabaseUrl = getenv('SUPABASE_URL');
    $supabaseKey = getenv('SUPABASE_SERVICE_ROLE_KEY');
    $geminiKey   = getenv('GEMINI_API_KEY');

    if (!$supabaseUrl || !$supabaseKey || !$geminiKey) {
        throw new Exception("Faltan variables de entorno en Vercel. Revisa SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY y GEMINI_API_KEY.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método no permitido.");
    }

    if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("No se recibió una imagen válida o el archivo supera el tamaño permitido.");
    }

    $tmpPath = $_FILES['comprobante']['tmp_name'];
    $fileName = $_FILES['comprobante']['name'];
    $mimeType = mime_content_type($tmpPath) ?: 'image/jpeg';
    $base64Image = base64_encode(file_get_contents($tmpPath));

    // Configuración SSL para peticiones HTTPS en Vercel
    $sslOptions = [
        "ssl" => [
            "verify_peer" => false,
            "verify_peer_name" => false
        ]
    ];
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . trim($geminiKey);

$promptText = 'Extrae los datos de este comprobante SINPE Móvil de Costa Rica y responde ÚNICAMENTE con un objeto JSON válido sin bloques markdown. Formato: {"monto": float, "numero_referencia": "string", "fecha_transferencia": "string", "nombre_emisor": "string", "telefono_emisor": "string"}';

$payloadGemini = json_encode([
    "contents" => [[
        "parts" => [
            ["text" => $promptText],
            ["inline_data" => ["mime_type" => $mimeType, "data" => $base64Image]]
        ]
    ]]
]);

$optsGemini = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ],
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payloadGemini,
        'ignore_errors' => true
    ]
];

$contextGemini = stream_context_create($optsGemini);
$responseGemini = @file_get_contents($geminiUrl, false, $contextGemini);
    $jsonGemini = json_decode($responseGemini, true);

    if (!isset($jsonGemini['candidates'][0]['content']['parts'][0]['text'])) {
        $msgErr = $jsonGemini['error']['message'] ?? 'Gemini no pudo procesar la foto.';
        throw new Exception("Error Gemini: " . $msgErr);
    }

    $rawText = trim($jsonGemini['candidates'][0]['content']['parts'][0]['text']);
    $rawText = preg_replace('/^```json\s*|\s*```$/', '', $rawText);
    $extractedData = json_decode($rawText, true);

    if (!$extractedData || !isset($extractedData['numero_referencia'])) {
        throw new Exception("La IA no logró detectar un número de referencia en la imagen.");
    }

    // 2. Subir Imagen a Supabase Storage
    $cleanBaseUrl = rtrim(trim($supabaseUrl), '/');
    $storageFileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
    $storageUrl = $cleanBaseUrl . "/storage/v1/object/comprobantes/" . $storageFileName;

    $optsStorage = array_merge_recursive($sslOptions, [
        'http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Bearer " . trim($supabaseKey) . "\r\nContent-Type: {$mimeType}\r\n",
            'content' => file_get_contents($tmpPath),
            'ignore_errors' => true
        ]
    ]);

    $contextStorage = stream_context_create($optsStorage);
    @file_get_contents($storageUrl, false, $contextStorage);

    $publicImageUrl = $cleanBaseUrl . "/storage/v1/object/public/comprobantes/" . $storageFileName;

    // 3. Guardar registro en Supabase DB
    $dbUrl = $cleanBaseUrl . "/rest/v1/sinpes";
    $dbPayload = json_encode([
        "numero_referencia"   => (string)$extractedData['numero_referencia'],
        "monto"               => floatval($extractedData['monto'] ?? 0),
        "fecha_transferencia" => (string)($extractedData['fecha_transferencia'] ?? ''),
        "nombre_emisor"       => (string)($extractedData['nombre_emisor'] ?? ''),
        "telefono_emisor"     => (string)($extractedData['telefono_emisor'] ?? ''),
        "imagen_url"          => $publicImageUrl
    ]);

    $optsDb = array_merge_recursive($sslOptions, [
        'http' => [
            'method'  => 'POST',
            'header'  => "apikey: " . trim($supabaseKey) . "\r\nAuthorization: Bearer " . trim($supabaseKey) . "\r\nContent-Type: application/json\r\nPrefer: return=representation\r\n",
            'content' => $dbPayload,
            'ignore_errors' => true
        ]
    ]);

    $contextDb = stream_context_create($optsDb);
    $responseDb = @file_get_contents($dbUrl, false, $contextDb);

    if (strpos($responseDb, '23505') !== false || strpos($responseDb, 'duplicate key') !== false) {
        throw new Exception("El comprobante Ref: {$extractedData['numero_referencia']} ya existe en la base de datos.");
    }

    echo json_encode(['success' => true, 'data' => $extractedData, 'imagen_url' => $publicImageUrl]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
