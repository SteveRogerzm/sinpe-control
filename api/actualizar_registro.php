<?php
ini_set('display_errors', '0');
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth_check.php'; 

// verificarAcceso() valida el correo de Google y retorna el registro completo del usuario
$usuarioActual = verificarAcceso();

// Garantizar que se tome explícitamente el GUID/UUID único de la tabla usuarios
$idUsuario = isset($usuarioActual['id']) && !empty($usuarioActual['id']) 
    ? (string)$usuarioActual['id'] 
    : null;

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

    $id = $input['id'] ?? null;

    if (!$id) {
        throw new Exception("ID de registro no proporcionado.");
    }

    $cleanBaseUrl = preg_replace('/\/rest\/v1\/?$/', '', rtrim(trim($rawSupabaseUrl), '/'));
    $dbUrl = $cleanBaseUrl . "/rest/v1/sinpes?id=eq." . urlencode($id);

    // 1. CONSULTAR EL REGISTRO ACTUAL EN SUPABASE
    $chGet = curl_init($dbUrl);
    curl_setopt($chGet, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chGet, CURLOPT_HTTPHEADER, [
        "apikey: " . trim($supabaseKey),
        "Authorization: Bearer " . trim($supabaseKey),
        "Content-Type: application/json"
    ]);
    curl_setopt($chGet, CURLOPT_SSL_VERIFYPEER, false);
    
    $getResponse = curl_exec($chGet);
    $getHttpCode = curl_getinfo($chGet, CURLINFO_HTTP_CODE);
    curl_close($chGet);

    $registros = json_decode($getResponse, true);
    if ($getHttpCode >= 400 || empty($registros)) {
        throw new Exception("Registro no encontrado en la base de datos.");
    }

    $registroActual = $registros[0];
    $updateData = [];

    // 2. MANEJO DE ESTADO, FECHA Y USUARIO DE APROBACIÓN (GUID)
    if (isset($input['estado'])) {
        $updateData['estado'] = $input['estado'];
        
        if ($input['estado'] === 'Aprobado') {
            $updateData['fecha_aprobacion'] = gmdate('Y-m-d\TH:i:s\Z');
            $updateData['usuario_aprobacion'] = $idUsuario; // Se envía el GUID (string)
        } else {
            $updateData['fecha_aprobacion'] = null;
            $updateData['usuario_aprobacion'] = null;
        }
    }

    $estadoFinal = $updateData['estado'] ?? $registroActual['estado'];

    // 3. MANEJO DE FACTURACIÓN, FECHA Y USUARIO DE FACTURACIÓN (GUID)
    $quiereFacturar = false;
    if (isset($input['facturar'])) {
        $quiereFacturar = (bool)$input['facturar'];
    } elseif (isset($input['fecha_facturacion'])) {
        $quiereFacturar = !empty($input['fecha_facturacion']);
    }

    if ($quiereFacturar) {
        if ($estadoFinal !== 'Aprobado') {
            throw new Exception("No se puede facturar un registro que no esté Aprobado.");
        }

        if (isset($input['facturar'])) {
            $updateData['fecha_facturacion'] = gmdate('Y-m-d\TH:i:s\Z');
        } else {
            $updateData['fecha_facturacion'] = $input['fecha_facturacion'];
        }
        $updateData['usuario_facturacion'] = $idUsuario; // Se envía el GUID (string)
    } elseif (array_key_exists('facturar', $input) && !$input['facturar']) {
        $updateData['fecha_facturacion'] = null;
        $updateData['usuario_facturacion'] = null;
    }

    // 4. MANEJO DE COMENTARIOS
    if (isset($input['comentario'])) {
        $updateData['comentario'] = $input['comentario'];
    }

    if (empty($updateData)) {
        throw new Exception("No hay datos para actualizar.");
    }

    // 5. EJECUTAR PATCH EN SUPABASE
    $ch = curl_init($dbUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($updateData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: " . trim($supabaseKey),
        "Authorization: Bearer " . trim($supabaseKey),
        "Content-Type: application/json",
        "Prefer: return=representation"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new Exception("Error Supabase (HTTP $httpCode): " . $response);
    }

    echo json_encode(['success' => true, 'data' => json_decode($response, true)]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
