<?php
/**
 * Secure file upload handler shared by CRM features (e.g. live-chat attachments).
 * Validates + moves the file only; the caller is responsible for logging the
 * result wherever it's needed (e.g. a chat_messages row).
 */
class Uploader
{
    private const ALLOWED_MIME = [
        'application/zip' => 'zip',
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'video/mp4' => 'mp4',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB

    public static function handle(string $type, array $fileInfo): array
    {
        if (!isset($fileInfo['error']) || is_array($fileInfo['error'])) {
            return ['ok' => false, 'error' => 'Invalid parameters.'];
        }

        if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed. Error code: ' . $fileInfo['error']];
        }

        if ($fileInfo['size'] > self::MAX_SIZE) {
            return ['ok' => false, 'error' => 'Exceeded file size limit.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($fileInfo['tmp_name']);
        
        if (!array_key_exists($mime, self::ALLOWED_MIME)) {
            return ['ok' => false, 'error' => 'Invalid file format.'];
        }

        $ext = self::ALLOWED_MIME[$mime];
        
        // Secure random filename
        $newName = sprintf('%s_%s.%s',
            sha1_file($fileInfo['tmp_name']),
            bin2hex(random_bytes(4)),
            $ext
        );

        $uploadDir = __DIR__ . '/../data/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $dest = $uploadDir . '/' . $newName;

        if (!move_uploaded_file($fileInfo['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'Failed to move uploaded file.'];
        }

        return [
            'ok' => true,
            'path' => 'data/uploads/' . $newName,
            'type' => $type,
            'mime' => $mime,
            'name' => $fileInfo['name'] ?? $newName,
        ];
    }
}