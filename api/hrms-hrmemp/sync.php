<?php
$target = __DIR__ . '/sync/index.php';
if (is_file($target)) {
    require $target;
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Endpoint not found.'], JSON_UNESCAPED_SLASHES);
