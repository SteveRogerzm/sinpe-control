<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

$supabaseUrl = getenv('SUPABASE_URL');
$supabaseKey = getenv('SUPABASE_SERVICE_ROLE_KEY');

if (!$supabaseUrl || !$supabaseKey) {
    echo json_encode(['error' => 'Faltan variables de entorno SUPABASE_URL o SUPABASE_SERVICE_ROLE_KEY']);
    exit;
}

// Limpiamos la URL para evitar duplicaciones de /rest/v1
$cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($supabaseUrl), '/'));
$dbUrl = $cleanBaseUrl . "/rest/v1/sinpes?select=*&order=created_at.desc";

$ch = curl_init($dbUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPGET, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: " . trim($supabaseKey),
    "Authorization: Bearer " . trim($supabaseKey),
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 400 || !$response) {
    echo json_encode([]);
    exit;
}

echo $response;
