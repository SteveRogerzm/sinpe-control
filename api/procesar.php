<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth_check.php'; 
verificarAcceso();

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
        throw new Exception("No se recibió un archivo válido.");
    }

    $tmpPath   = $_FILES['comprobante']['tmp_name'];
    $fileName  = $_FILES['comprobante']['name'];
    
    // Obtener y validar el MimeType real del archivo enviado
    $mimeType  = mime_content_type($tmpPath) ?: $_FILES['comprobante']['type'];

    // Lista de tipos de archivo permitidos (Imágenes y PDF)
    $allowedTypes = [
        'image/jpeg', 
        'image/png', 
        'image/webp', 
        'image/heic', 
        'application/pdf'
    ];

    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception("Formato no soportado ({$mimeType}). Solo se admiten imágenes o archivos PDF.");
    }

    // Normalizar la URL de Supabase para remover /rest/v1 si venía incluido en la variable de entorno
    $cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($rawSupabaseUrl), '/'));

    $base64Data = base64_encode(file_get_contents($tmpPath));

    // 1. Procesar con Gemini API mediante Fallback con Modelos Vigentes
    $promptText = 'Extrae los datos de este comprobante SINPE Móvil de Costa Rica (imagen o PDF). '
        . 'Identifica el banco/entidad financiera de origen (ej: BAC, Banco Nacional, BCR, Davivienda, etc.) como "banco_emisor", '
        . 'y la persona que envía el dinero como "cliente". '
        . 'Responde strictly en formato JSON: '
        . '{"monto": float, "numero_referencia": "string", "fecha_transferencia": "string", "cliente": "string", "banco_emisor": "string", "telefono_emisor": "string"}';

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

    // Arreglo de modelos de la familia Flash según tu panel de cuotas de Google AI Studio
    $modelsToTry = [
        "gemini-3.5-flash-lite",
        "gemini-3.1-flash-lite",

        "gemini-3.8-flash",
        "gemini-3.7-flash",
        "gemini-3.6-flash",
        "gemini-3.5-flash",
        "gemini-3.0-flash",
        "gemini-2.5-flash",
        "gemini-2.5-flash-lite",
        "gemma-4-31b",
        "gemma-4-26b"
    ];

    $jsonGemini = null;
    $lastErrorMsg = '';
    $success = false;

    foreach ($modelsToTry as $model) {
        $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        
        $maxAttempts = 2;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            
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
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $jsonGemini = json_decode($responseGemini, true);

            // Si la respuesta fue exitosa salimos del loop inmediatamente
            if ($httpCode === 200 && !isset($jsonGemini['error'])) {
                $success = true;
                break 2; // Salir del while y del foreach
            }

            $errMsg = $jsonGemini['error']['message'] ?? '';
            $lastErrorMsg = "[{$model}] " . $errMsg;

            $isQuotaOrDeprecated = (
                $httpCode === 429 || 
                $httpCode === 404 ||
                $httpCode === 503 || 
                strpos(strtolower($errMsg), 'quota') !== false || 
                strpos(strtolower($errMsg), 'resource_exhausted') !== false ||
                strpos(strtolower($errMsg), 'no longer available') !== false ||
                strpos(strtolower($errMsg), 'high demand') !== false
            );

            // Si el modelo agotó cuota o ya no existe, pasamos directamente al siguiente modelo del array
            if ($isQuotaOrDeprecated) {
                break;
            }

            if ($attempt < $maxAttempts) {
                usleep(1000000); // Esperar 1 segundo antes de reintentar en el mismo modelo
            }
        }
    }

    if (!$success) {
        throw new Exception("Google Gemini Error (Fallback agotado): " . $lastErrorMsg);
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
        throw new Exception("Error al subir archivo a Storage (HTTP {$httpCodeStorage}): " . $responseStorage);
    }

    $publicImageUrl = $cleanBaseUrl . "/storage/v1/object/public/comprobantes/" . $storageFileName;
    $comentarioInicial = $_POST['comentario'] ?? null;
    
    // 3. Insertar Registro en la BD
    $dbUrl     = $cleanBaseUrl . "/rest/v1/sinpes";
    $dbPayload = json_encode([
        "numero_referencia"   => (string)$extractedData['numero_referencia'],
        "monto"               => floatval($extractedData['monto'] ?? 0),
        "fecha_transferencia" => (string)($extractedData['fecha_transferencia'] ?? ''),
        "nombre_emisor"       => (string)($extractedData['banco_emisor'] ?? $extractedData['nombre_emisor'] ?? ''),
        "cliente"             => (string)($extractedData['cliente'] ?? ''),
        "telefono_emisor"     => (string)($extractedData['telefono_emisor'] ?? ''),
        "imagen_url"          => $publicImageUrl,
        "estado"              => 'Pendiente',
        "comentario"          => $comentarioInicial
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
