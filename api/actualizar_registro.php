<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth_check.php'; 
verificarAcceso();

try {
    $rawSupabaseUrl = getenv('SUPABASE_URL');
    $supabaseKey    = getenv('SUPABASE_SERVICE_ROLE_KEY');

    if (!$rawSupabaseUrl || !$supabaseKey) {
        throw new Exception("Faltan variables de entorno.");
    }

    // CORRECCIÓN AQUÍ: Usar 'php://input' con los dos puntos
    $jsonContent = file_get_contents('php://input');
    $input = json_decode($jsonContent, true);

    if (!$input) {
        $input = $_POST;
    }

    $id = $input['id'] ?? null;

    if (!$id) {
        throw new Exception("ID de registro no proporcionado.");
    }

    $cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($rawSupabaseUrl), '/'));
    $dbUrl = $cleanBaseUrl . "/rest/v1/sinpes?id=eq." . urlencode($id);

    $updateData = [];
    if (isset($input['estado'])) $updateData['estado'] = $input['estado'];
    if (isset($input['comentario'])) $updateData['comentario'] = $input['comentario'];

    if (empty($updateData)) {
        throw new Exception("No hay datos para actualizar.");
    }

    $ch = curl_init($dbUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: " . trim($supabaseKey),
        "Authorization: Bearer " . trim($supabaseKey),
        "Content-Type: application/json",
        "Prefer: return=representation"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new Exception("Error Supabase (HTTP $httpCode): " . $response);
    }

    echo json_encode(['success' => true, 'data' => json_decode($response, true)]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
