<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth_check.php';

try {
    // Validar que el usuario en sesión sea Administrador
    $usuarioActual = verificarAcceso();
    if (!$usuarioActual || empty($usuarioActual['es_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tiene permisos de administrador para consultar usuarios.']);
        exit;
    }

    $rawSupabaseUrl = getenv('SUPABASE_URL');
    $supabaseKey    = getenv('SUPABASE_SERVICE_ROLE_KEY');

    if (!$rawSupabaseUrl || !$supabaseKey) {
        throw new Exception("Faltan variables de entorno de Supabase.");
    }

    $cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($rawSupabaseUrl), '/'));
    $dbUrl = $cleanBaseUrl . "/rest/v1/usuarios?select=*&order=created_at.desc";

    $ch = curl_init($dbUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: " . trim($supabaseKey),
        "Authorization: Bearer " . trim($supabaseKey),
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new Exception("Error al consultar usuarios (HTTP $httpCode): " . $response);
    }

    $usuarios = json_decode($response, true);

    echo json_encode(['success' => true, 'data' => $usuarios]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
