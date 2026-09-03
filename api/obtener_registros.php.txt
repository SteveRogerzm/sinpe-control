<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

$supabaseUrl = getenv('SUPABASE_URL');
$supabaseKey = getenv('SUPABASE_SERVICE_ROLE_KEY');

if (!$supabaseUrl || !$supabaseKey) {
    echo json_encode([]);
    exit;
}

$cleanBaseUrl = rtrim(trim($supabaseUrl), '/');
$dbUrl = $cleanBaseUrl . "/rest/v1/sinpes?select=*&order=created_at.desc";

$opts = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ],
    'http' => [
        'method' => 'GET',
        'header' => "apikey: " . trim($supabaseKey) . "\r\nAuthorization: Bearer " . trim($supabaseKey) . "\r\n",
        'ignore_errors' => true
    ]
];

$context = stream_context_create($opts);
$response = @file_get_contents($dbUrl, false, $context);

echo $response ?: json_encode([]);
