<?php
// upload.php — Proxy seguro al VPS karl-gateway
// Oculta el agent-key del navegador
// Valida el password con el mismo hash SHA256 del frontend

header('Content-Type: application/json');

// Config (estas vars quedan solo en el servidor Hostinger, no visibles al navegador)
$GATEWAY_URL = 'https://gateway.srv1593908.hstgr.cloud/upload/file';
$AGENT_KEY = 'izi-karl-2026';
$EXPECTED_PASS_HASH = 'c1d7eb5a1030fb7a77fcdbe0a83ff05296ed0e9171d85ce93c0528bc6bc0d760';
$MAX_SIZE = 500 * 1024 * 1024; // 500 MB

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validar password (viene del cliente en header o form)
$auth_pass = $_SERVER['HTTP_X_UPLOAD_PASS'] ?? $_POST['auth_pass'] ?? '';
if (!$auth_pass) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing auth password']);
    exit;
}

if (hash('sha256', $auth_pass) !== $EXPECTED_PASS_HASH) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid password']);
    exit;
}

// Validar archivo
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $err = $_FILES['file']['error'] ?? 'unknown';
    echo json_encode(['error' => 'File upload error', 'code' => $err]);
    exit;
}

if ($_FILES['file']['size'] > $MAX_SIZE) {
    http_response_code(413);
    echo json_encode(['error' => 'File too large (max 500 MB)']);
    exit;
}

$tmp_path = $_FILES['file']['tmp_name'];
$orig_name = $_FILES['file']['name'];
$description = $_POST['description'] ?? 'Upload desde panel Hostinger';
$folder = $_POST['folder'] ?? 'chat-exports';

// Forward al gateway con el agent-key secreto
$ch = curl_init($GATEWAY_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'x-agent-key: ' . $AGENT_KEY,
    ],
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($tmp_path, $_FILES['file']['type'], $orig_name),
        'folder' => $folder,
        'description' => $description,
    ],
    CURLOPT_TIMEOUT => 600, // 10 min para archivos grandes
    CURLOPT_CONNECTTIMEOUT => 30,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    http_response_code(502);
    echo json_encode(['error' => 'Gateway error', 'detail' => $curl_err]);
    exit;
}

http_response_code($http_code);
echo $response;
