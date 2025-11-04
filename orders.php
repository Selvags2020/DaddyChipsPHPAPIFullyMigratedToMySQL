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
    
    AppConfig::debug("OPTIONS preflight request handled for orders - origin: " . $origin);
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
header('Content-Type: application/json');

require_once 'config/database.php';

class OrdersAPI {
    private $db;
    private $table = 'orders';
    private $orderDetailsTable = 'order_details';
    private $counterTable = 'order_counter';

    public function __construct() {
        AppConfig::debug("OrdersAPI constructor called");
        
        $database = new Database();
        $this->db = $database->getConnection();
        
        if ($this->db === null) {
            AppConfig::error("Database connection failed in OrdersAPI constructor");
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
        
        AppConfig::debug("Database connection established successfully");
    }

    // Generate order number using MySQL order_counter table
    public function generateOrderNumber() {
        AppConfig::debug("generateOrderNumber method called");
        
        try {
            // Start transaction to ensure atomic operation
            $this->db->begin_transaction();
            
            // Lock the counter row for update
            $lockQuery = "SELECT last_order_number FROM " . $this->counterTable . " WHERE id = 1 FOR UPDATE";
            $lockStmt = $this->db->prepare($lockQuery);
            
            if (!$lockStmt) {
                AppConfig::error("Prepare failed for generateOrderNumber: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $lockStmt->execute();
            $result = $lockStmt->get_result();
            
            $currentCount = 0;
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $currentCount = $row['last_order_number'];
                AppConfig::debug("Current order counter: " . $currentCount);
            } else {
                // Initialize counter if it doesn't exist
                $initQuery = "INSERT INTO " . $this->counterTable . " (id, last_order_number) VALUES (1, 0)";
                $initStmt = $this->db->prepare($initQuery);
                if (!$initStmt || !$initStmt->execute()) {
                    AppConfig::error("Failed to initialize order counter");
                    throw new Exception("Failed to initialize counter");
                }
                AppConfig::debug("Order counter initialized");
            }
            
            // Increment the counter
            $newOrderNumber = $currentCount + 1;
            
            // Update the counter
            $updateQuery = "UPDATE " . $this->counterTable . " SET last_order_number = ? WHERE id = 1";
            $updateStmt = $this->db->prepare($updateQuery);
            
            if (!$updateStmt) {
                AppConfig::error("Prepare failed for counter update: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }
            
            $updateStmt->bind_param("i", $newOrderNumber);
            
            if (!$updateStmt->execute()) {
                AppConfig::error("Execute failed for counter update: " . $updateStmt->error);
                throw new Exception("Failed to update counter: " . $updateStmt->error);
            }
            
            // Commit transaction
            $this->db->commit();
            
            $formattedOrderNumber = str_pad($newOrderNumber, 4, '0', STR_PAD_LEFT);
            AppConfig::debug("Order number generated successfully: " . $formattedOrderNumber);
            
            echo json_encode([
                'success' => true,
                'order_number' => $formattedOrderNumber,
                'message' => 'Order number generated successfully'
            ]);
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->db->rollback();
            AppConfig::error("Exception in generateOrderNumber: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function getOrders() {
        AppConfig::debug("getOrders method called");
        
        try {
            $filter = isset($_GET['filter']) ? $_GET['filter'] : 'New';
            
            AppConfig::debug("Fetching orders with filter: " . $filter);
            
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

            AppConfig::debug("Executing query: " . $query);

            $stmt = $this->db->prepare($query);
            if (!$stmt) {
                AppConfig::error("Prepare failed for getOrders: " . $this->db->error);
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

            AppConfig::debug("Fetched " . count($orders) . " orders successfully");
            
            echo json_encode([
                'success' => true,
                'data' => $orders,
                'counts' => $counts,
                'message' => 'Orders fetched successfully'
            ]);
            
        } catch (Exception $e) {
            AppConfig::error("Exception in getOrders: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    private function getOrderCounts() {
        AppConfig::debug("getOrderCounts method called");
        
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

            $counts = [
                'total' => (int)$totalCount,
                'new' => (int)$newCount,
                'delivered' => (int)$deliveredCount
            ];

            AppConfig::debug("Order counts calculated: " . json_encode($counts));
            
            return $counts;

        } catch (Exception $e) {
            AppConfig::error("Exception in getOrderCounts: " . $e->getMessage());
            return ['total' => 0, 'new' => 0, 'delivered' => 0];
        }
    }

    public function markDelivered($id) {
        AppConfig::debug("markDelivered method called for order ID: " . $id);
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $remarks = $input['remarks'] ?? '';
            $created_by = $input['created_by'] ?? 'Admin';

            AppConfig::debug("Marking order as delivered - Remarks: " . $remarks . ", Created by: " . $created_by);

            if (empty($remarks)) {
                AppConfig::warn("Mark delivered failed: Remarks are required for order ID: " . $id);
                throw new Exception("Remarks are required");
            }

            $query = "UPDATE " . $this->table . " SET status = 'Delivered', remarks = ?, order_received_date_time = NOW() WHERE order_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                AppConfig::error("Prepare failed for markDelivered: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("si", $remarks, $id);
            
            if ($stmt->execute()) {
                AppConfig::log("Order marked as delivered successfully - Order ID: " . $id . " by " . $created_by);
                echo json_encode([
                    'success' => true,
                    'message' => 'Order marked as delivered successfully'
                ]);
            } else {
                AppConfig::error("Execute failed for markDelivered: " . $stmt->error);
                throw new Exception("Failed to update order: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            AppConfig::error("Exception in markDelivered: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function updateOrder($id) {
        AppConfig::debug("updateOrder method called for order ID: " . $id);
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $customer_mobile_number = $input['customer_mobile_number'] ?? '';
            $order_details = $input['order_details'] ?? '';
            $order_source = $input['order_source'] ?? '';

            AppConfig::debug("Updating order - Mobile: " . $customer_mobile_number . ", Source: " . $order_source);

            $query = "UPDATE " . $this->table . " SET customer_mobile_number = ?, order_details = ?, order_source = ? WHERE order_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                AppConfig::error("Prepare failed for updateOrder: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("sssi", $customer_mobile_number, $order_details, $order_source, $id);
            
            if ($stmt->execute()) {
                AppConfig::log("Order updated successfully - Order ID: " . $id);
                echo json_encode([
                    'success' => true,
                    'message' => 'Order updated successfully'
                ]);
            } else {
                AppConfig::error("Execute failed for updateOrder: " . $stmt->error);
                throw new Exception("Failed to update order: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            AppConfig::error("Exception in updateOrder: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function createOrder() {
        AppConfig::debug("createOrder method called");
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            AppConfig::debug("Received order data: " . json_encode($input));
            
            // Validate required fields
            if (empty($input['order_number'])) {
                AppConfig::warn("Order creation failed: Order number is required");
                throw new Exception("Order number is required");
            }
            
            if (empty($input['order_details'])) {
                AppConfig::warn("Order creation failed: Order details are required");
                throw new Exception("Order details are required");
            }
            
            if (empty($input['customer_mobile_number'])) {
                AppConfig::warn("Order creation failed: Customer mobile number is required");
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
            $cart_items = $input['cart_items'] ?? []; // New field for cart items

            AppConfig::debug("Processing order - Number: " . $order_number . ", Mobile: " . $customer_mobile_number . ", Items: " . count($cart_items));

            // Check if order number already exists
            $checkQuery = "SELECT order_id FROM " . $this->table . " WHERE order_number = ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->bind_param("s", $order_number);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                AppConfig::warn("Order creation failed: Order number already exists - " . $order_number);
                throw new Exception("Order number already exists");
            }

            // Start transaction for both orders and order_details
            $this->db->begin_transaction();

            try {
                // Insert into orders table
                $query = "INSERT INTO " . $this->table . " 
                         (order_number, order_details, status, remarks, customer_mobile_number, order_source, created_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->db->prepare($query);
                
                if (!$stmt) {
                    AppConfig::error("Prepare failed for createOrder: " . $this->db->error);
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
                
                if (!$stmt->execute()) {
                    AppConfig::error("Execute failed for createOrder: " . $stmt->error);
                    throw new Exception("Failed to create order: " . $stmt->error);
                }

                // Get the auto-generated order_id
                $order_id = $stmt->insert_id;
                
                // Insert into order_details table if cart items are provided
                if (!empty($cart_items) && is_array($cart_items)) {
                    $detailQuery = "INSERT INTO " . $this->orderDetailsTable . " 
                                   (order_number, category_id, category_name, product_id, product_name, 
                                    quantity, unit_price, total_price, product_image) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $detailStmt = $this->db->prepare($detailQuery);
                    if (!$detailStmt) {
                        AppConfig::error("Prepare failed for order details: " . $this->db->error);
                        throw new Exception("Order details prepare failed: " . $this->db->error);
                    }

                    $itemsInserted = 0;
                    foreach ($cart_items as $item) {
                        $unit_price = $item['offer_price'] || $item['standard_price'];
                        $total_price = $unit_price * $item['quantity'];
                        
                        $detailStmt->bind_param("ssssssdds",
                            $order_number,
                            $item['category_id'],
                            $item['category_name'] ?? '',
                            $item['id'] ?? $item['product_id'],
                            $item['name'],
                            $item['quantity'],
                            $unit_price,
                            $total_price,
                            $item['product_image'] ?? ''
                        );

                        if ($detailStmt->execute()) {
                            $itemsInserted++;
                        } else {
                            AppConfig::error("Failed to insert order detail: " . $detailStmt->error);
                        }
                    }

                    AppConfig::debug("Inserted " . $itemsInserted . " order details for order: " . $order_number);
                    $detailStmt->close();
                }

                // Commit transaction
                $this->db->commit();

                AppConfig::log("Order created successfully: " . $order_number . " with " . count($cart_items) . " items by " . $created_by);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Order created successfully',
                    'order_id' => $order_id,
                    'order_number' => $order_number,
                    'items_count' => count($cart_items),
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

            } catch (Exception $e) {
                // Rollback transaction on error
                $this->db->rollback();
                AppConfig::error("Transaction failed in createOrder: " . $e->getMessage());
                throw $e;
            }
            
        } catch (Exception $e) {
            AppConfig::error("Exception in createOrder: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function getOrder($id) {
        AppConfig::debug("getOrder method called for order ID: " . $id);
        
        try {
            if (empty($id)) {
                AppConfig::warn("getOrder failed: Order ID is required");
                throw new Exception("Order ID is required");
            }

            $query = "SELECT * FROM " . $this->table . " WHERE order_id = ?";
            $stmt = $this->db->prepare($query);
            
            if (!$stmt) {
                AppConfig::error("Prepare failed for getOrder: " . $this->db->error);
                throw new Exception("Prepare failed: " . $this->db->error);
            }

            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                AppConfig::warn("Order not found - Order ID: " . $id);
                throw new Exception("Order not found");
            }

            $order = $result->fetch_assoc();

            // Get order details
            $detailsQuery = "SELECT * FROM " . $this->orderDetailsTable . " WHERE order_number = ?";
            $detailsStmt = $this->db->prepare($detailsQuery);
            $detailsStmt->bind_param("s", $order['order_number']);
            $detailsStmt->execute();
            $detailsResult = $detailsStmt->get_result();

            $order_details = [];
            while ($row = $detailsResult->fetch_assoc()) {
                $order_details[] = $row;
            }

            $order['order_items'] = $order_details;

            AppConfig::debug("Order fetched successfully - Order ID: " . $id . " with " . count($order_details) . " items");
            
            echo json_encode([
                'success' => true,
                'data' => $order,
                'message' => 'Order fetched successfully'
            ]);
            
        } catch (Exception $e) {
            AppConfig::error("Exception in getOrder: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function deleteOrder($id) {
        AppConfig::debug("deleteOrder method called for order ID: " . $id);
        
        try {
            if (empty($id)) {
                AppConfig::warn("deleteOrder failed: Order ID is required");
                throw new Exception("Order ID is required");
            }

            // First, check if the order exists
            $checkQuery = "SELECT order_id, order_number FROM " . $this->table . " WHERE order_id = ?";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->bind_param("i", $id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows === 0) {
                AppConfig::warn("Order not found for deletion - Order ID: " . $id);
                throw new Exception("Order not found");
            }

            $order = $checkResult->fetch_assoc();
            $order_number = $order['order_number'];

            // Start transaction
            $this->db->begin_transaction();

            try {
                // Delete from order_details first
                $deleteDetailsQuery = "DELETE FROM " . $this->orderDetailsTable . " WHERE order_number = ?";
                $deleteDetailsStmt = $this->db->prepare($deleteDetailsQuery);
                $deleteDetailsStmt->bind_param("s", $order_number);
                $deleteDetailsStmt->execute();

                AppConfig::debug("Deleted order details for order: " . $order_number);

                // Delete from orders table
                $deleteOrderQuery = "DELETE FROM " . $this->table . " WHERE order_id = ?";
                $deleteOrderStmt = $this->db->prepare($deleteOrderQuery);
                $deleteOrderStmt->bind_param("i", $id);
                
                if ($deleteOrderStmt->execute()) {
                    if ($deleteOrderStmt->affected_rows > 0) {
                        $this->db->commit();
                        AppConfig::log("Order deleted successfully - Order ID: " . $id . ", Order Number: " . $order_number);
                        echo json_encode([
                            'success' => true,
                            'message' => 'Order deleted successfully',
                            'deleted_id' => $id
                        ]);
                    } else {
                        AppConfig::warn("No order was deleted - Order ID: " . $id);
                        throw new Exception("No order was deleted");
                    }
                } else {
                    AppConfig::error("Execute failed for deleteOrder: " . $deleteOrderStmt->error);
                    throw new Exception("Failed to delete order: " . $deleteOrderStmt->error);
                }
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $this->db->rollback();
                AppConfig::error("Transaction failed in deleteOrder: " . $e->getMessage());
                throw $e;
            }
            
        } catch (Exception $e) {
            AppConfig::error("Exception in deleteOrder: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    private function sendErrorResponse($message) {
        AppConfig::error("Sending error response: " . $message);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
}

// Handle requests with logging
AppConfig::debug("=== ORDERS API REQUEST STARTED ===");
AppConfig::debug("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown'));
AppConfig::debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown'));
AppConfig::debug("Query String: " . ($_SERVER['QUERY_STRING'] ?? 'None'));

$method = $_SERVER['REQUEST_METHOD'];

if ($method != 'OPTIONS') {
    $api = new OrdersAPI();
    
    // Check for generate-order-number endpoint
    $request_uri = $_SERVER['REQUEST_URI'];
    if (strpos($request_uri, 'generate-order-number') !== false) {
        AppConfig::debug("Routing to generate-order-number endpoint");
        $api->generateOrderNumber();
        exit;
    }
    
    AppConfig::debug("Routing main request - Method: " . $method);
    
    try {
        switch ($method) {
            case 'POST':
                AppConfig::debug("Routing to createOrder endpoint");
                $api->createOrder();
                break;
            case 'GET':
                if (isset($_GET['id'])) {
                    AppConfig::debug("Routing to getOrder endpoint for ID: " . $_GET['id']);
                    $api->getOrder($_GET['id']);
                } else {
                    AppConfig::debug("Routing to getOrders endpoint");
                    $api->getOrders();
                }
                break;
            case 'PUT':
                $id = $_GET['id'] ?? '';
                $action = $_GET['action'] ?? '';
                
                if ($id) {
                    if ($action === 'deliver') {
                        AppConfig::debug("Routing to markDelivered endpoint for ID: " . $id);
                        $api->markDelivered($id);
                    } else {
                        AppConfig::debug("Routing to updateOrder endpoint for ID: " . $id);
                        $api->updateOrder($id);
                    }
                } else {
                    AppConfig::warn("PUT request missing order ID");
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Order ID required']);
                }
                break;
            case 'DELETE':
                $id = $_GET['id'] ?? '';
                if ($id) {
                    AppConfig::debug("Routing to deleteOrder endpoint for ID: " . $id);
                    $api->deleteOrder($id);
                } else {
                    AppConfig::warn("DELETE request missing order ID");
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Order ID required']);
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

AppConfig::debug("=== ORDERS API REQUEST COMPLETED ===");
?>