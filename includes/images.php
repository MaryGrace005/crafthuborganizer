<?php
// ============================================================
//  Image Upload Helper — CraftHub Organizer
//  Handles booking image uploads, retrieval, and deletion.
// ============================================================

define('UPLOAD_BASE_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_BASE_URL', APP_URL . '/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB
define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'gif']);

// -----------------------------------------------
//  Upload a booking image
//  Returns ['success' => true, 'image_id' => X] or ['success' => false, 'error' => '...']
// -----------------------------------------------
function uploadBookingImage(
    int    $bookingId,
    int    $uploadedBy,
    array  $file,             // $_FILES['image'] element
    string $imageType  = 'event_photo',
    string $caption    = '',
    int    $isPublic   = 0
): array {
    // Validate upload
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errMsg = match($file['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (max 5 MB).',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            default              => 'Upload failed. Please try again.',
        };
        return ['success' => false, 'error' => $errMsg];
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'error' => 'File size exceeds 5 MB limit.'];
    }

    // Validate MIME type (real type, not spoofed extension)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
        return ['success' => false, 'error' => 'Only JPEG, PNG, WebP, and GIF images are allowed.'];
    }

    // Build safe filename
    $ext         = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return ['success' => false, 'error' => 'Invalid file extension.'];
    }

    $safeName    = 'bk' . $bookingId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetDir   = UPLOAD_BASE_DIR . 'booking_images/';
    $targetPath  = $targetDir . $safeName;
    $relativePath = 'uploads/booking_images/' . $safeName;

    // Ensure directory exists
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file.'];
    }

    // Insert into DB
    try {
        $db  = getDB();
        $stmt = $db->prepare("
            INSERT INTO booking_images
                (booking_id, uploaded_by, image_type, image_path, original_name, mime_type, file_size, caption, is_public)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bookingId,
            $uploadedBy,
            $imageType,
            $relativePath,
            basename($file['name']),
            $mimeType,
            $file['size'],
            $caption ?: null,
            $isPublic,
        ]);
        $imageId = (int)$db->lastInsertId();

        logAudit($uploadedBy, 'IMAGE_UPLOAD', "Uploaded {$imageType} for booking #{$bookingId}: {$safeName}", 'booking_images');

        return ['success' => true, 'image_id' => $imageId, 'path' => $relativePath];
    } catch (PDOException $e) {
        // Clean up file if DB insert failed
        @unlink($targetPath);
        return ['success' => false, 'error' => 'Database error saving image record.'];
    }
}

// -----------------------------------------------
//  Get all images for a booking
//  $publicOnly = true: only show images marked is_public = 1
// -----------------------------------------------
function getBookingImages(int $bookingId, bool $publicOnly = false): array {
    try {
        $db   = getDB();
        $sql  = "
            SELECT bi.*, u.name AS uploader_name, u.role AS uploader_role
            FROM booking_images bi
            LEFT JOIN users u ON bi.uploaded_by = u.user_id
            WHERE bi.booking_id = ?
        ";
        if ($publicOnly) {
            $sql .= " AND bi.is_public = 1";
        }
        $sql .= " ORDER BY bi.uploaded_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// -----------------------------------------------
//  Get images by type for a booking
// -----------------------------------------------
function getBookingImagesByType(int $bookingId, string $type): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT bi.*, u.name AS uploader_name
            FROM booking_images bi
            LEFT JOIN users u ON bi.uploaded_by = u.user_id
            WHERE bi.booking_id = ? AND bi.image_type = ?
            ORDER BY bi.uploaded_at DESC
        ");
        $stmt->execute([$bookingId, $type]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// -----------------------------------------------
//  Delete a booking image (by image_id)
//  Checks ownership unless $force = true (admin)
// -----------------------------------------------
function deleteBookingImage(int $imageId, int $requestedBy, bool $force = false): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM booking_images WHERE image_id = ?");
        $stmt->execute([$imageId]);
        $img  = $stmt->fetch();

        if (!$img) {
            return ['success' => false, 'error' => 'Image not found.'];
        }

        // Only allow the uploader or admin/force delete
        if (!$force && (int)$img['uploaded_by'] !== $requestedBy) {
            return ['success' => false, 'error' => 'You do not have permission to delete this image.'];
        }

        // Delete file from disk
        $fullPath = UPLOAD_BASE_DIR . '../' . $img['image_path'];
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }

        // Delete DB record
        $db->prepare("DELETE FROM booking_images WHERE image_id = ?")->execute([$imageId]);

        logAudit($requestedBy, 'IMAGE_DELETE', "Deleted image #{$imageId} for booking #{$img['booking_id']}", 'booking_images');
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => 'Database error deleting image.'];
    }
}

// -----------------------------------------------
//  Get the full public URL for a stored image path
// -----------------------------------------------
function imageUrl(string $relativePath): string {
    return APP_URL . '/' . ltrim($relativePath, '/');
}

// -----------------------------------------------
//  Count images for a booking
// -----------------------------------------------
function countBookingImages(int $bookingId): int {
    try {
        $db = getDB();
        $s  = $db->prepare("SELECT COUNT(*) FROM booking_images WHERE booking_id = ?");
        $s->execute([$bookingId]);
        return (int)$s->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
