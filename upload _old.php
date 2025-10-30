<?php
// upload.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check if file was uploaded
        if (!isset($_FILES['image'])) {
            throw new Exception('No file uploaded. Make sure you are using form-data with "image" as key.');
        }

        $uploadedFile = $_FILES['image'];
        
        // Check for upload errors
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
            ];
            throw new Exception($errorMessages[$uploadedFile['error']] ?? 'Unknown upload error');
        }

        // Validate file type
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png', 
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $fileType = finfo_file($fileInfo, $uploadedFile['tmp_name']);
        finfo_close($fileInfo);
        
        if (!array_key_exists($fileType, $allowedTypes)) {
            throw new Exception('Invalid file type. Allowed: JPEG, PNG, GIF, WebP. Detected: ' . $fileType);
        }

        // Validate file size (max 5MB)
        $maxSize = 5 * 1024 * 1024; // 5MB in bytes
        if ($uploadedFile['size'] > $maxSize) {
            throw new Exception('File size too large. Maximum 5MB allowed.');
        }

        // Create uploads directory if it doesn't exist
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        // Generate unique filename
        $fileExtension = $allowedTypes[$fileType];
        $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;

        // Move uploaded file
        if (move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
            // Generate full URL
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $baseUrl = $protocol . '://' . $host;
            
            // Remove any existing path (for XAMPP)
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            if ($scriptDir !== '/') {
                $baseUrl .= $scriptDir;
            }
            
            $imageUrl = $baseUrl . '/' . $filePath;
            
            // Success response
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'imageUrl' => $imageUrl,
                'filePath' => $filePath,
                'fileName' => $fileName,
                'fileSize' => $uploadedFile['size'],
                'fileType' => $fileType
            ]);
            
        } else {
            throw new Exception('Failed to move uploaded file to destination');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'debug' => [
                'files_received' => $_FILES,
                'post_data' => $_POST
            ]
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}
?>