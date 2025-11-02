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
    
    header("Access-Control-Allow-Methods: POST, OPTIONS");
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

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/auth/token_generator.php';

class AuthAPI {
    private $db;
    private $table = 'users';
    private $tokenGenerator;

    public function __construct() {
        AppConfig::debug("AuthAPI constructor called");
        
        $database = new Database();
        $this->db = $database->getConnection();
        
        if ($this->db === null) {
            AppConfig::error("Database connection failed in AuthAPI constructor");
            $this->sendErrorResponse('Database connection failed');
            exit;
        }
        
        // Initialize TokenGenerator with appconfig secret key
        $jwtConfig = AppConfig::getJWTConfig();
        $this->tokenGenerator = new TokenGenerator($jwtConfig['secret']);
        
        AppConfig::debug("Database connection established successfully");
        AppConfig::debug("TokenGenerator initialized with secret key: " . (empty($jwtConfig['secret']) ? 'EMPTY' : 'SET'));
    }

    public function login() {
        AppConfig::debug("Login method called");
        
        try {
            // Get raw POST data
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                AppConfig::error("Invalid JSON data in login: " . json_last_error_msg());
                throw new Exception('Invalid JSON data');
            }

            AppConfig::debug("Login attempt with email: " . ($data['email'] ?? 'not provided'));

            // Validate required fields
            if (empty($data['email']) || empty($data['password'])) {
                AppConfig::warn("Login failed: Email or password missing");
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Email and password are required']);
                return;
            }

            $email = trim($data['email']);
            $password = $data['password'];

            // Check if user exists
            $user = $this->getUserByEmail($email);
            
            if (!$user) {
                AppConfig::warn("Login failed: User not found - " . $email);
                $this->logLoginAttempt($email, false);
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
                return;
            }

            AppConfig::debug("User found: " . $email . " (ID: " . $user['id'] . ", Role: " . $user['role'] . ")");

            // Check if account is locked
            if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                AppConfig::warn("Login failed: Account locked - " . $email);
                http_response_code(423);
                echo json_encode(['success' => false, 'message' => 'Account temporarily locked. Try again later.']);
                return;
            }

