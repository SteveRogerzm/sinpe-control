<?php
function verificarAcceso() {
    $allowedEmails = ['rogerzunig3@gmail.com', 'otro-permitido@gmail.com'];

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado: Token ausente']);
        exit;
    }

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
    $email = $payload['email'] ?? '';

    if (!in_array(strtolower($email), array_map('strtolower', $allowedEmails))) {
        http_response_code(403);
        echo json_encode(['error' => "El correo {$email} no tiene permisos para acceder."]);
        exit;
    }

    return $email;
}
