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

class ProductsFrontendAPI {
    private $db;
    private $productsTable = 'products';
    private $categoriesTable = 'categories';
    private $settingsTable = 'settings';
    private $ordersTable = 'orders';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        if ($this->db === null) {
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
    }

    // Get all categories
    public function getCategories() {
        try {
            $query = "SELECT * FROM " . $this->categoriesTable . " WHERE is_active = 1 ORDER BY category_name";
            $result = $this->db->query($query);

            if (!$result) {
                throw new Exception("Query failed: " . $this->db->error);
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

    // Get all products with category details
    public function getAllProducts() {
        try {
            $query = "SELECT p.*, c.category_name, c.img_url as category_image 
                      FROM " . $this->productsTable . " p 
                      LEFT JOIN " . $this->categoriesTable . " c ON p.category_id = c.category_id 
                      WHERE p.is_active = 1 
                      ORDER BY p.created_at DESC";

            $result = $this->db->query($query);

            if (!$result) {
                throw new Exception("Query failed: " . $this->db->error);
            }

            $products = [];
            while ($row = $result->fetch_assoc()) {
                // Convert tags from JSON to array
                $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
                $products[] = $row;
            }

            echo json_encode([
                'success' => true,
                'data' => $products,
                'message' => 'Products fetched successfully'
            ]);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Get products by category
    public function getProductsByCategory($categoryId) {
        try {
            $query = "SELECT p.*, c.category_name, c.img_url as category_image 
                      FROM " . $this->productsTable . " p 
                      LEFT JOIN " . $this->categoriesTable . " c ON p.category_id = c.category_id 
                      WHERE p.category_id = ? AND p.is_active = 1 
                      ORDER BY p.created_at DESC";

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("s", $categoryId);
            $stmt->execute();
            $result = $stmt->get_result();

            $products = [];
            while ($row = $result->fetch_assoc()) {
                $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
                $products[] = $row;
            }

            echo json_encode([
                'success' => true,
                'data' => $products,
                'message' => 'Products fetched successfully'
            ]);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Get search suggestions
    public function getSearchSuggestions() {
        try {
            $query = "SELECT name, tags FROM " . $this->productsTable . " WHERE is_active = 1";
            $result = $this->db->query($query);

            if (!$result) {
                throw new Exception("Query failed: " . $this->db->error);
            }

            $names = [];
            $tags = [];

            while ($row = $result->fetch_assoc()) {
                if ($row['name']) {
                    $names[] = $row['name'];
                }
                if ($row['tags']) {
                    $productTags = json_decode($row['tags'], true);
                    if (is_array($productTags)) {
                        $tags = array_merge($tags, $productTags);
                    }
                }
            }

            // Remove duplicates
            $names = array_unique($names);
            $tags = array_unique($tags);

            echo json_encode([
                'success' => true,
                'data' => [
                    'names' => array_values($names),
                    'tags' => array_values($tags)
                ],
                'message' => 'Search suggestions fetched successfully'
            ]);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Get business WhatsApp number
    public function getBusinessWhatsAppNumber() {
        try {
            $query = "SELECT setting_value FROM " . $this->settingsTable . " WHERE setting_key = 'BusinessWhatsAppNumber'";
            $result = $this->db->query($query);

            $whatsappNumber = '';
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $whatsappNumber = $row['setting_value'];
            }

            echo json_encode([
                'success' => true,
                'data' => $whatsappNumber,
                'message' => 'WhatsApp number fetched successfully'
            ]);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Create new order
    public function createOrder() {
        try {
            // Get raw POST data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            $required = ['order_details', 'customer_mobile_number'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                    return;
                }
            }

            // Generate order number
            $orderNumber = $this->generateOrderNumber();
            
            $orderId = 'order_' . time() . '_' . uniqid();
            $orderDetails = $data['order_details'];
            $customerMobileNumber = $data['customer_mobile_number'];
            $orderSource = $data['order_source'] ?? 'Web';
            $createdAt = date('Y-m-d H:i:s');

            $query = "INSERT INTO " . $this->ordersTable . " 
                      (order_id, order_number, order_details, customer_mobile_number, 
                       order_source, status, created_at, order_received_date_time) 
                      VALUES (?, ?, ?, ?, ?, 'New', ?, ?)";

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $stmt->bind_param("sssssss", 
                $orderId, $orderNumber, $orderDetails, $customerMobileNumber,
                $orderSource, $createdAt, $createdAt
            );

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Order created successfully', 
                    'order_number' => $orderNumber,
                    'order_id' => $orderId
                ]);
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Generate order number
    private function generateOrderNumber() {
        // Get the last order number
        $query = "SELECT order_number FROM " . $this->ordersTable . " ORDER BY created_at DESC LIMIT 1";
        $result = $this->db->query($query);
        
        $lastOrderNumber = '0000';
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $lastOrderNumber = $row['order_number'];
        }
        
        // Increment the order number
        $newNumber = str_pad((int)$lastOrderNumber + 1, 4, '0', STR_PAD_LEFT);
        return $newNumber;
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
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathSegments = explode('/', $path);
$endpoint = end($pathSegments);

// Only process if it's not an OPTIONS request
if ($method != 'OPTIONS') {
    $api = new ProductsFrontendAPI();
    
    switch ($method) {
        case 'GET':
            if (strpos($endpoint, 'categories') !== false) {
                $api->getCategories();
            } elseif (strpos($endpoint, 'suggestions') !== false) {
                $api->getSearchSuggestions();
            } elseif (strpos($endpoint, 'whatsapp') !== false) {
                $api->getBusinessWhatsAppNumber();
            } elseif (isset($_GET['category_id'])) {
                $api->getProductsByCategory($_GET['category_id']);
            } else {
                $api->getAllProducts();
            }
            break;
        case 'POST':
            if (strpos($endpoint, 'order') !== false) {
                $api->createOrder();
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
}
?>