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
header('Content-Type: application/json');

require_once 'config/database.php';

class OrdersAPI {
    private $db;
    private $table = 'orders';
    private $counterTable = 'order_counter';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        if ($this->db === null) {
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
    }

    // Generate order number using MySQL order_counter table
    public function generateOrderNumber() {
        try {
            // Start transaction to ensure atomic operation
            $this->db->begin_transaction();
            
            // Lock the counter row for update
            $lockQuery = "SELECT last_order_number FROM " . $this->counterTable . " WHERE id = 1 FOR UPDATE";
            $lockStmt = $this->db->prepare($lockQuery);
            
            if (!$lockStmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $lockStmt->execute();
            $result = $lockStmt->get_result();
            
            $currentCount = 0;
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $currentCount = $row['last_order_number'];
            } else {
                // Initialize counter if it doesn't exist
                $initQuery = "INSERT INTO " . $this->counterTable . " (id, last_order_number) VALUES (1, 0)";
                $initStmt = $this->db->prepare($initQuery);
                if (!$initStmt || !$initStmt->execute()) {
                    throw new Exception("Failed to initialize counter");
                }
            }
            
            // Increment the counter
            $newOrderNumber = $currentCount + 1;
            
            // Update the counter
            $updateQuery = "UPDATE " . $this->counterTable . " SET last_order_number = ? WHERE id = 1";
            $updateStmt = $this->db->prepare($updateQuery);
            
            if (!$updateStmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $updateStmt->bind_param("i", $newOrderNumber);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update counter: " . $updateStmt->error);
            }
            
            // Commit transaction
            $this->db->commit();
            
            // Return formatted order number
            echo json_encode([
                'success' => true,
                'order_number' => str_pad($newOrderNumber, 4, '0', STR_PAD_LEFT),
                'message' => 'Order number generated successfully'
            ]);
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->db->rollback();
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function getOrders() {
        try {
            $filter = isset($_GET['filter']) ? $_GET['filter'] : 'New';
            
            // Build query based on filter
            $query = "SELECT * FROM " . $this->table . " WHERE 1=1";
            $params = [];
            
            if ($filter === 'New') {
                $query .= " AND status = 'New'";
            } elseif ($filter === 'Delivered') {
                $twoDaysAgo = date('Y-m-d H:i:s', strtotime('-2 days'));
                $query .= " AND status = 'Delivered' AND order_received_date_time >= ?";
                $params = [$twoDaysAgo];
            }
            
            $query .= " ORDER BY created_at DESC";

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            if (!empty($params)) {
                $stmt->bind_param("s", ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();

            $orders = [];
            while ($row = $result->fetch_assoc()) {
                $orders[] = $row;
            }

            // Get counts for different statuses
            $counts = $this->getOrderCounts();

            echo json_encode([
                'success' => true,
                'data' => $orders,
                'counts' => $counts,
                'message' => 'Orders fetched successfully'
            ]);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    private function getOrderCounts() {
        try {
            // Total new orders
            $newQuery = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE status = 'New'";
            $newStmt = $this->db->prepare($newQuery);
            $newStmt->execute();
            $newResult = $newStmt->get_result();
            $newCount = $newResult->fetch_assoc()['count'];

            // Total delivered orders (last 2 days)
            $deliveredQuery = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE status = 'Delivered' AND order_received_date_time >= ?";
            $twoDaysAgo = date('Y-m-d H:i:s', strtotime('-2 days'));
            $deliveredStmt = $this->db->prepare($deliveredQuery);
            $deliveredStmt->bind_param("s", $twoDaysAgo);
            $deliveredStmt->execute();
            $deliveredResult = $deliveredStmt->get_result();
            $deliveredCount = $deliveredResult->fetch_assoc()['count'];

            // Total all orders (new + last 2 days delivered)
            $totalQuery = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE status = 'New' OR (status = 'Delivered' AND order_received_date_time >= ?)";
            $totalStmt = $this->db->prepare($totalQuery);
            $totalStmt->bind_param("s", $twoDaysAgo);
            $totalStmt->execute();
            $totalResult = $totalStmt->get_result();
            $totalCount = $totalResult->fetch_assoc()['count'];

            return [
                'total' => (int)$totalCount,
                'new' => (int)$newCount,
                'delivered' => (int)$deliveredCount
            ];

        } catch (Exception $e) {
            return ['total' => 0, 'new' => 0, 'delivered' => 0];
        }
    }

    public function markDelivered($id) {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $remarks = $input['remarks'] ?? '';
            $created_by = $input['created_by'] ?? 'Admin';

            if (empty($remarks)) {
                throw new Exception("Remarks are required");
            }

            $query = "UPDATE " . $this->table . " SET status = 'Delivered', remarks = ?, order_received_date_time = NOW() WHERE order_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("si", $remarks, $id);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Order marked as delivered successfully'
                ]);
            } else {
                throw new Exception("Failed to update order: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function updateOrder($id) {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $customer_mobile_number = $input['customer_mobile_number'] ?? '';
            $order_details = $input['order_details'] ?? '';
            $order_source = $input['order_source'] ?? '';

            $query = "UPDATE " . $this->table . " SET customer_mobile_number = ?, order_details = ?, order_source = ? WHERE order_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("sssi", $customer_mobile_number, $order_details, $order_source, $id);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Order updated successfully'
                ]);
            } else {
                throw new Exception("Failed to update order: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function createOrder() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            if (empty($input['order_number'])) {
                throw new Exception("Order number is required");
            }
            
            if (empty($input['order_details'])) {
                throw new Exception("Order details are required");
            }
            
            if (empty($input['customer_mobile_number'])) {
                throw new Exception("Customer mobile number is required");
            }

            // Extract data from input
            $order_number = $input['order_number'];
            $order_details = $input['order_details'];
            $status = $input['status'] ?? 'New';
            $remarks = $input['remarks'] ?? '';
            $customer_mobile_number = $input['customer_mobile_number'];
            $order_source = $input['order_source'] ?? 'Web';
            $created_by = $input['created_by'] ?? 'customer';

            // Check if order number already exists
            $checkQuery = "SELECT order_id FROM " . $this->table . " WHERE order_number = ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->bind_param("s", $order_number);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                throw new Exception("Order number already exists");
            }

            // Prepare insert query (order_id is auto-increment, so we don't include it)
            $query = "INSERT INTO " . $this->table . " 
                     (order_number, order_details, status, remarks, customer_mobile_number, order_source, created_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("sssssss", 
                $order_number, 
                $order_details, 
                $status, 
                $remarks, 
                $customer_mobile_number, 
                $order_source, 
                $created_by
            );
            
            if ($stmt->execute()) {
                // Get the auto-generated order_id
                $order_id = $stmt->insert_id;
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Order created successfully',
                    'order_id' => $order_id,
                    'data' => [
                        'order_id' => $order_id,
                        'order_number' => $order_number,
                        'order_details' => $order_details,
                        'status' => $status,
                        'customer_mobile_number' => $customer_mobile_number,
                        'order_source' => $order_source,
                        'created_by' => $created_by,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ]);
            } else {
                throw new Exception("Failed to create order: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function getOrder($id) {
        try {
            if (empty($id)) {
                throw new Exception("Order ID is required");
            }

            $query = "SELECT * FROM " . $this->table . " WHERE order_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                throw new Exception("Order not found");
            }

            $order = $result->fetch_assoc();

            echo json_encode([
                'success' => true,
                'data' => $order,
                'message' => 'Order fetched successfully'
            ]);
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function deleteOrder($id) {
        try {
            if (empty($id)) {
                throw new Exception("Order ID is required");
            }

            // First, check if the order exists
            $checkQuery = "SELECT order_id FROM " . $this->table . " WHERE order_id = ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->bind_param("i", $id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows === 0) {
                throw new Exception("Order not found");
            }

            // Prepare delete query
            $query = "DELETE FROM " . $this->table . " WHERE order_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Order deleted successfully',
                        'deleted_id' => $id
                    ]);
                } else {
                    throw new Exception("No order was deleted");
                }
            } else {
                throw new Exception("Failed to delete order: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }
    }

    private function sendErrorResponse($message) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
}

// Handle requests
$method = $_SERVER['REQUEST_METHOD'];

if ($method != 'OPTIONS') {
    $api = new OrdersAPI();
    
    // Check for generate-order-number endpoint
    $request_uri = $_SERVER['REQUEST_URI'];
    if (strpos($request_uri, 'generate-order-number') !== false) {
        $api->generateOrderNumber();
        exit;
    }
    
    switch ($method) {
        case 'POST':
            $api->createOrder();
            break;
        case 'GET':
            if (isset($_GET['id'])) {
                $api->getOrder($_GET['id']);
            } else {
                $api->getOrders();
            }
            break;
        case 'PUT':
            $id = $_GET['id'] ?? '';
            $action = $_GET['action'] ?? '';
            
            if ($id) {
                if ($action === 'deliver') {
                    $api->markDelivered($id);
                } else {
                    $api->updateOrder($id);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Order ID required']);
            }
            break;
        case 'DELETE':
            $id = $_GET['id'] ?? '';
            if ($id) {
                $api->deleteOrder($id);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Order ID required']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
}
?>