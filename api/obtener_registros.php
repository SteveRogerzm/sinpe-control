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
$dbUrl = $cleanBaseUrl . "/rest/v1/sinpes?select=*&order=created_at.desc";

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

echo json_encode([
    'permisos' => $usuarioPermisos,
    'data' => json_decode($response, true)
]);
