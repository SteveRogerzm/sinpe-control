<?php
function verificarAcceso() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado: Token ausente']);
        exit;
    }

    // Decodificar el token JWT para extraer el email
    $jwt = $matches[1];
    $tokenParts = explode('.', $jwt);
    if (count($tokenParts) !== 3) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido']);
        exit;
    }

    $payloadBase64 = str_replace(['-', '_'], ['+', '/'], $tokenParts[1]);
    $padding = strlen($payloadBase64) % 4;
    if ($padding) {
        $payloadBase64 .= str_repeat('=', 4 - $padding);
    }
    $payload = json_decode(base64_decode($payloadBase64), true);
    $email = strtolower($payload['email'] ?? '');

    // Consultar permisos en la tabla usuarios en Supabase
    $supabaseUrl = getenv('SUPABASE_URL');
    $supabaseKey = getenv('SUPABASE_SERVICE_ROLE_KEY');
    
    $cleanUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($supabaseUrl), '/'));
    $queryUrl = $cleanUrl . "/rest/v1/usuarios?email=eq." . urlencode($email) . "&select=*";

    $ch = curl_init($queryUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: " . trim($supabaseKey),
        "Authorization: Bearer " . trim($supabaseKey),
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);

    $usuarios = json_decode($res, true);

    if (empty($usuarios) || !isset($usuarios[0])) {
        http_response_code(403);
        echo json_encode(['error' => "El correo {$email} no tiene permisos para acceder."]);
        exit;
    }

    // Retorna el array del usuario con todos sus flags de permisos
    return $usuarios[0];
}
