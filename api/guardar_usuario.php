<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth_check.php';

try {
    // 1. Validar que el usuario en sesión sea ADMINISTRADOR (es_admin)
    $usuarioActual = verificarAcceso();
    if (!$usuarioActual || empty($usuarioActual['es_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tiene permisos de administrador para gestionar usuarios.']);
        exit;
    }

    $rawSupabaseUrl = getenv('SUPABASE_URL');
    $supabaseKey    = getenv('SUPABASE_SERVICE_ROLE_KEY');

    if (!$rawSupabaseUrl || !$supabaseKey) {
        throw new Exception("Faltan variables de entorno de Supabase.");
    }

    $jsonContent = file_get_contents('php://input');
    $input = json_decode($jsonContent, true);

    if (!$input) {
        $input = $_POST;
    }

    $id             = !empty($input['id']) ? trim($input['id']) : null;
    $nombreUsuario  = trim($input['nombre_usuario'] ?? '');
    $email          = trim(strtolower($input['email'] ?? ''));
    
    // Captura de todos los permisos e identificadores de rol
    $esAdmin        = !empty($input['es_admin']);
    $esCajero       = !empty($input['es_cajero']);
    $pCargar        = !empty($input['puede_cargar_comprobantes']);
    $pComentarios   = !empty($input['puede_editar_comentarios']);
    $pAcciones      = !empty($input['puede_gestionar_acciones']);

    if (empty($email) || empty($nombreUsuario)) {
        throw new Exception("El nombre de usuario y el correo electrónico son obligatorios.");
    }

    $payload = [
        'nombre_usuario'            => $nombreUsuario,
        'email'                     => $email,
        'es_admin'                  => $esAdmin,
        'es_cajero'                 => $esCajero,
        'puede_cargar_comprobantes' => $pCargar,
        'puede_editar_comentarios'  => $pComentarios,
        'puede_gestionar_acciones'  => $pAcciones
    ];

    if ($id) {
        $payload['id'] = $id;
    }

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
        "Prefer: return=representation, resolution=merge-duplicates"
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
