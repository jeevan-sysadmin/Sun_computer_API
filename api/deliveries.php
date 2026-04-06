<?php
// Set headers for CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 3600");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database Configuration
class Database {
    private $host = "localhost";
    private $db_name = "sun_computers";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            return false;
        }

        return $this->conn;
    }
}

// Simple Auth class
class Auth {
    
    // Get bearer token from Authorization header
    public function getBearerToken() {
        $headers = null;
        
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    // Verify token - For development, accept any token. For production, implement proper validation
    public function verifyToken($token) {
        // For development purposes, accept all tokens
        // In production, implement proper JWT validation here
        return !empty($token);
    }
}

// Initialize database and auth
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        throw new Exception("Could not connect to database");
    }
    
    $auth = new Auth();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Initialization error',
        'error' => $e->getMessage()
    ]);
    exit();
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Verify token for all requests except OPTIONS
$token = $auth->getBearerToken();
if (!$token || !$auth->verifyToken($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid or missing token']);
    exit();
}

// Route the request
switch ($method) {
    case 'GET':
        getDeliveries($conn);
        break;
    case 'POST':
        createDelivery($conn);
        break;
    case 'PUT':
        updateDelivery($conn);
        break;
    case 'DELETE':
        deleteDelivery($conn);
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

/**
 * Get all deliveries
 */
function getDeliveries($conn) {
    try {
        // Check if fetching single delivery by ID
        if (isset($_GET['id']) && !empty($_GET['id'])) {
            $query = "SELECT d.*, 
                             o.order_code, 
                             c.full_name as client_name, 
                             c.phone as client_phone,
                             c.email as client_email,
                             c.address as client_address
                      FROM deliveries d 
                      LEFT JOIN service_orders o ON d.order_id = o.id 
                      LEFT JOIN clients c ON o.client_id = c.id
                      WHERE d.id = :id";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $_GET['id'], PDO::PARAM_INT);
            $stmt->execute();
            
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($delivery) {
                echo json_encode([
                    'success' => true,
                    'delivery' => $delivery
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Delivery not found'
                ]);
            }
        } else {
            // Get all deliveries with optional filters
            $whereClause = '';
            $params = [];
            
            // Filter by status if provided
            if (isset($_GET['status']) && !empty($_GET['status'])) {
                $whereClause = " WHERE d.status = :status";
                $params[':status'] = $_GET['status'];
            }
            
            // Filter by delivery person if provided
            if (isset($_GET['delivery_person']) && !empty($_GET['delivery_person'])) {
                $whereClause .= $whereClause ? " AND d.delivery_person = :delivery_person" : " WHERE d.delivery_person = :delivery_person";
                $params[':delivery_person'] = $_GET['delivery_person'];
            }
            
            // Filter by date range if provided
            if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
                $whereClause .= $whereClause ? " AND d.scheduled_date >= :start_date" : " WHERE d.scheduled_date >= :start_date";
                $params[':start_date'] = $_GET['start_date'];
            }
            
            if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                $whereClause .= $whereClause ? " AND d.scheduled_date <= :end_date" : " WHERE d.scheduled_date <= :end_date";
                $params[':end_date'] = $_GET['end_date'];
            }
            
            // Filter by delivery code
            if (isset($_GET['delivery_code']) && !empty($_GET['delivery_code'])) {
                $whereClause .= $whereClause ? " AND d.delivery_code LIKE :delivery_code" : " WHERE d.delivery_code LIKE :delivery_code";
                $params[':delivery_code'] = '%' . $_GET['delivery_code'] . '%';
            }
            
            // Filter by order code
            if (isset($_GET['order_code']) && !empty($_GET['order_code'])) {
                $whereClause .= $whereClause ? " AND o.order_code LIKE :order_code" : " WHERE o.order_code LIKE :order_code";
                $params[':order_code'] = '%' . $_GET['order_code'] . '%';
            }
            
            // Filter by client name
            if (isset($_GET['client_name']) && !empty($_GET['client_name'])) {
                $whereClause .= $whereClause ? " AND c.full_name LIKE :client_name" : " WHERE c.full_name LIKE :client_name";
                $params[':client_name'] = '%' . $_GET['client_name'] . '%';
            }
            
            $query = "SELECT d.*, 
                             o.order_code, 
                             c.full_name as client_name, 
                             c.phone as client_phone,
                             c.email as client_email,
                             c.address as client_address
                      FROM deliveries d 
                      LEFT JOIN service_orders o ON d.order_id = o.id 
                      LEFT JOIN clients c ON o.client_id = c.id
                      {$whereClause}
                      ORDER BY d.scheduled_date DESC, d.scheduled_time DESC";
            
            $stmt = $conn->prepare($query);
            
            // Bind parameters if any
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format dates for better readability
            foreach ($deliveries as &$delivery) {
                if (isset($delivery['scheduled_date'])) {
                    $delivery['scheduled_date_formatted'] = date('d/m/Y', strtotime($delivery['scheduled_date']));
                }
                if (isset($delivery['delivered_date'])) {
                    $delivery['delivered_date_formatted'] = date('d/m/Y H:i', strtotime($delivery['delivered_date']));
                }
                if (isset($delivery['created_at'])) {
                    $delivery['created_at_formatted'] = date('d/m/Y H:i', strtotime($delivery['created_at']));
                }
                if (isset($delivery['updated_at'])) {
                    $delivery['updated_at_formatted'] = date('d/m/Y H:i', strtotime($delivery['updated_at']));
                }
                
                // Format scheduled time
                if (isset($delivery['scheduled_time'])) {
                    $delivery['scheduled_time_formatted'] = date('h:i A', strtotime($delivery['scheduled_time']));
                }
                
                // Get product information if needed
                if (isset($delivery['order_id'])) {
                    $productQuery = "SELECT p.product_name, p.brand, p.model 
                                     FROM service_orders so 
                                     LEFT JOIN products p ON so.product_id = p.id 
                                     WHERE so.id = :order_id";
                    $productStmt = $conn->prepare($productQuery);
                    $productStmt->bindParam(':order_id', $delivery['order_id'], PDO::PARAM_INT);
                    $productStmt->execute();
                    $productInfo = $productStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($productInfo) {
                        $delivery['product_name'] = $productInfo['product_name'];
                        $delivery['product_brand'] = $productInfo['brand'];
                        $delivery['product_model'] = $productInfo['model'];
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'count' => count($deliveries),
                'deliveries' => $deliveries
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error',
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Create a new delivery
 */
function createDelivery($conn) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        // If no JSON data, check form data
        if (empty($data) && $_SERVER['CONTENT_TYPE'] === 'application/x-www-form-urlencoded') {
            $data = $_POST;
        }
        
        if (empty($data)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No data provided'
            ]);
            return;
        }
        
        // Validate required fields
        $required = ['order_id', 'delivery_type', 'address', 'contact_person', 'contact_phone', 'scheduled_date', 'scheduled_time'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Missing required field: {$field}"
                ]);
                return;
            }
        }
        
        // Check if order exists
        $checkOrderQuery = "SELECT id FROM service_orders WHERE id = :order_id";
        $checkOrderStmt = $conn->prepare($checkOrderQuery);
        $checkOrderStmt->bindParam(':order_id', $data['order_id'], PDO::PARAM_INT);
        $checkOrderStmt->execute();
        
        if ($checkOrderStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Order not found'
            ]);
            return;
        }
        
        // Generate delivery code
        $delivery_code = 'DEL' . date('Ymd') . strtoupper(substr(uniqid(), -6));
        
        $query = "INSERT INTO deliveries (
            order_id, delivery_code, delivery_type, address,
            contact_person, contact_phone, scheduled_date,
            scheduled_time, delivery_person, status, notes
        ) VALUES (
            :order_id, :delivery_code, :delivery_type, :address,
            :contact_person, :contact_phone, :scheduled_date,
            :scheduled_time, :delivery_person, :status, :notes
        )";
        
        $stmt = $conn->prepare($query);
        
        // Set default status if not provided (use 'scheduled' to match your database enum)
        $status = isset($data['status']) ? $data['status'] : 'scheduled';
        
        $stmt->bindParam(':order_id', $data['order_id'], PDO::PARAM_INT);
        $stmt->bindParam(':delivery_code', $delivery_code);
        $stmt->bindParam(':delivery_type', $data['delivery_type']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':contact_person', $data['contact_person']);
        $stmt->bindParam(':contact_phone', $data['contact_phone']);
        $stmt->bindParam(':scheduled_date', $data['scheduled_date']);
        $stmt->bindParam(':scheduled_time', $data['scheduled_time']);
        $stmt->bindParam(':delivery_person', $data['delivery_person'] ?? null);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':notes', $data['notes'] ?? null);
        
        if ($stmt->execute()) {
            $delivery_id = $conn->lastInsertId();
            
            // Fetch the created delivery with client info
            $fetchQuery = "SELECT d.*, 
                                  o.order_code, 
                                  c.full_name as client_name, 
                                  c.phone as client_phone
                           FROM deliveries d 
                           LEFT JOIN service_orders o ON d.order_id = o.id 
                           LEFT JOIN clients c ON o.client_id = c.id
                           WHERE d.id = :id";
            $fetchStmt = $conn->prepare($fetchQuery);
            $fetchStmt->bindParam(':id', $delivery_id, PDO::PARAM_INT);
            $fetchStmt->execute();
            $delivery = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Delivery scheduled successfully',
                'delivery_id' => $delivery_id,
                'delivery_code' => $delivery_code,
                'delivery' => $delivery
            ]);
        } else {
            throw new Exception('Failed to execute query');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to schedule delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Update an existing delivery
 */
function updateDelivery($conn) {
    try {
        // Get delivery ID from URL parameter or request body
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            $data = json_decode(file_get_contents("php://input"), true);
            $id = isset($data['id']) ? $data['id'] : null;
        }
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
            return;
        }
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        // If no JSON data, check form data
        if (empty($data) && $_SERVER['CONTENT_TYPE'] === 'application/x-www-form-urlencoded') {
            $data = $_POST;
        }
        
        // Check if delivery exists
        $checkQuery = "SELECT id, status FROM deliveries WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Delivery not found']);
            return;
        }
        
        $currentDelivery = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Build update query dynamically
        $fields = [];
        $params = [':id' => $id];
        
        // Define allowed fields to update
        $allowed_fields = [
            'order_id', 'delivery_type', 'address', 'contact_person', 
            'contact_phone', 'scheduled_date', 'scheduled_time', 
            'delivery_person', 'status', 'notes'
        ];
        
        // If marking as delivered, add delivered_date (timestamp)
        if (isset($data['status']) && $data['status'] === 'delivered' && $currentDelivery['status'] !== 'delivered') {
            $allowed_fields[] = 'delivered_date';
            $data['delivered_date'] = date('Y-m-d H:i:s');
        }
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            return;
        }
        
        $query = "UPDATE deliveries SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $conn->prepare($query);
        
        // Bind parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            // Fetch updated delivery with client info
            $fetchQuery = "SELECT d.*, 
                                  o.order_code, 
                                  c.full_name as client_name, 
                                  c.phone as client_phone
                           FROM deliveries d 
                           LEFT JOIN service_orders o ON d.order_id = o.id 
                           LEFT JOIN clients c ON o.client_id = c.id
                           WHERE d.id = :id";
            $fetchStmt = $conn->prepare($fetchQuery);
            $fetchStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $fetchStmt->execute();
            $delivery = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Delivery updated successfully',
                'delivery' => $delivery
            ]);
        } else {
            throw new Exception('Failed to execute update');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Delete a delivery
 */
function deleteDelivery($conn) {
    try {
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
            return;
        }
        
        // Check if delivery exists
        $checkQuery = "SELECT id FROM deliveries WHERE id = :id";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Delivery not found']);
            return;
        }
        
        // Delete delivery
        $query = "DELETE FROM deliveries WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Delivery deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete delivery');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete delivery',
            'error' => $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
?>