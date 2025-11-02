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
    
    AppConfig::debug("OPTIONS preflight request handled for origin: " . $origin);
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

class CategoriesAPI {
    private $db;
    private $table = 'categories';

    public function __construct() {
        AppConfig::debug("CategoriesAPI constructor called");
        
        $database = new Database();
        $this->db = $database->getConnection();
        
        if ($this->db === null) {
            AppConfig::error("Database connection failed in CategoriesAPI constructor");
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
        
        AppConfig::debug("Database connection established successfully");
    }

    // PUBLIC: Get all categories (no auth required)
    public function getCategories() {
        AppConfig::debug("getCategories method called");
        
        try {
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            
            AppConfig::debug("Search parameter: " . ($search ? $search : 'empty'));
            
            $query = "SELECT category_id, category_name, img_url, is_active, created_at FROM " . $this->table . " WHERE 1=1";
            $params = [];
            $types = "";
            
            if (!empty($search)) {
                $query .= " AND (category_name LIKE ? OR category_id LIKE ?)";
                $searchTerm = "%$search%";
                $params = [$searchTerm, $searchTerm];
                $types = "ss";
            }
            
            $query .= " ORDER BY created_at DESC";

            AppConfig::debug("Executing query: " . $query);
            AppConfig::debug("Query parameters: " . json_encode($params));

            if (!empty($params)) {
                $stmt = $this->db->prepare($query);
                if (!$stmt) {
                    AppConfig::error("Prepare failed for query: " . $query . " - Error: " . $this->db->error);
                    throw new Exception("Prepare failed: " . $this->db->error);
                }
                
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $this->db->query($query);
                if (!$result) {
                    AppConfig::error("Query failed: " . $query . " - Error: " . $this->db->error);
                    throw new Exception("Query failed: " . $this->db->error);
                }
            }

            $categories = [];
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }

            AppConfig::debug("Fetched " . count($categories) . " categories successfully");
            
            echo json_encode([
                'success' => true, 
                'data' => $categories,
                'message' => 'Categories fetched successfully'
            ]);
            
        } catch (Exception $e) {
            AppConfig::error("Error in getCategories: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PUBLIC: Get single category (no auth required)
    public function getCategory($id) {
        AppConfig::debug("getCategory method called with ID: " . $id);
        
        try {
            $query = "SELECT category_id, category_name, img_url, is_active, created_at FROM " . $this->table . " WHERE category_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                AppConfig::error("Prepare failed for getCategory: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                AppConfig::debug("Category found: " . $id);
                echo json_encode([
                    'success' => true, 
                    'data' => $result->fetch_assoc(),
                    'message' => 'Category fetched successfully'
                ]);
            } else {
                AppConfig::debug("Category not found: " . $id);
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
            }
        } catch (Exception $e) {
            AppConfig::error("Error in getCategory: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PROTECTED: Create new category (auth required)
    public function createCategory() {
        AppConfig::debug("createCategory method called");
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for createCategory");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");
            
            // Get raw POST data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                AppConfig::error("Invalid JSON data in createCategory: " . json_last_error_msg());
                throw new Exception('Invalid JSON data');
            }

            AppConfig::debug("Received category data: " . json_encode($data));

            // Validate required fields
            if (empty($data['category_name'])) {
                AppConfig::warn("Category creation failed: category_name is required");
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category name is required']);
                return;
            }

            // Generate category_id from category_name
            $category_id = $this->generateCategoryId($data['category_name']);
            $category_name = trim($data['category_name']);
            $img_url = $data['img_url'] ?? '';
            $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;
            $created_by = $user->email; // Use authenticated user's email
            $created_at = date('Y-m-d H:i:s');

            // Validate category name length
            if (strlen($category_name) < 2) {
                AppConfig::warn("Category creation failed: name too short - " . $category_name);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category name must be at least 2 characters long']);
                return;
            }

            // Check if category_id already exists
            if ($this->categoryExists($category_id)) {
                AppConfig::warn("Category creation failed: already exists - " . $category_id);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category already exists']);
                return;
            }

            $query = "INSERT INTO " . $this->table . " 
                      (category_id, category_name, img_url, is_active, created_by, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?)";

            AppConfig::debug("Executing insert query for category: " . $category_name);

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed for createCategory: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("sssiss", 
                $category_id, $category_name, $img_url, $is_active, $created_by, $created_at
            );

            if ($stmt->execute()) {
                AppConfig::log("Category created successfully: " . $category_name . " (ID: " . $category_id . ") by " . $user->email);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Category created successfully', 
                    'category_id' => $category_id,
                    'data' => [
                        'category_id' => $category_id,
                        'category_name' => $category_name,
                        'img_url' => $img_url,
                        'is_active' => $is_active
                    ]
                ]);
            } else {
                AppConfig::error("Execute failed for createCategory: " . $stmt->error);
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            AppConfig::error("Exception in createCategory: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PROTECTED: Update category (auth required)
    public function updateCategory($id) {
        AppConfig::debug("updateCategory method called for ID: " . $id);
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for updateCategory");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");
            
            // Get raw PUT data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                AppConfig::error("Invalid JSON data in updateCategory: " . json_last_error_msg());
                throw new Exception('Invalid JSON data');
            }

            AppConfig::debug("Received update data for category " . $id . ": " . json_encode($data));

            // Check if category exists
            if (!$this->categoryExists($id)) {
                AppConfig::warn("Update failed: category not found - " . $id);
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                return;
            }

            // Validate required fields
            if (empty($data['category_name'])) {
                AppConfig::warn("Update failed: category_name is required for " . $id);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category name is required']);
                return;
            }

            $category_name = trim($data['category_name']);
            $img_url = $data['img_url'] ?? '';
            $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;

            // Validate category name length
            if (strlen($category_name) < 2) {
                AppConfig::warn("Update failed: name too short for " . $id . " - " . $category_name);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category name must be at least 2 characters long']);
                return;
            }

            $query = "UPDATE " . $this->table . " SET 
                      category_name = ?, img_url = ?, is_active = ?, updated_at = NOW()
                      WHERE category_id = ?";

            AppConfig::debug("Executing update query for category: " . $id);

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed for updateCategory: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("ssis", 
                $category_name, $img_url, $is_active, $id
            );

            if ($stmt->execute()) {
                AppConfig::log("Category updated successfully: " . $id . " by " . $user->email);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Category updated successfully',
                    'data' => [
                        'category_id' => $id,
                        'category_name' => $category_name,
                        'img_url' => $img_url,
                        'is_active' => $is_active
                    ]
                ]);
            } else {
                AppConfig::error("Execute failed for updateCategory: " . $stmt->error);
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            AppConfig::error("Exception in updateCategory: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PROTECTED: Delete category (auth required)
    public function deleteCategory($id) {
        AppConfig::debug("deleteCategory method called for ID: " . $id);
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for deleteCategory");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");
            
            // Check if category exists
            if (!$this->categoryExists($id)) {
                AppConfig::warn("Delete failed: category not found - " . $id);
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                return;
            }

            // Check if category has associated products
            if ($this->hasAssociatedProducts($id)) {
                AppConfig::warn("Delete failed: category has associated products - " . $id);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Cannot delete category with associated products']);
                return;
            }

            // Get category name for logging before deletion
            $category_name = $this->getCategoryName($id);

            $query = "DELETE FROM " . $this->table . " WHERE category_id = ?";
            
            AppConfig::debug("Executing delete query for category: " . $id . " (" . $category_name . ")");

            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                AppConfig::error("Prepare failed for deleteCategory: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("s", $id);

            if ($stmt->execute()) {
                AppConfig::log("Category deleted successfully: " . $category_name . " (" . $id . ") by " . $user->email);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Category deleted successfully'
                ]);
            } else {
                AppConfig::error("Execute failed for deleteCategory: " . $stmt->error);
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            AppConfig::error("Exception in deleteCategory: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Test token endpoint with logging
    public function testToken() {
        AppConfig::debug("testToken endpoint called");
        
        try {
            AppConfig::debug("Attempting to authenticate user for testToken");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated successfully: " . $user->email . " (ID: " . $user->user_id . ")");
            
            echo json_encode([
                'success' => true,
                'message' => 'Token is valid',
                'user' => [
                    'user_id' => $user->user_id,
                    'email' => $user->email,
                    'role' => $user->role
                ]
            ]);
        } catch (Exception $e) {
            AppConfig::error("Token validation failed in testToken: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Token validation failed: ' . $e->getMessage()
            ]);
        }
    }

    // Helper methods
    private function generateCategoryId($categoryName) {
        $category_id = strtolower(trim($categoryName));
        $category_id = preg_replace('/[^a-z0-9]+/', '-', $category_id);
        $category_id = trim($category_id, '-');
        
        AppConfig::debug("Generated category ID: " . $category_id . " from name: " . $categoryName);
        return $category_id;
    }

    private function categoryExists($category_id) {
        AppConfig::debug("Checking if category exists: " . $category_id);
        
        $query = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE category_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("s", $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $exists = $row['count'] > 0;
        AppConfig::debug("Category exists check for " . $category_id . ": " . ($exists ? 'YES' : 'NO'));
        
        return $exists;
    }

    private function getCategoryName($category_id) {
        $query = "SELECT category_name FROM " . $this->table . " WHERE category_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("s", $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $name = $row['category_name'] ?? '';
        AppConfig::debug("Retrieved category name for " . $category_id . ": " . $name);
        
        return $name;
    }

    private function hasAssociatedProducts($category_id) {
        AppConfig::debug("Checking for associated products for category: " . $category_id);
        
        $query = "SELECT COUNT(*) as count FROM products WHERE category_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("s", $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $hasProducts = $row['count'] > 0;
        AppConfig::debug("Category " . $category_id . " has associated products: " . ($hasProducts ? 'YES' : 'NO'));
        
        return $hasProducts;
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
AppConfig::debug("=== CATEGORIES API REQUEST STARTED ===");
AppConfig::debug("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown'));
AppConfig::debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown'));
AppConfig::debug("Query String: " . ($_SERVER['QUERY_STRING'] ?? 'None'));

$method = $_SERVER['REQUEST_METHOD'];
$api = new CategoriesAPI();

// Get the request parameters
$id = $_GET['id'] ?? '';

// Only process if it's not an OPTIONS request
if ($method != 'OPTIONS') {
    // Add a test endpoint
    if (isset($_GET['test_token'])) {
        AppConfig::debug("Routing to test_token endpoint");
        $api->testToken();
        exit;
    }
    
    AppConfig::debug("Routing main request - Method: " . $method . ", ID: " . ($id ? $id : 'none'));
    
    try {
        switch ($method) {
            case 'GET':
                if ($id) {
                    $api->getCategory($id);
                } else {
                    $api->getCategories();
                }
                break;
            case 'POST':
                $api->createCategory();
                break;
            case 'PUT':
                if ($id) {
                    $api->updateCategory($id);
                } else {
                    AppConfig::warn("PUT request missing category ID");
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Category ID required']);
                }
                break;
            case 'DELETE':
                if ($id) {
                    $api->deleteCategory($id);
                } else {
                    AppConfig::warn("DELETE request missing category ID");
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Category ID required']);
                }
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

AppConfig::debug("=== CATEGORIES API REQUEST COMPLETED ===");
?>