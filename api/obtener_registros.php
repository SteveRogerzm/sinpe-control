<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth_check.php'; 

// Verifica usuario y extrae sus permisos
$usuarioPermisos = verificarAcceso();

$supabaseUrl = getenv('SUPABASE_URL');
$supabaseKey = getenv('SUPABASE_SERVICE_ROLE_KEY');

$cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($supabaseUrl), '/'));

// JOIN embebido usando sintaxis de PostgREST:
// Se traen todos los campos de sinpes (*) y el objeto nombre_usuario / email del usuario relacionado
$selectQuery = urlencode("*, u_creacion:usuario_creacion(id, nombre_usuario, email), u_aprobacion:usuario_aprobacion(id, nombre_usuario, email), u_facturacion:usuario_facturacion(id, nombre_usuario, email)");

$dbUrl = $cleanBaseUrl . "/rest/v1/sinpes?select={$selectQuery}&order=created_at.desc";

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

if ($httpCode >= 400 || !$response) {
    echo json_encode(['permisos' => $usuarioPermisos, 'data' => []]);
    exit;
}

$data = json_decode($response, true);

// Opcional: Aplanar o formatear la respuesta si el frontend prefiere strings simples
if (is_array($data)) {
    foreach ($data as &$item) {
        $item['nombre_creacion']    = $item['u_creacion']['nombre_usuario'] ?? $item['u_creacion']['email'] ?? null;
        $item['nombre_aprobacion']  = $item['u_aprobacion']['nombre_usuario'] ?? $item['u_aprobacion']['email'] ?? null;
        $item['nombre_facturacion'] = $item['u_facturacion']['nombre_usuario'] ?? $item['u_facturacion']['email'] ?? null;
    }
}

echo json_encode([
    'permisos' => $usuarioPermisos,
    'data' => $data
]);
