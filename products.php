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
    
    AppConfig::debug("OPTIONS preflight request handled for products - origin: " . $origin);
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

class ProductsAPI {
    private $db;
    private $table = 'products';

    public function __construct() {
        AppConfig::debug("ProductsAPI constructor called");
        
        $database = new Database();
        $this->db = $database->getConnection();
        
        if ($this->db === null) {
            AppConfig::error("Database connection failed in ProductsAPI constructor");
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
        
        AppConfig::debug("Database connection established successfully");
    }

    // PUBLIC: Get all products with pagination and search
    public function getProducts() {
        AppConfig::debug("getProducts method called");
        
        try {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            $category_id = isset($_GET['category_id']) ? $_GET['category_id'] : '';
            
            $offset = ($page - 1) * $limit;

            AppConfig::debug("Pagination - Page: " . $page . ", Limit: " . $limit . ", Search: " . $search . ", Category: " . $category_id);

            // Build query
            $query = "SELECT p.*, c.category_name 
                      FROM " . $this->table . " p 
                      LEFT JOIN categories c ON p.category_id = c.category_id 
                      WHERE 1=1";
            
            $params = [];
            $types = "";
            
            if (!empty($search)) {
                $query .= " AND (p.name LIKE ? OR p.description LIKE ? OR c.category_name LIKE ?)";
                $searchTerm = "%$search%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
                $types .= "sss";
            }
            
            if (!empty($category_id)) {
                $query .= " AND p.category_id = ?";
                $params[] = $category_id;
                $types .= "s";
            }
            
            $query .= " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $types .= "ii";

            // Count total records for pagination
            $countQuery = "SELECT COUNT(*) as total FROM " . $this->table . " p LEFT JOIN categories c ON p.category_id = c.category_id WHERE 1=1";
            $countParams = [];
            $countTypes = "";
            
            if (!empty($search)) {
                $countQuery .= " AND (p.name LIKE ? OR p.description LIKE ? OR c.category_name LIKE ?)";
                $countParams = array_merge($countParams, [$searchTerm, $searchTerm, $searchTerm]);
                $countTypes .= "sss";
            }
            
            if (!empty($category_id)) {
                $countQuery .= " AND p.category_id = ?";
                $countParams[] = $category_id;
                $countTypes .= "s";
            }
            
            AppConfig::debug("Executing count query: " . $countQuery);
            AppConfig::debug("Count parameters: " . json_encode($countParams));

            $countStmt = $this->db->prepare($countQuery);
            if (!$countStmt) {
                AppConfig::error("Count prepare failed: " . $this->db->error);
                throw new Exception("Count prepare failed: " . $this->db->error);
            }
            
            if (!empty($countParams)) {
                $countStmt->bind_param($countTypes, ...$countParams);
            }
            
            $countStmt->execute();
            $totalResult = $countStmt->get_result();
            $totalData = $totalResult->fetch_assoc();
            $total = $totalData['total'];

            AppConfig::debug("Total products found: " . $total);

            // Get products
            AppConfig::debug("Executing main query: " . $query);
            AppConfig::debug("Query parameters: " . json_encode($params));

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();

            $products = [];
            while ($row = $result->fetch_assoc()) {
                // Convert tags from JSON to array
                $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
                $products[] = $row;
            }

            AppConfig::debug("Fetched " . count($products) . " products successfully");
            
            echo json_encode([
                'success' => true,
                'data' => $products,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => ceil($total / $limit)
                ],
                'message' => 'Products fetched successfully'
            ]);
            
        } catch (Exception $e) {
            AppConfig::error("Error in getProducts: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PUBLIC: Get single product
    public function getProduct($id) {
        AppConfig::debug("getProduct method called with ID: " . $id);
        
        try {
            $query = "SELECT p.*, c.category_name 
                      FROM " . $this->table . " p 
                      LEFT JOIN categories c ON p.category_id = c.category_id 
                      WHERE p.product_id = ? OR p.firebase_id = ?";
            
            AppConfig::debug("Executing query: " . $query);
            
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed for getProduct: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("ss", $id, $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $product = $result->fetch_assoc();
                $product['tags'] = $product['tags'] ? json_decode($product['tags'], true) : [];
                
                AppConfig::debug("Product found: " . $id);
                
                echo json_encode([
                    'success' => true, 
                    'data' => $product,
                    'message' => 'Product fetched successfully'
                ]);
            } else {
                AppConfig::debug("Product not found: " . $id);
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
            }
        } catch (Exception $e) {
            AppConfig::error("Error in getProduct: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PROTECTED: Create new product (auth required)
    public function createProduct() {
        AppConfig::debug("createProduct method called");
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for createProduct");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");
            
            // Get raw POST data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                AppConfig::error("Invalid JSON data in createProduct: " . json_last_error_msg());
                throw new Exception('Invalid JSON data');
            }

            AppConfig::debug("Received product data: " . json_encode($data));

            // Validate required fields
            $required = ['name', 'category_id', 'standard_price', 'stock_quantity', 'description'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    AppConfig::warn("Product creation failed: Missing required field - " . $field);
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                    return;
                }
            }

            $product_id = 'prod' . time() . rand(100, 999);
            $firebase_id = $data['firebase_id'] ?? '';
            $name = trim($data['name']);
            $description = trim($data['description']);
            $category_id = $data['category_id'];
            $category_name = $data['category_name'] ?? '';
            $standard_price = (float)$data['standard_price'];
            $offer_price = isset($data['offer_price']) && $data['offer_price'] !== '' ? (float)$data['offer_price'] : null;
            $stock_quantity = (int)$data['stock_quantity'];
            $product_image = $data['product_image'] ?? '';
            $tags = isset($data['tags']) ? json_encode($data['tags']) : '[]';
            $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;
            $created_by = $user->email; // Use authenticated user's email
            $created_at = date('Y-m-d H:i:s');

            // Validate product name length
            if (strlen($name) < 2) {
                AppConfig::warn("Product creation failed: name too short - " . $name);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Product name must be at least 2 characters long']);
                return;
            }

            // Validate price
            if ($standard_price <= 0) {
                AppConfig::warn("Product creation failed: invalid standard price - " . $standard_price);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Standard price must be greater than 0']);
                return;
            }

            $query = "INSERT INTO " . $this->table . " 
                      (product_id, firebase_id, name, description, category_id, category_name, 
                       standard_price, offer_price, stock_quantity, product_image, tags, 
                       is_active, created_by, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            AppConfig::debug("Executing insert query for product: " . $name);

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed for createProduct: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            // Handle null offer_price
            if ($offer_price === null) {
                $stmt->bind_param("ssssssdississ", 
                    $product_id, $firebase_id, $name, $description, $category_id, $category_name,
                    $standard_price, $offer_price, $stock_quantity, $product_image, $tags,
                    $is_active, $created_by, $created_at
                );
            } else {
                $stmt->bind_param("ssssssddsssiss", 
                    $product_id, $firebase_id, $name, $description, $category_id, $category_name,
                    $standard_price, $offer_price, $stock_quantity, $product_image, $tags,
                    $is_active, $created_by, $created_at
                );
            }

            if ($stmt->execute()) {
                AppConfig::log("Product created successfully: " . $name . " (ID: " . $product_id . ") by " . $user->email);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Product created successfully', 
                    'product_id' => $product_id,
                    'data' => [
                        'product_id' => $product_id,
                        'name' => $name,
                        'category_id' => $category_id,
                        'standard_price' => $standard_price,
                        'stock_quantity' => $stock_quantity,
                        'is_active' => $is_active
                    ]
                ]);
            } else {
                AppConfig::error("Execute failed for createProduct: " . $stmt->error);
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            AppConfig::error("Exception in createProduct: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PROTECTED: Update product (auth required)
    public function updateProduct($id) {
        AppConfig::debug("updateProduct method called for ID: " . $id);
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for updateProduct");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");
            
            // Get raw PUT data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                AppConfig::error("Invalid JSON data in updateProduct: " . json_last_error_msg());
                throw new Exception('Invalid JSON data');
            }

            AppConfig::debug("Received update data for product " . $id . ": " . json_encode($data));

            // Check if product exists
            if (!$this->productExists($id)) {
                AppConfig::warn("Update failed: product not found - " . $id);
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                return;
            }

            $query = "UPDATE " . $this->table . " SET 
                      name = ?, description = ?, category_id = ?, category_name = ?,
                      standard_price = ?, offer_price = ?, stock_quantity = ?, 
                      product_image = ?, tags = ?, is_active = ?, updated_at = NOW()
                      WHERE product_id = ? OR firebase_id = ?";

            $tags = isset($data['tags']) ? json_encode($data['tags']) : '[]';
            $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;
            
            // Handle null offer_price
            $offer_price = isset($data['offer_price']) && $data['offer_price'] !== '' ? (float)$data['offer_price'] : null;

            AppConfig::debug("Executing update query for product: " . $id);

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed for updateProduct: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            // Extract variables for binding
            $name = trim($data['name']);
            $description = trim($data['description']);
            $category_id = $data['category_id'];
            $category_name = $data['category_name'] ?? '';
            $standard_price = (float)$data['standard_price'];
            $stock_quantity = (int)$data['stock_quantity'];
            $product_image = $data['product_image'] ?? '';
            
            if ($offer_price === null) {
                // Use different binding for null offer_price
                $stmt->bind_param("ssssdississ", 
                    $name, $description, $category_id, $category_name,
                    $standard_price, $offer_price, $stock_quantity,
                    $product_image, $tags, $is_active, $id, $id
                );
            } else {
                // Use regular binding for non-null offer_price
                $stmt->bind_param("ssssddsssiss", 
                    $name, $description, $category_id, $category_name,
                    $standard_price, $offer_price, $stock_quantity,
                    $product_image, $tags, $is_active, $id, $id
                );
            }

            if ($stmt->execute()) {
                AppConfig::log("Product updated successfully: " . $id . " by " . $user->email);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Product updated successfully',
                    'data' => [
                        'product_id' => $id,
                        'name' => $name,
                        'category_id' => $category_id,
                        'standard_price' => $standard_price,
                        'stock_quantity' => $stock_quantity,
                        'is_active' => $is_active
                    ]
                ]);
            } else {
                AppConfig::error("Execute failed for updateProduct: " . $stmt->error);
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            AppConfig::error("Exception in updateProduct: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // PROTECTED: Delete product (auth required)
    public function deleteProduct($id) {
        AppConfig::debug("deleteProduct method called for ID: " . $id);
        
        try {
            // Require authentication for this method
            AppConfig::debug("Attempting to authenticate user for deleteProduct");
            $user = AuthMiddleware::requireAuth();
            AppConfig::debug("User authenticated: " . $user->email . " (ID: " . $user->user_id . ")");
            
            // Check if product exists
            if (!$this->productExists($id)) {
                AppConfig::warn("Delete failed: product not found - " . $id);
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                return;
            }

            // Get product name for logging before deletion
            $product_name = $this->getProductName($id);

            $query = "DELETE FROM " . $this->table . " WHERE product_id = ? OR firebase_id = ?";
            
            AppConfig::debug("Executing delete query for product: " . $id . " (" . $product_name . ")");

            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                AppConfig::error("Prepare failed for deleteProduct: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("ss", $id, $id);

            if ($stmt->execute()) {
                AppConfig::log("Product deleted successfully: " . $product_name . " (" . $id . ") by " . $user->email);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Product deleted successfully'
                ]);
            } else {
                AppConfig::error("Execute failed for deleteProduct: " . $stmt->error);
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            AppConfig::error("Exception in deleteProduct: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Helper methods
    private function productExists($id) {
        AppConfig::debug("Checking if product exists: " . $id);
        
        $query = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE product_id = ? OR firebase_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ss", $id, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $exists = $row['count'] > 0;
        AppConfig::debug("Product exists check for " . $id . ": " . ($exists ? 'YES' : 'NO'));
        
        return $exists;
    }

    private function getProductName($id) {
        $query = "SELECT name FROM " . $this->table . " WHERE product_id = ? OR firebase_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ss", $id, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $name = $row['name'] ?? '';
        AppConfig::debug("Retrieved product name for " . $id . ": " . $name);
        
        return $name;
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
AppConfig::debug("=== PRODUCTS API REQUEST STARTED ===");
AppConfig::debug("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown'));
AppConfig::debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown'));
AppConfig::debug("Query String: " . ($_SERVER['QUERY_STRING'] ?? 'None'));

$method = $_SERVER['REQUEST_METHOD'];
$api = new ProductsAPI();

// Get the request parameters
$id = $_GET['id'] ?? '';

// Only process if it's not an OPTIONS request
if ($method != 'OPTIONS') {
    AppConfig::debug("Routing main request - Method: " . $method . ", ID: " . ($id ? $id : 'none'));
    
    try {
        switch ($method) {
            case 'GET':
                if ($id) {
                    $api->getProduct($id);
                } else {
                    $api->getProducts();
                }
                break;
            case 'POST':
                $api->createProduct();
                break;
            case 'PUT':
                if ($id) {
                    $api->updateProduct($id);
                } else {
                    AppConfig::warn("PUT request missing product ID");
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Product ID required']);
                }
                break;
            case 'DELETE':
                if ($id) {
                    $api->deleteProduct($id);
                } else {
                    AppConfig::warn("DELETE request missing product ID");
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Product ID required']);
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

AppConfig::debug("=== PRODUCTS API REQUEST COMPLETED ===");
?>