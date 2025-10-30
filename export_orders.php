<?php
// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("Access-Control-Allow-Origin: http://localhost:3000");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: true");
    header("Content-Length: 0");
    header("Content-Type: text/plain");
    http_response_code(200);
    exit();
}

// Set CORS headers for actual requests
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

require_once 'config/database.php';

class ExportOrdersAPI {
    private $db;
    private $table = 'orders';

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        
        if ($this->db === null) {
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
    }

    public function exportOrders() {
        try {
            $filter = isset($_GET['filter']) ? $_GET['filter'] : 'All';
            
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

            error_log("Export Query: " . $query); // Debug log
            
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

            error_log("Orders count: " . count($orders)); // Debug log

            if (empty($orders)) {
                // If no orders, send empty response but still create file
                $this->generateExcelFile([], $filter);
                return;
            }

            // Generate Excel file
            $this->generateExcelFile($orders, $filter);
            
        } catch (Exception $e) {
            error_log("Export error: " . $e->getMessage()); // Debug log
            $this->sendErrorResponse($e->getMessage());
        }
    }

    private function generateExcelFile($orders, $filter) {
        try {
            // Check if PhpSpreadsheet is available
            if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                $this->generateExcelWithPhpSpreadsheet($orders, $filter);
            } else {
                // Fallback to simple CSV
                $this->generateCSVFile($orders, $filter);
            }
        } catch (Exception $e) {
            // Fallback to CSV if Excel generation fails
            error_log("Excel generation failed, falling back to CSV: " . $e->getMessage());
            $this->generateCSVFile($orders, $filter);
        }
    }

    private function generateExcelWithPhpSpreadsheet($orders, $filter) {
        // Create data array for Excel
        $excelData = [];
        
        // Add headers
        $excelData[] = [
            'Order ID',
            'Order Number',
            'Status',
            'Customer Mobile',
            'Order Details',
            'Order Source',
            'Created At',
            'Remarks',
            'Order Received Date'
        ];
        
        // Add order data
        foreach ($orders as $order) {
            $excelData[] = [
                $order['order_id'] ?? 'N/A',
                $order['order_number'] ?? 'N/A',
                $order['status'] ?? 'N/A',
                $order['customer_mobile_number'] ?? 'N/A',
                $order['order_details'] ?? 'N/A',
                $order['order_source'] ?? 'N/A',
                $order['created_at'] ?? 'N/A',
                $order['remarks'] ?? 'N/A',
                $order['order_received_date_time'] ?? 'N/A'
            ];
        }
        
        // Create spreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set title
        $sheet->setTitle('Orders');
        
        // Add data to sheet
        $sheet->fromArray($excelData, NULL, 'A1');
        
        // Auto-size columns
        foreach (range('A', 'I') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }
        
        // Style the header row
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ]
        ];
        
        $sheet->getStyle('A1:I1')->applyFromArray($headerStyle);
        
        // Set headers for Excel download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="orders_' . $filter . '_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        // Create writer and output
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        
    } catch (Exception $e) {
        throw new Exception("PhpSpreadsheet error: " . $e->getMessage());
    }

    private function generateCSVFile($orders, $filter) {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders_' . $filter . '_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: max-age=0');
        
        // Output CSV
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fputs($output, "\xEF\xBB\xBF");
        
        // Add headers
        fputcsv($output, [
            'Order ID',
            'Order Number',
            'Status',
            'Customer Mobile',
            'Order Details',
            'Order Source',
            'Created At',
            'Remarks',
            'Order Received Date'
        ]);
        
        // Add order data
        foreach ($orders as $order) {
            fputcsv($output, [
                $order['order_id'] ?? 'N/A',
                $order['order_number'] ?? 'N/A',
                $order['status'] ?? 'N/A',
                $order['customer_mobile_number'] ?? 'N/A',
                $order['order_details'] ?? 'N/A',
                $order['order_source'] ?? 'N/A',
                $order['created_at'] ?? 'N/A',
                $order['remarks'] ?? 'N/A',
                $order['order_received_date_time'] ?? 'N/A'
            ]);
        }
        
        fclose($output);
        exit;
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
    $api = new ExportOrdersAPI();
    
    if ($method == 'GET') {
        $api->exportOrders();
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
}
?>