<?php
// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    require_once __DIR__ . '/config/appconfig.php';
    $allowedOrigins = AppConfig::$ALLOWED_ORIGINS;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: " . $origin);
    } else {
        header("Access-Control-Allow-Origin: " . $allowedOrigins[0]);
    }
    
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: true");
    header("Content-Length: 0");
    header("Content-Type: text/plain");
    http_response_code(200);
    
    AppConfig::debug("OPTIONS preflight request handled for upload - origin: " . $origin);
    exit();
}

// Set CORS headers for actual requests
require_once __DIR__ . '/config/appconfig.php';
$allowedOrigins = AppConfig::$ALLOWED_ORIGINS;
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $origin);
} else {
    header("Access-Control-Allow-Origin: " . $allowedOrigins[0]);
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/auth/middleware.php';

class UploadAPI {
    private $uploadDir = 'uploads/';
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    private $maxSize = 5 * 1024 * 1024; // 5MB

    public function __construct() {
        AppConfig::debug("UploadAPI constructor called");
        
        // Create uploads directory if it doesn't exist
        if (!file_exists($this->uploadDir)) {
            if (mkdir($this->uploadDir, 0777, true)) {
                AppConfig::debug("Upload directory created: " . $this->uploadDir);
            } else {
                AppConfig::error("Failed to create upload directory: " . $this->uploadDir);
                throw new Exception("Failed to create upload directory");
            }
        } else {
            AppConfig::debug("Upload directory exists: " . $this->uploadDir);
        }
    }

    public function uploadImage() {
        AppConfig::debug("uploadImage method called");
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for upload");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                AppConfig::warn("Method not allowed for upload: " . $_SERVER['REQUEST_METHOD']);
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                return;
            }

            if (!isset($_FILES['image'])) {
                AppConfig::warn("No image file provided in upload request by user: " . $user->email);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No image file provided']);
                return;
            }

            $file = $_FILES['image'];
            AppConfig::debug("File upload attempt - Name: " . $file['name'] . ", Size: " . $file['size'] . " bytes, Type: " . $file['type']);

            // Check for upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = $this->getUploadError($file['error']);
                AppConfig::warn("File upload error: " . $errorMsg . " - File: " . $file['name']);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $errorMsg]);
                return;
            }

            // Validate file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($detectedType, $this->allowedTypes)) {
                AppConfig::warn("Invalid file type detected: " . $detectedType . " for file: " . $file['name']);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, and WEBP are allowed']);
                return;
            }

            // Validate file size
            if ($file['size'] > $this->maxSize) {
                AppConfig::warn("File too large: " . $file['name'] . " - " . $file['size'] . " bytes (max: " . $this->maxSize . ")");
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'File size must be less than 5MB']);
                return;
            }

            // Validate image dimensions
            $imageInfo = getimagesize($file['tmp_name']);
            if (!$imageInfo) {
                AppConfig::warn("Invalid image file: " . $file['name']);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid image file']);
                return;
            }

            $minWidth = 300;
            $minHeight = 300;
            if ($imageInfo[0] < $minWidth || $imageInfo[1] < $minHeight) {
                AppConfig::warn("Image dimensions too small: " . $imageInfo[0] . "x" . $imageInfo[1] . " for file: " . $file['name']);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Image must be at least {$minWidth}x{$minHeight} pixels"]);
                return;
            }

            AppConfig::debug("Image validation passed - Dimensions: " . $imageInfo[0] . "x" . $imageInfo[1] . ", Type: " . $detectedType);

            // Generate unique filename
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
            $filePath = $this->uploadDir . $fileName;

            AppConfig::debug("Moving uploaded file to: " . $filePath);

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Return the URL
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                $imageUrl = $baseUrl . '/DaddyChipsAPI/' . $filePath;
                
                AppConfig::log("Image uploaded successfully: " . $file['name'] . " -> " . $fileName . " by " . $user->email . " (ID: " . $user->user_id . ")");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Image uploaded successfully',
                    'imageUrl' => $imageUrl,
                    'fileInfo' => [
                        'originalName' => $file['name'],
                        'savedName' => $fileName,
                        'filePath' => $filePath,
                        'size' => $file['size'],
                        'dimensions' => [
                            'width' => $imageInfo[0],
                            'height' => $imageInfo[1]
                        ],
                        'mimeType' => $detectedType
                    ]
                ]);
            } else {
                AppConfig::error("Failed to move uploaded file: " . $file['name'] . " to " . $filePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
            }

        } catch (Exception $e) {
            AppConfig::error("Exception in uploadImage: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Get upload error message
    private function getUploadError($errorCode) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
        ];
        
        return $errors[$errorCode] ?? 'Unknown upload error';
    }

    private function sendErrorResponse($message) {
        AppConfig::error("Sending error response: " . $message);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
    }
}

// Handle requests with logging
AppConfig::debug("=== UPLOAD API REQUEST STARTED ===");
AppConfig::debug("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown'));
AppConfig::debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown'));

$method = $_SERVER['REQUEST_METHOD'];
$api = new UploadAPI();

// Handle POST requests for file uploads
if ($method == 'POST') {
    $api->uploadImage();
} else if ($method != 'OPTIONS') {
    AppConfig::warn("Method not allowed for upload: " . $method);
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

AppConfig::debug("=== UPLOAD API REQUEST COMPLETED ===");
?>