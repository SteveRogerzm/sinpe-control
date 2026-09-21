<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth_check.php';

// Validar que el usuario en sesión tenga acceso
$usuarioActual = verificarAcceso();

try {
    $rawSupabaseUrl = getenv('SUPABASE_URL');
    $supabaseKey    = getenv('SUPABASE_SERVICE_ROLE_KEY');

    if (!$rawSupabaseUrl || !$supabaseKey) {
        throw new Exception("Faltan variables de entorno.");
    }

    $jsonContent = file_get_contents('php://input');
    $input = json_decode($jsonContent, true);

    if (!$input) {
        $input = $_POST;
    }

    $correo = trim(strtolower($input['correo'] ?? ''));

    if (empty($correo)) {
        throw new Exception("Se requiere el correo del usuario a eliminar.");
    }

    // Prevenir que el usuario se elimine a sí mismo
    if (isset($usuarioActual['correo']) && strtolower($usuarioActual['correo']) === $correo) {
        throw new Exception("No puedes eliminar tu propia cuenta de usuario.");
    }

    $cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($rawSupabaseUrl), '/'));
    $dbUrl = $cleanBaseUrl . "/rest/v1/usuarios?correo=eq." . urlencode($correo);

    $ch = curl_init($dbUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
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
        throw new Exception("Error al eliminar el usuario (HTTP $httpCode): " . $response);
    }

    echo json_encode(['success' => true, 'data' => json_decode($response, true)]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
