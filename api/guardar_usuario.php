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

    $nombreUsuario = trim($input['nombre_usuario'] ?? '');
    $correo        = trim(strtolower($input['correo'] ?? ''));
    $pCargar       = isset($input['puede_cargar_comprobantes']) ? (bool)$input['puede_cargar_comprobantes'] : false;
    $pComentarios   = isset($input['puede_editar_comentarios']) ? (bool)$input['puede_editar_comentarios'] : false;
    $pAcciones     = isset($input['puede_gestionar_acciones']) ? (bool)$input['puede_gestionar_acciones'] : false;

    if (empty($correo) || empty($nombreUsuario)) {
        throw new Exception("El nombre de usuario y el correo electrónico son obligatorios.");
    }

    $payload = [
        'nombre_usuario'            => $nombreUsuario,
        'correo'                  => $correo,
        'puede_cargar_comprobantes' => $pCargar,
        'puede_editar_comentarios'  => $pComentarios,
        'puede_gestionar_acciones'  => $pAcciones
    ];

    $cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($rawSupabaseUrl), '/'));
    $dbUrl = $cleanBaseUrl . "/rest/v1/usuarios";

    $ch = curl_init($dbUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: " . trim($supabaseKey),
        "Authorization: Bearer " . trim($supabaseKey),
        "Content-Type: application/json",
        "Prefer: return=representation, resolution=merge-duplicates" // Realiza UPSERT por la clave primaria (correo)
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new Exception("Error al guardar usuario en Supabase (HTTP $httpCode): " . $response);
    }

    echo json_encode(['success' => true, 'data' => json_decode($response, true)]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
