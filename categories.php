<?php
// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("Access-Control-Allow-Origin: http://localhost:3000");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: true");
    header("Content-Length: 0");
    header("Content-Type: text/plain");
    http_response_code(200);
    exit();
}

// Set CORS headers for actual requests
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

require_once 'config/database.php';

class CategoriesAPI {
    private $db;
    private $table = 'categories';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Check if connection is successful
        if ($this->db === null) {
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
    }

    // Get all categories with search
    public function getCategories() {
        try {
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            
            $query = "SELECT * FROM " . $this->table . " WHERE 1=1";
            $params = [];
            
            if (!empty($search)) {
                $query .= " AND (category_name LIKE ? OR category_id LIKE ?)";
                $searchTerm = "%$search%";
                $params = [$searchTerm, $searchTerm];
            }
            
            $query .= " ORDER BY created_at DESC";

            if (!empty($params)) {
                $stmt = $this->db->prepare($query);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $this->db->error);
                }
                
                $types = str_repeat('s', count($params));
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $this->db->query($query);
                if (!$result) {
                    throw new Exception("Query failed: " . $this->db->error);
                }
            }

            $categories = [];
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }

            echo json_encode([
                'success' => true, 
                'data' => $categories,
                'message' => 'Categories fetched successfully'
            ]);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Get single category
    public function getCategory($id) {
        try {
            $query = "SELECT * FROM " . $this->table . " WHERE category_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                echo json_encode([
                    'success' => true, 
                    'data' => $result->fetch_assoc(),
                    'message' => 'Category fetched successfully'
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Create new category
    public function createCategory() {
        try {
            // Get raw POST data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            if (empty($data['category_name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category name is required']);
                return;
            }

            // Generate category_id from category_name
            $category_id = $this->generateCategoryId($data['category_name']);
            $category_name = $data['category_name'];
            $img_url = $data['img_url'] ?? '';
            $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;
            $created_by = $data['created_by'] ?? 'Admin';
            $created_at = date('Y-m-d H:i:s');

            // Check if category_id already exists
            if ($this->categoryExists($category_id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category already exists']);
                return;
            }

            $query = "INSERT INTO " . $this->table . " 
                      (category_id, category_name, img_url, is_active, created_by, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("sssiss", 
                $category_id, $category_name, $img_url, $is_active, $created_by, $created_at
            );

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Category created successfully', 
                    'category_id' => $category_id
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Update category
    public function updateCategory($id) {
        try {
            // Get raw PUT data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data');
            }

            // Check if category exists
            if (!$this->categoryExists($id)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found']);
                return;
            }

            $query = "UPDATE " . $this->table . " SET 
                      category_name = ?, img_url = ?, is_active = ?, updated_at = NOW()
                      WHERE category_id = ?";

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $is_active = isset($data['is_active']) ? (bool)$data['is_active'] : true;
            
            $stmt->bind_param("ssis", 
                $data['category_name'], $data['img_url'], $is_active, $id
            );

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Category updated successfully'
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Delete category
    public function deleteCategory($id) {
        try {
            // Check if category has associated products
            if ($this->hasAssociatedProducts($id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Cannot delete category with associated products']);
                return;
            }

            $query = "DELETE FROM " . $this->table . " WHERE category_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("s", $id);

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Category deleted successfully'
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Generate category ID from name
    private function generateCategoryId($categoryName) {
        $category_id = strtolower($categoryName);
        $category_id = preg_replace('/[^a-z0-9]+/', '-', $category_id);
        $category_id = trim($category_id, '-');
        return $category_id;
    }

    // Check if category exists
    private function categoryExists($category_id) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE category_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("s", $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] > 0;
    }

    // Check if category has associated products
    private function hasAssociatedProducts($category_id) {
        $query = "SELECT COUNT(*) as count FROM products WHERE category_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("s", $category_id);
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
$api = new CategoriesAPI();

// Only process if it's not an OPTIONS request (already handled above)
if ($method != 'OPTIONS') {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                $api->getCategory($_GET['id']);
            } else {
                $api->getCategories();
            }
            break;
        case 'POST':
            $api->createCategory();
            break;
        case 'PUT':
            $id = $_GET['id'] ?? '';
            if ($id) {
                $api->updateCategory($id);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category ID required']);
            }
            break;
        case 'DELETE':
            $id = $_GET['id'] ?? '';
            if ($id) {
                $api->deleteCategory($id);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category ID required']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
}
?>