<?php
/**
 * serve_image.php - Serve images stored in database
 * Retrieves binary image data from DM_Checklist_Entries and outputs with correct headers
 */
session_start();
require_once '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['dm_user_id'])) {
    http_response_code(403);
    exit();
}

$sessionId = isset($_GET['session']) ? (int) $_GET['session'] : 0;
$itemId = isset($_GET['item']) ? (int) $_GET['item'] : 0;
$photoId = isset($_GET['photo_id']) ? (int) $_GET['photo_id'] : 0;

if (!$photoId && (!$sessionId || !$itemId)) {
    http_response_code(400);
    exit();
}

$entry = null;

if ($photoId) {
    // New logic: fetch by photo ID
    $query = "SELECT ImageData, MimeType, FilePath FROM DM_Checklist_Photos WHERE ID = ?";
    $entry = dbQueryOne($query, [$photoId]);
} else {
    // Legacy logic: fetch from entries
    // Note: This likely won't have FilePath unless joined, but legacy entries shouldn't have FilePaths in the old table anyway.
    $query = "SELECT ImageData, ImagePath FROM DM_Checklist_Entries WHERE SessionID = ? AND ItemID = ?";
    $entry = dbQueryOne($query, [$sessionId, $itemId]);
}

if (!$entry) {
    http_response_code(404);
    exit("Image not found");
}

$mimeType = $entry['MimeType'] ?? $entry['ImagePath'] ?? 'image/jpeg';
$filePath = $entry['FilePath'] ?? null;
$imageData = $entry['ImageData'] ?? null;

// 1. Check if FilePath exists and file is readable (New Method)
if ($filePath) {
    // Construct absolute path. stored as relative "uploads/..."
    $absolutePath = __DIR__ . '/../' . $filePath;

    if (file_exists($absolutePath)) {
        header('Content-Type: ' . $mimeType);
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($absolutePath));
        readfile($absolutePath);
        exit();
    }
    // If file missing but record says it should be there -> Error or Fallback to blob?
    // Let's fallback to blob just in case migration copy failed but we didn't wipe data yet.
}

// 2. Fallback to Helper/Legacy "ImagePath" (from old checklist entries, not photos table)
if (!empty($entry['ImagePath']) && file_exists($entry['ImagePath']) && !$imageData) {
    $mimeType = mime_content_type($entry['ImagePath']);
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=86400');
    readfile($entry['ImagePath']);
    exit();
}

// 3. Fallback to Blob (Old Method)
if (!empty($imageData)) {
    header('Content-Type: ' . $mimeType);
    header('Cache-Control: public, max-age=86400');

    if (is_resource($imageData)) {
        fpassthru($imageData);
    } else {
        header('Content-Length: ' . strlen($imageData));
        echo $imageData;
    }
    exit();
}

// 4. Nothing found
http_response_code(404);
echo "Image data not found.";
exit();
?>