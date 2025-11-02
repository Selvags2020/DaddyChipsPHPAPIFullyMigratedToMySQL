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
    
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: true");
    header("Content-Length: 0");
    header("Content-Type: text/plain");
    http_response_code(200);
    
    AppConfig::debug("OPTIONS preflight request handled for settings - origin: " . $origin);
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

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/auth/middleware.php';

class SettingsAPI {
    private $db;
    private $table = 'settings';

    public function __construct() {
        AppConfig::debug("SettingsAPI constructor called");
        
        $database = new Database();
        $this->db = $database->getConnection();
        
        if ($this->db === null) {
            AppConfig::error("Database connection failed in SettingsAPI constructor");
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
        
        AppConfig::debug("Database connection established successfully");
    }

    // PUBLIC: Get all settings (no auth required for read)
    public function getSettings() {
        AppConfig::debug("getSettings method called");
        
        try {
            $query = "SELECT * FROM " . $this->table . " ORDER BY setting_id";
            $result = $this->db->query($query);

            if (!$result) {
                AppConfig::error("Query failed for getSettings: " . $this->db->error);
                throw new Exception("Query failed: " . $this->db->error);
            }

            $settings = [];
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            // Ensure BusinessWhatsAppNumber exists
            if (!isset($settings['BusinessWhatsAppNumber'])) {
                $settings['BusinessWhatsAppNumber'] = '';
                AppConfig::debug("BusinessWhatsAppNumber not found in database, using empty default");
            }

            AppConfig::debug("Fetched " . count($settings) . " settings successfully");
            
            echo json_encode([
                'success' => true,
                'data' => $settings,
                'message' => 'Settings fetched successfully'
            ]);
            
        } catch (Exception $e) {
            AppConfig::error("Error in getSettings: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PROTECTED: Update settings (auth required)
    public function updateSettings() {
        AppConfig::debug("updateSettings method called");
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for updateSettings");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");
            
            // Get raw PUT data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                AppConfig::error("Invalid JSON data in updateSettings: " . json_last_error_msg());
                throw new Exception('Invalid JSON data');
            }

            AppConfig::debug("Received settings update data: " . json_encode($data));

            // Validate required fields
            if (!isset($data['BusinessWhatsAppNumber'])) {
                AppConfig::warn("Settings update failed: BusinessWhatsAppNumber is required");
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'WhatsApp number is required']);
                return;
            }

            // Validate WhatsApp number format
            $whatsappNumber = trim($data['BusinessWhatsAppNumber']);
            if (!empty($whatsappNumber) && !preg_match('/^\d{10,15}$/', $whatsappNumber)) {
                AppConfig::warn("Settings update failed: Invalid WhatsApp number format - " . $whatsappNumber);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please enter a valid WhatsApp number (10-15 digits)']);
                return;
            }

            // Check if setting exists
            $checkQuery = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE setting_key = 'BusinessWhatsAppNumber'";
            $checkResult = $this->db->query($checkQuery);
            
            if (!$checkResult) {
                AppConfig::error("Check query failed: " . $this->db->error);
                throw new Exception("Check query failed: " . $this->db->error);
            }
            
            $checkData = $checkResult->fetch_assoc();

            AppConfig::debug("BusinessWhatsAppNumber exists in database: " . ($checkData['count'] > 0 ? 'YES' : 'NO'));

            if ($checkData['count'] > 0) {
                // Update existing setting
                $query = "UPDATE " . $this->table . " SET 
                          setting_value = ?, updated_at = NOW()
                          WHERE setting_key = 'BusinessWhatsAppNumber'";
                AppConfig::debug("Updating existing BusinessWhatsAppNumber setting");
            } else {
                // Insert new setting
                $query = "INSERT INTO " . $this->table . " 
                          (setting_key, setting_value, description, created_at) 
                          VALUES ('BusinessWhatsAppNumber', ?, 'Primary business WhatsApp number', NOW())";
                AppConfig::debug("Inserting new BusinessWhatsAppNumber setting");
            }

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed for updateSettings: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("s", $whatsappNumber);

            if ($stmt->execute()) {
                AppConfig::log("Settings updated successfully by " . $user->email . " (ID: " . $user->user_id . ") - WhatsApp: " . ($whatsappNumber ?: 'empty'));
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Settings updated successfully',
                    'data' => [
                        'BusinessWhatsAppNumber' => $whatsappNumber
                    ]
                ]);
            } else {
                AppConfig::error("Execute failed for updateSettings: " . $stmt->error);
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            AppConfig::error("Exception in updateSettings: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PROTECTED: Reset settings (auth required)
    public function resetSettings() {
        AppConfig::debug("resetSettings method called");
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for resetSettings");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");

            $query = "UPDATE " . $this->table . " SET 
                      setting_value = '', updated_at = NOW()
                      WHERE setting_key = 'BusinessWhatsAppNumber'";

            AppConfig::debug("Executing reset query for BusinessWhatsAppNumber");

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed for resetSettings: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            if ($stmt->execute()) {
                AppConfig::log("Settings reset successfully by " . $user->email . " (ID: " . $user->user_id . ")");
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Settings reset successfully',
                    'data' => [
                        'BusinessWhatsAppNumber' => ''
                    ]
                ]);
            } else {
                AppConfig::error("Execute failed for resetSettings: " . $stmt->error);
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            AppConfig::error("Exception in resetSettings: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Test token endpoint with logging
    public function testToken() {
        AppConfig::debug("testToken endpoint called for settings");
        
        try {
            AppConfig::debug("Attempting to authenticate user for testToken");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated successfully: " . $user->email . " (ID: " . $user->user_id . ")");
            
            echo json_encode([
                'success' => true,
                'message' => 'Token is valid for settings API',
                'user' => [
                    'user_id' => $user->user_id,
                    'email' => $user->email,
                    'role' => $user->role
                ]
            ]);
        } catch (Exception $e) {
            AppConfig::error("Token validation failed in settings testToken: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Token validation failed: ' . $e->getMessage()
            ]);
        }
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
AppConfig::debug("=== SETTINGS API REQUEST STARTED ===");
AppConfig::debug("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown'));
AppConfig::debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown'));

$method = $_SERVER['REQUEST_METHOD'];
$api = new SettingsAPI();

// Only process if it's not an OPTIONS request
if ($method != 'OPTIONS') {
    // Add a test endpoint
    if (isset($_GET['test_token'])) {
        AppConfig::debug("Routing to test_token endpoint");
        $api->testToken();
        exit;
    }
    
    AppConfig::debug("Routing main request - Method: " . $method);
    
    try {
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
                AppConfig::warn("Method not allowed: " . $method);
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                break;
        }
    } catch (Exception $e) {
        AppConfig::error("Unhandled exception in request router: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Internal server error: ' . $e->getMessage()
        ]);
    }
}

AppConfig::debug("=== SETTINGS API REQUEST COMPLETED ===");
?>