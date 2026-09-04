<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    $rawSupabaseUrl = getenv('SUPABASE_URL');
    $supabaseKey    = getenv('SUPABASE_SERVICE_ROLE_KEY');
    $geminiKey      = getenv('GEMINI_API_KEY');

    if (!$rawSupabaseUrl || !$supabaseKey || !$geminiKey) {
        throw new Exception("Faltan variables de entorno en Vercel.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método no permitido.");
    }

    if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("No se recibió un archivo de imagen válido.");
    }

    // Normalizar la URL de Supabase para remover /rest/v1 si venía incluido en la variable de entorno
    $cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($rawSupabaseUrl), '/'));

    $tmpPath    = $_FILES['comprobante']['tmp_name'];
    $fileName   = $_FILES['comprobante']['name'];
    $mimeType   = mime_content_type($tmpPath) ?: 'image/jpeg';
    $base64Data = base64_encode(file_get_contents($tmpPath));

    // 1. Procesar con Gemini API
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent";
    $promptText = 'Extrae los datos de este comprobante SINPE Móvil de Costa Rica. Responde estrictamente en formato JSON: {"monto": float, "numero_referencia": "string", "fecha_transferencia": "string", "nombre_emisor": "string", "telefono_emisor": "string"}';

    $payloadGemini = json_encode([
        "contents" => [
            [
                "parts" => [
                    ["text" => $promptText],
                    ["inline_data" => ["mime_type" => $mimeType, "data" => $base64Data]]
                ]
            ]
        ],
        "generationConfig" => ["response_mime_type" => "application/json"]
    ]);

    $ch = curl_init($geminiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadGemini);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . trim($geminiKey)
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $responseGemini = curl_exec($ch);
    curl_close($ch);

    $jsonGemini = json_decode($responseGemini, true);
    if (isset($jsonGemini['error'])) {
        throw new Exception("Google Gemini Error: " . ($jsonGemini['error']['message'] ?? json_encode($jsonGemini['error'])));
    }

    $rawText = trim($jsonGemini['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $extractedData = json_decode($rawText, true);

    if (!$extractedData || !isset($extractedData['numero_referencia'])) {
        throw new Exception("La IA no logró extraer los datos del comprobante.");
    }

    // 2. Subir Archivo a Supabase Storage
    $storageFileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
    $storageUrl      = $cleanBaseUrl . "/storage/v1/object/comprobantes/" . $storageFileName;

    $chStorage = curl_init($storageUrl);
    curl_setopt($chStorage, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chStorage, CURLOPT_POST, true);
    curl_setopt($chStorage, CURLOPT_POSTFIELDS, file_get_contents($tmpPath));
    curl_setopt($chStorage, CURLOPT_HTTPHEADER, [
        "apikey: " . trim($supabaseKey),
        "Authorization: Bearer " . trim($supabaseKey),
        "Content-Type: {$mimeType}"
    ]);
    curl_setopt($chStorage, CURLOPT_SSL_VERIFYPEER, false);
    $responseStorage = curl_exec($chStorage);
    $httpCodeStorage = curl_getinfo($chStorage, CURLINFO_HTTP_CODE);
    curl_close($chStorage);

    if ($httpCodeStorage >= 400) {
        throw new Exception("Error al subir imagen a Storage (HTTP {$httpCodeStorage}): " . $responseStorage);
    }

    $publicImageUrl = $cleanBaseUrl . "/storage/v1/object/public/comprobantes/" . $storageFileName;

    // 3. Insertar Registro en la BD
    $dbUrl     = $cleanBaseUrl . "/rest/v1/sinpes";
    $dbPayload = json_encode([
        "numero_referencia"   => (string)$extractedData['numero_referencia'],
        "monto"               => floatval($extractedData['monto'] ?? 0),
        "fecha_transferencia" => (string)($extractedData['fecha_transferencia'] ?? ''),
        "nombre_emisor"       => (string)($extractedData['nombre_emisor'] ?? ''),
        "telefono_emisor"     => (string)($extractedData['telefono_emisor'] ?? ''),
        "imagen_url"          => $publicImageUrl
    ]);

    $chDb = curl_init($dbUrl);
    curl_setopt($chDb, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chDb, CURLOPT_POST, true);
    curl_setopt($chDb, CURLOPT_POSTFIELDS, $dbPayload);
    curl_setopt($chDb, CURLOPT_HTTPHEADER, [
        "apikey: " . trim($supabaseKey),
        "Authorization: Bearer " . trim($supabaseKey),
        "Content-Type: application/json",
        "Prefer: return=representation"
    ]);
    curl_setopt($chDb, CURLOPT_SSL_VERIFYPEER, false);

    $responseDb = curl_exec($chDb);
    $httpCodeDb = curl_getinfo($chDb, CURLINFO_HTTP_CODE);
    curl_close($chDb);

    if ($httpCodeDb >= 400) {
        if (strpos($responseDb, '23505') !== false || strpos($responseDb, 'duplicate key') !== false) {
            throw new Exception("El comprobante con Ref: {$extractedData['numero_referencia']} ya existe en la base de datos.");
        }
        throw new Exception("Error en BD (HTTP {$httpCodeDb}): " . $responseDb);
    }

    echo json_encode(['success' => true, 'data' => $extractedData, 'imagen_url' => $publicImageUrl]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
