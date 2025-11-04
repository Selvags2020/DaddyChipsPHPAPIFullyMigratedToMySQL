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

class ProductsAPI {
    private $db;
    private $table = 'products';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Check if connection is successful
        if ($this->db === null) {
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
    }

    // Get all products with pagination and search
    public function getProducts() {
        try {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            
            $offset = ($page - 1) * $limit;

            // Build query
            $query = "SELECT p.*, c.category_name 
                      FROM " . $this->table . " p 
                      LEFT JOIN categories c ON p.category_id = c.category_id 
                      WHERE 1=1";
            
            $params = [];
            
            if (!empty($search)) {
                $query .= " AND (p.name LIKE ? OR p.description LIKE ? OR c.category_name LIKE ?)";
                $searchTerm = "%$search%";
                $params = [$searchTerm, $searchTerm, $searchTerm];
            }
            
            $query .= " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            // Count total records for pagination
            $countQuery = "SELECT COUNT(*) as total FROM " . $this->table . " p WHERE 1=1";
            $countParams = [];
            
            if (!empty($search)) {
                $countQuery .= " AND (p.name LIKE ? OR p.description LIKE ?)";
                $countParams = [$searchTerm, $searchTerm];
            }
            
            $countStmt = $this->db->prepare($countQuery);
            if (!$countStmt) {
                throw new Exception("Count prepare failed: " . $this->db->error);
            }
            
            if (!empty($countParams)) {
                $countTypes = str_repeat('s', count($countParams));
                $countStmt->bind_param($countTypes, ...$countParams);
            }
            
            $countStmt->execute();
            $totalResult = $countStmt->get_result();
            $totalData = $totalResult->fetch_assoc();
            $total = $totalData['total'];

            // Get products
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            if (!empty($params)) {
                $types = '';
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } else {
                        $types .= 's';
                    }
                }
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
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Get single product
    public function getProduct($id) {
        try {
            $query = "SELECT p.*, c.category_name 
                      FROM " . $this->table . " p 
                      LEFT JOIN categories c ON p.category_id = c.category_id 
                      WHERE p.product_id = ? OR p.firebase_id = ?";
            
            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("ss", $id, $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $product = $result->fetch_assoc();
                $product['tags'] = $product['tags'] ? json_decode($product['tags'], true) : [];
                echo json_encode([
                    'success' => true, 
                    'data' => $product,
                    'message' => 'Product fetched successfully'
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Product not found']);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Create new product
    public function createProduct() {
        try {
            // Get raw POST data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            $required = ['name', 'category_id', 'standard_price', 'stock_quantity', 'description'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                    return;
                }
            }

            $product_id = 'prod' . time();
            $firebase_id = $data['firebase_id'] ?? '';
            $name = $data['name'];
            $description = $data['description'];
            $category_id = $data['category_id'];
            $category_name = $data['category_name'] ?? '';
            $standard_price = (float)$data['standard_price'];
            $offer_price = isset($data['offer_price']) && $data['offer_price'] !== '' ? (float)$data['offer_price'] : null;
            $stock_quantity = (int)$data['stock_quantity'];
            $product_image = $data['product_image'] ?? '';
            $tags = isset($data['tags']) ? json_encode($data['tags']) : '[]';
            $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;
            $created_by = $data['created_by'] ?? 'Admin';
            $created_at = date('Y-m-d H:i:s');

            $query = "INSERT INTO " . $this->table . " 
                      (product_id, firebase_id, name, description, category_id, category_name, 
                       standard_price, offer_price, stock_quantity, product_image, tags, 
                       is_active, created_by, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
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
                echo json_encode([
                    'success' => true, 
                    'message' => 'Product created successfully', 
                    'product_id' => $product_id
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Update product
    public function updateProduct($id) {
        try {
            // Get raw PUT data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data');
            }

            // Check if product exists
            if (!$this->productExists($id)) {
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

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            // Extract variables for binding
            $name = $data['name'];
            $description = $data['description'];
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
                echo json_encode([
                    'success' => true, 
                    'message' => 'Product updated successfully'
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Delete product
    public function deleteProduct($id) {
        try {
            $query = "DELETE FROM " . $this->table . " WHERE product_id = ? OR firebase_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("ss", $id, $id);

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Product deleted successfully'
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Check if product exists
    private function productExists($id) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE product_id = ? OR firebase_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ss", $id, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] > 0;
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
    $api = new ProductsAPI();
    
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $api->getProduct($_GET['id']);
            } else {
                $api->getProducts();
            }
            break;
        case 'POST':
            $api->createProduct();
            break;
        case 'PUT':
            $id = $_GET['id'] ?? '';
            if ($id) {
                $api->updateProduct($id);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Product ID required']);
            }
            break;
        case 'DELETE':
            $id = $_GET['id'] ?? '';
            if ($id) {
                $api->deleteProduct($id);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Product ID required']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
}
?>