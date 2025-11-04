<?php
// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Origin: https://daddychips.co.in");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: true");
    header("Content-Length: 0");
    header("Content-Type: text/plain");
    http_response_code(200);
    exit();
}

// Set CORS headers for actual requests
// header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Origin: https://daddychips.co.in");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

require_once 'config/database.php';

class SettingsAPI {
    private $db;
    private $table = 'settings';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Check if connection is successful
        if ($this->db === null) {
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
    }

    // Get all settings
    public function getSettings() {
        try {
            $query = "SELECT * FROM " . $this->table . " ORDER BY setting_id";
            $result = $this->db->query($query);

            if (!$result) {
                throw new Exception("Query failed: " . $this->db->error);
            }

            $settings = [];
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            // Ensure BusinessWhatsAppNumber exists
            if (!isset($settings['BusinessWhatsAppNumber'])) {
                $settings['BusinessWhatsAppNumber'] = '';
            }

            echo json_encode([
                'success' => true,
                'data' => $settings,
                'message' => 'Settings fetched successfully'
            ]);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Update settings
    public function updateSettings() {
        try {
            // Get raw PUT data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            if (!isset($data['BusinessWhatsAppNumber'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'WhatsApp number is required']);
                return;
            }

            // Validate WhatsApp number format
            $whatsappNumber = $data['BusinessWhatsAppNumber'];
            if (!empty($whatsappNumber) && !preg_match('/^\d{10,15}$/', $whatsappNumber)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please enter a valid WhatsApp number (10-15 digits)']);
                return;
            }

            // Check if setting exists
            $checkQuery = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE setting_key = 'BusinessWhatsAppNumber'";
            $checkResult = $this->db->query($checkQuery);
            $checkData = $checkResult->fetch_assoc();

            if ($checkData['count'] > 0) {
                // Update existing setting
                $query = "UPDATE " . $this->table . " SET 
                          setting_value = ?, updated_at = NOW()
                          WHERE setting_key = 'BusinessWhatsAppNumber'";
            } else {
                // Insert new setting
                $query = "INSERT INTO " . $this->table . " 
                          (setting_key, setting_value, description, created_at) 
                          VALUES ('BusinessWhatsAppNumber', ?, 'Primary business WhatsApp number', NOW())";
            }

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("s", $whatsappNumber);

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Settings updated successfully'
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Reset settings
    public function resetSettings() {
        try {
            $query = "UPDATE " . $this->table . " SET 
                      setting_value = '', updated_at = NOW()
                      WHERE setting_key = 'BusinessWhatsAppNumber'";

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Settings reset successfully'
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Send error response
    private function sendErrorResponse($message) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
    }
}

// Handle requests
$method = $_SERVER['REQUEST_METHOD'];

// Only process if it's not an OPTIONS request (already handled above)
if ($method != 'OPTIONS') {
    $api = new SettingsAPI();
    
    switch ($method) {
        case 'GET':
            $api->getSettings();
            break;
        case 'PUT':
            $api->updateSettings();
            break;
        case 'DELETE':
            $api->resetSettings();
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
}
?>