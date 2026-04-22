<?php
// list.php — Proxy seguro para listar archivos ya subidos
header('Content-Type: application/json');

$LIST_URL = 'https://gateway.srv1593908.hstgr.cloud/upload/list?folder=chat-exports';
$AGENT_KEY = 'izi-karl-2026';
$EXPECTED_PASS_HASH = 'c1d7eb5a1030fb7a77fcdbe0a83ff05296ed0e9171d85ce93c0528bc6bc0d760';

$auth_pass = $_SERVER['HTTP_X_UPLOAD_PASS'] ?? $_GET['auth_pass'] ?? '';
if (!$auth_pass || hash('sha256', $auth_pass) !== $EXPECTED_PASS_HASH) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$ch = curl_init($LIST_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['x-agent-key: ' . $AGENT_KEY],
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
http_response_code($http_code);
echo $response;
