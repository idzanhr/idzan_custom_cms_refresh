<?php
declare(strict_types=1);
// Image upload endpoint for the rich-text editor (returns JSON).
require __DIR__ . '/_init.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"error":"POST only"}'; exit; }
csrf_check();

try {
    $path = store_upload($_FILES['image'] ?? [], (string)($_POST['hint'] ?? ''));
    $info = @getimagesize(public_dir() . '/' . $path);
    echo json_encode(['url' => media_url($path), 'path' => $path, 'width' => $info[0] ?? null, 'height' => $info[1] ?? null]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
