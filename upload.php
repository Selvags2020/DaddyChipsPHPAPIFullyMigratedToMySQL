<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

class UploadAPI {
    private $uploadDir = 'uploads/';
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    private $maxSize = 5 * 1024 * 1024; // 5MB

    public function __construct() {
        // Create uploads directory if it doesn't exist
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    public function uploadImage() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            return;
        }

        if (!isset($_FILES['image'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No image file provided']);
            return;
        }

        $file = $_FILES['image'];

        // Validate file type
        if (!in_array($file['type'], $this->allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, and WEBP are allowed']);
            return;
        }

        // Validate file size
        if ($file['size'] > $this->maxSize) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File size must be less than 5MB']);
            return;
        }

        // Validate image dimensions
        $imageInfo = getimagesize($file['tmp_name']);
        if (!$imageInfo) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid image file']);
            return;
        }

        $minWidth = 300;
        $minHeight = 300;
        if ($imageInfo[0] < $minWidth || $imageInfo[1] < $minHeight) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Image must be at least {$minWidth}x{$minHeight} pixels"]);
            return;
        }

        // Generate unique filename
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
        $filePath = $this->uploadDir . $fileName;

        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Return the URL (adjust this to your actual domain)
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
            $imageUrl = $baseUrl . '/DaddyChipsAPI/' . $filePath;
            
            echo json_encode([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'imageUrl' => $imageUrl
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
        }
    }
}

$upload = new UploadAPI();
$upload->uploadImage();
?>