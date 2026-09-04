<?php
function verificarAcceso() {
    $allowedEmails = ['tu-correo@gmail.com', 'otro-permitido@gmail.com'];

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

    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1])), true);
    $email = $payload['email'] ?? '';

    if (!in_array(strtolower($email), array_map('strtolower', $allowedEmails))) {
        http_response_code(403);
        echo json_encode(['error' => "El correo {$email} no tiene permisos para acceder."]);
        exit;
    }

    return $email;
}