            // Check if account is active
            if (!$user['is_active']) {
                AppConfig::warn("Login failed: Account inactive - " . $email);
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact support.']);
                return;
            }

            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                AppConfig::warn("Login failed: Invalid password - " . $email);
                $this->handleFailedLogin($user);
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
                return;
            }

            // Successful login - generate token using TokenGenerator
            AppConfig::debug("Password verified, generating token for: " . $email);
            $token = $this->tokenGenerator->generateToken($user);
            $this->handleSuccessfulLogin($user);

            AppConfig::log("Login successful: " . $email . " (ID: " . $user['id'] . ")");
            AppConfig::debug("Generated token length: " . strlen($token) . " chars");

            // Debug: Inspect the generated token
            if (AppConfig::isDevelopment()) {
                $tokenInfo = $this->tokenGenerator->inspectToken($token);
                AppConfig::debug("Generated token info: " . json_encode($tokenInfo));
            }

            // Return user data with token
            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'token' => $token,
                'user' => [
                    'uid' => $user['uid'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'name' => $user['full_name'],
                    'last_login' => $user['last_login']
                ]
            ]);

        } catch (Exception $e) {
            AppConfig::error("Exception in login: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Token verification endpoint
    public function verifyToken() {
        AppConfig::debug("verifyToken method called");
        
        try {
            require_once __DIR__ . '/auth/middleware.php';
            AppConfig::debug("Attempting to authenticate token");
            $payload = authenticateRequest();
            
            if ($payload) {
                AppConfig::debug("Token verified successfully for user: " . $payload->email . " (ID: " . $payload->user_id . ")");
                
                // Token is valid, return user info
                $user = $this->getUserById($payload->user_id);
                
                if ($user) {
                    AppConfig::debug("User found for token: " . $user['email']);
                    echo json_encode([
                        'success' => true,
                        'message' => 'Token is valid',
                        'user' => [
                            'uid' => $user['uid'],
                            'email' => $user['email'],
                            'role' => $user['role'],
                            'name' => $user['full_name']
                        ]
                    ]);
                } else {
                    AppConfig::warn("Token valid but user not found in database: " . $payload->user_id);
                    http_response_code(401);
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                }
            } else {
                AppConfig::warn("Token verification failed - no payload returned");
            }

        } catch (Exception $e) {
            AppConfig::error("Exception in verifyToken: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    public function forgotPassword() {
        AppConfig::debug("forgotPassword method called");
        
        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (empty($data['email'])) {
                AppConfig::warn("Forgot password failed: Email missing");
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Email is required']);
                return;
            }

            $email = trim($data['email']);
            AppConfig::debug("Password reset requested for: " . $email);

            $user = $this->getUserByEmail($email);

            if (!$user) {
                AppConfig::debug("Password reset: User not found (not revealing) - " . $email);
                // Don't reveal whether email exists or not
                echo json_encode([
                    'success' => true, 
                    'message' => 'If the email exists, a password reset link has been sent.'
                ]);
                return;
            }

            AppConfig::log("Password reset token generated for: " . $email);

            // Generate reset token (in a real app, you'd send an email)
            $reset_token = bin2hex(random_bytes(32));
            $reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $query = "UPDATE " . $this->table . " SET 
                     password_reset_token = ?, 
                     password_reset_expires = ? 
                     WHERE email = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("sss", $reset_token, $reset_expires, $email);
            
            if ($stmt->execute()) {
                AppConfig::log("Password reset token saved for: " . $email);
                // In production, send email with reset link
                // For now, we'll just return success
                echo json_encode([
                    'success' => true, 
                    'message' => 'If the email exists, a password reset link has been sent.',
                    'debug_token' => $reset_token // Remove this in production
                ]);
            } else {
                AppConfig::error("Failed to save password reset token for: " . $email);
                throw new Exception("Failed to process reset request");
            }

        } catch (Exception $e) {
            AppConfig::error("Exception in forgotPassword: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    // Debug endpoint to check token generation
    public function debugTokenGeneration() {
        AppConfig::debug("debugTokenGeneration method called");
        
        try {
            // Get the secret key being used
            $secretKey = $this->tokenGenerator->getSecretKey();
            
            // Create a test user
            $testUser = [
                'id' => 999,
                'email' => 'test@example.com',
                'role' => 'Admin',
                'full_name' => 'Test User'
            ];
            
            // Generate a test token
            $testToken = $this->tokenGenerator->generateToken($testUser);
            $tokenInfo = $this->tokenGenerator->inspectToken($testToken);
            
            echo json_encode([
                'success' => true,
                'debug_info' => [
                    'secret_key_length' => strlen($secretKey),
                    'secret_key_preview' => substr($secretKey, 0, 10) . '...',
                    'test_token' => $testToken,
                    'token_info' => $tokenInfo,
                    'token_generator_class' => get_class($this->tokenGenerator)
                ]
            ]);
            
        } catch (Exception $e) {
            AppConfig::error("Exception in debugTokenGeneration: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }

    private function getUserByEmail($email) {
        AppConfig::debug("Querying user by email: " . $email);
        
        $query = "SELECT * FROM " . $this->table . " WHERE email = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $user = $result->fetch_assoc();
        AppConfig::debug("User query result: " . ($user ? 'FOUND (ID: ' . $user['id'] . ')' : 'NOT FOUND'));
        
        return $user;
    }

    private function getUserById($id) {
        AppConfig::debug("Querying user by ID: " . $id);
        
        $query = "SELECT id, uid, email, role, full_name, is_active FROM " . $this->table . " WHERE id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $user = $result->fetch_assoc();
        AppConfig::debug("User by ID result: " . ($user ? 'FOUND' : 'NOT FOUND'));
        
        return $user;
    }

    private function handleFailedLogin($user) {
        AppConfig::debug("Handling failed login for user: " . $user['email']);
        
        $login_attempts = $user['login_attempts'] + 1;
        $account_locked_until = null;

        // Lock account after 5 failed attempts for 30 minutes
        if ($login_attempts >= 5) {
            $account_locked_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            AppConfig::warn("Account locked: " . $user['email'] . " - too many failed attempts");
        }

        $query = "UPDATE " . $this->table . " SET 
                 login_attempts = ?, 
                 account_locked_until = ? 
                 WHERE id = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("isi", $login_attempts, $account_locked_until, $user['id']);
        $stmt->execute();
        
        AppConfig::debug("Failed login recorded - attempts: " . $login_attempts);
    }

    private function handleSuccessfulLogin($user) {
        AppConfig::debug("Handling successful login for user: " . $user['email']);
        
        $query = "UPDATE " . $this->table . " SET 
                 login_attempts = 0, 
                 account_locked_until = NULL, 
                 last_login = NOW() 
                 WHERE id = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        
        AppConfig::debug("Successful login recorded for user: " . $user['email']);
    }

    private function logLoginAttempt($email, $success) {
        AppConfig::log("Login attempt for " . $email . ": " . ($success ? 'SUCCESS' : 'FAILED'));
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
AppConfig::debug("=== AUTH API REQUEST STARTED ===");
AppConfig::debug("Request Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown'));
AppConfig::debug("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown'));

$method = $_SERVER['REQUEST_METHOD'];
$api = new AuthAPI();

// Get the request path
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

AppConfig::debug("Request path: " . $path);

if ($method == 'POST') {
    if (strpos($path, 'verify-token') !== false) {
        AppConfig::debug("Routing to verify-token endpoint");
        $api->verifyToken();
    } else if (strpos($path, 'forgot-password') !== false) {
        AppConfig::debug("Routing to forgot-password endpoint");
        $api->forgotPassword();
    } else if (strpos($path, 'debug-token') !== false) {
        AppConfig::debug("Routing to debug-token endpoint");
        $api->debugTokenGeneration();
    } else {
        AppConfig::debug("Routing to login endpoint");
        $api->login();
    }
} else if ($method != 'OPTIONS') {
    AppConfig::warn("Method not allowed: " . $method);
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

AppConfig::debug("=== AUTH API REQUEST COMPLETED ===");
?>