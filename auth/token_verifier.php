<?php
require_once __DIR__ . '/../config/appconfig.php';

class TokenVerifier {
    private $secret_key;

    public function __construct($secret_key = null) {
        if ($secret_key) {
            $this->secret_key = $secret_key;
        } else {
            // Get secret key from appconfig
            $jwtConfig = AppConfig::getJWTConfig();
            $this->secret_key = $jwtConfig['secret'];
            
            // Debug: Check if secret key is loaded
            if (AppConfig::isDevelopment()) {
                error_log("=== TOKEN VERIFIER INITIALIZATION ===");
                error_log("JWT Secret Key: " . (empty($this->secret_key) ? 'EMPTY - THIS WILL CAUSE FAILURES' : 'SET (' . strlen($this->secret_key) . ' chars)'));
                error_log("App Environment: " . AppConfig::getEnvironment());
                error_log("=== END INITIALIZATION ===");
            }
        }
    }

    public function verifyToken() {
        $token = $this->getBearerToken();
        
        if (AppConfig::isDevelopment()) {
            error_log("=== TOKEN VERIFICATION STARTED ===");
            error_log("Token received: " . ($token ? substr($token, 0, 50) . '...' : 'NULL OR EMPTY'));
            error_log("Authorization header: " . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'Not set in HTTP_AUTHORIZATION'));
            error_log("Raw Authorization: " . ($_SERVER['Authorization'] ?? 'Not set in Authorization'));
            error_log("Request method: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown'));
            error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown'));
        }
        
        if (!$token) {
            if (AppConfig::isDevelopment()) {
                error_log("❌ No bearer token found in request");
                error_log("=== TOKEN VERIFICATION FAILED - NO TOKEN ===");
            }
            return false;
        }

        $result = $this->verifyJWT($token);
        
        if (AppConfig::isDevelopment()) {
            if ($result) {
                error_log("✅ Token verification SUCCESS for user: " . $result->email);
            } else {
                error_log("❌ Token verification FAILED");
            }
            error_log("=== TOKEN VERIFICATION COMPLETED ===");
        }
        
        return $result;
    }

    private function verifyJWT($token) {
        try {
            // Clean the token
            $token = trim($token);
            
            if (AppConfig::isDevelopment()) {
                error_log("Token length: " . strlen($token) . " characters");
            }

            $tokenParts = explode('.', $token);
            if (count($tokenParts) != 3) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ JWT token has invalid structure - expected 3 parts, got " . count($tokenParts));
                    error_log("Token parts: " . json_encode($tokenParts));
                }
                return false;
            }

            list($header, $payload, $signatureProvided) = $tokenParts;

            // Debug token parts
            if (AppConfig::isDevelopment()) {
                error_log("Header part: " . substr($header, 0, 30) . '...');
                error_log("Payload part: " . substr($payload, 0, 30) . '...');
                error_log("Signature part: " . substr($signatureProvided, 0, 30) . '...');
            }

            // Decode header and payload
            $decodedHeader = $this->base64UrlDecode($header);
            $decodedPayload = $this->base64UrlDecode($payload);
            
            if (!$decodedHeader) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ Header decoding failed");
                    error_log("Header to decode: " . $header);
                }
                return false;
            }
            
            if (!$decodedPayload) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ Payload decoding failed");
                    error_log("Payload to decode: " . $payload);
                }
                return false;
            }

            $headerObj = json_decode($decodedHeader);
            $payloadObj = json_decode($decodedPayload);

            if (!$headerObj) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ Header JSON parsing failed");
                    error_log("Header JSON string: " . $decodedHeader);
                    error_log("JSON error: " . json_last_error_msg());
                }
                return false;
            }
            
            if (!$payloadObj) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ Payload JSON parsing failed");
                    error_log("Payload JSON string: " . $decodedPayload);
                    error_log("JSON error: " . json_last_error_msg());
                }
                return false;
            }

            // Verify algorithm
            if (!isset($headerObj->alg) || $headerObj->alg !== 'HS256') {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ JWT token uses unsupported algorithm: " . ($headerObj->alg ?? 'none'));
                    error_log("Supported algorithm: HS256");
                }
                return false;
            }

            // Create signature to verify
            $base64UrlHeader = $this->base64UrlEncode($decodedHeader);
            $base64UrlPayload = $this->base64UrlEncode($decodedPayload);
            
            $dataToSign = $base64UrlHeader . "." . $base64UrlPayload;
            $signature = hash_hmac('sha256', $dataToSign, $this->secret_key, true);
            $base64UrlSignature = $this->base64UrlEncode($signature);

            // Verify signature
            if (!hash_equals($base64UrlSignature, $signatureProvided)) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ JWT token signature verification FAILED");
                    error_log("Data to sign: " . $dataToSign);
                    error_log("Expected signature: " . $base64UrlSignature);
                    error_log("Received signature: " . $signatureProvided);
                    error_log("Secret key length: " . strlen($this->secret_key));
                    error_log("Secret key (first 10 chars): " . substr($this->secret_key, 0, 10) . '...');
                    error_log("Signatures match: " . (hash_equals($base64UrlSignature, $signatureProvided) ? 'YES' : 'NO'));
                }
                return false;
            }

            if (AppConfig::isDevelopment()) {
                error_log("✅ Signature verification PASSED");
            }

            // Check token expiration
            $currentTime = time();
            
            if (!isset($payloadObj->exp)) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ JWT token missing expiration (exp) claim");
                }
                return false;
            }
            
            if ($payloadObj->exp < $currentTime) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ JWT token has EXPIRED");
                    error_log("Expiry time: " . date('Y-m-d H:i:s', $payloadObj->exp));
                    error_log("Current time: " . date('Y-m-d H:i:s', $currentTime));
                    error_log("Time difference: " . ($currentTime - $payloadObj->exp) . " seconds ago");
                }
                return false;
            }

            // Check token issuance time
            if (isset($payloadObj->iat)) {
                if ($payloadObj->iat > $currentTime + 60) {
                    if (AppConfig::isDevelopment()) {
                        error_log("❌ JWT token issued in the future");
                        error_log("Issued at: " . date('Y-m-d H:i:s', $payloadObj->iat));
                        error_log("Current time: " . date('Y-m-d H:i:s', $currentTime));
                    }
                    return false;
                }
            }

            // Check required fields
            if (!isset($payloadObj->user_id)) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ JWT token missing required field: user_id");
                }
                return false;
            }
            
            if (!isset($payloadObj->email)) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ JWT token missing required field: email");
                }
                return false;
            }

            // Additional validation for token not before time (if present)
            if (isset($payloadObj->nbf) && $payloadObj->nbf > $currentTime) {
                if (AppConfig::isDevelopment()) {
                    error_log("❌ JWT token not yet valid (nbf)");
                    error_log("Not before: " . date('Y-m-d H:i:s', $payloadObj->nbf));
                }
                return false;
            }

            if (AppConfig::isDevelopment()) {
                error_log("✅ All token validations PASSED");
                error_log("User ID: " . $payloadObj->user_id);
                error_log("Email: " . $payloadObj->email);
                error_log("Role: " . ($payloadObj->role ?? 'Not set'));
                error_log("Issued at: " . (isset($payloadObj->iat) ? date('Y-m-d H:i:s', $payloadObj->iat) : 'Not set'));
                error_log("Expires at: " . date('Y-m-d H:i:s', $payloadObj->exp));
            }

            return $payloadObj;

        } catch (Exception $e) {
            if (AppConfig::isDevelopment()) {
                error_log("❌ JWT verification EXCEPTION: " . $e->getMessage());
                error_log("Exception trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    private function getBearerToken() {
        $headers = $this->getAuthorizationHeader();
        
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            } else {
                if (AppConfig::isDevelopment()) {
                    error_log("Authorization header exists but doesn't match Bearer pattern: " . $headers);
                }
            }
        } else {
            if (AppConfig::isDevelopment()) {
                error_log("No Authorization header found in request");
                
                // Log all available headers for debugging
                $allHeaders = [];
                if (function_exists('apache_request_headers')) {
                    $allHeaders = apache_request_headers();
                }
                error_log("All request headers: " . json_encode($allHeaders));
                
                // Also check $_SERVER for authorization headers
                $authHeaders = [];
                foreach ($_SERVER as $key => $value) {
                    if (stripos($key, 'authorization') !== false) {
                        $authHeaders[$key] = $value;
                    }
                }
                error_log("Authorization-related SERVER vars: " . json_encode($authHeaders));
            }
        }
        
        // Also check for token in POST data or GET parameters (for flexibility)
        if (!empty($_POST['token'])) {
            if (AppConfig::isDevelopment()) {
                error_log("Found token in POST data");
            }
            return $_POST['token'];
        }
        
        if (!empty($_GET['token'])) {
            if (AppConfig::isDevelopment()) {
                error_log("Found token in GET parameters");
            }
            return $_GET['token'];
        }
        
        return null;
    }

    private function getAuthorizationHeader() {
        $headers = null;
        
        // Try different server variables for Authorization header
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER['Authorization']);
            if (AppConfig::isDevelopment()) {
                error_log("Found Authorization in \$_SERVER['Authorization']");
            }
        } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
            if (AppConfig::isDevelopment()) {
                error_log("Found Authorization in \$_SERVER['HTTP_AUTHORIZATION']");
            }
        } else if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
            if (AppConfig::isDevelopment()) {
                error_log("Found Authorization in \$_SERVER['REDIRECT_HTTP_AUTHORIZATION']");
            }
        } else if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            
            if (AppConfig::isDevelopment()) {
                error_log("Apache request headers available: " . json_encode($requestHeaders));
            }
            
            // Server-specific fixes for case sensitivity
            $requestHeaders = array_combine(
                array_map(function($key) {
                    return str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $key))));
                }, array_keys($requestHeaders)), 
                array_values($requestHeaders)
            );
            
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
                if (AppConfig::isDevelopment()) {
                    error_log("Found Authorization in apache_request_headers()");
                }
            }
        }
        
        return $headers;
    }

    private function base64UrlEncode($data) {
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }
        $encoded = base64_encode($data);
        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    private function base64UrlDecode($data) {
        // Add padding if needed
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }
        
        $decoded = base64_decode(strtr($data, '-_', '+/'));
        
        // Check if decoding was successful
        if ($decoded === false) {
            if (AppConfig::isDevelopment()) {
                error_log("Base64 decoding failed for data: " . $data);
            }
            return false;
        }
        
        return $decoded;
    }

    // Utility method to get the secret key (for token generation)
    public function getSecretKey() {
        return $this->secret_key;
    }

    // Utility method to validate token without verification (for debugging)
    public function inspectToken($token) {
        try {
            $tokenParts = explode('.', $token);
            if (count($tokenParts) != 3) {
                return ['valid' => false, 'error' => 'Invalid token structure - expected 3 parts'];
            }

            list($header, $payload, $signature) = $tokenParts;

            $decodedHeader = $this->base64UrlDecode($header);
            $decodedPayload = $this->base64UrlDecode($payload);

            if (!$decodedHeader) {
                return ['valid' => false, 'error' => 'Header decoding failed'];
            }
            
            if (!$decodedPayload) {
                return ['valid' => false, 'error' => 'Payload decoding failed'];
            }

            $headerObj = json_decode($decodedHeader);
            $payloadObj = json_decode($decodedPayload);

            if (!$headerObj) {
                return ['valid' => false, 'error' => 'Header JSON parsing failed: ' . json_last_error_msg()];
            }
            
            if (!$payloadObj) {
                return ['valid' => false, 'error' => 'Payload JSON parsing failed: ' . json_last_error_msg()];
            }

            $currentTime = time();
            $expired = isset($payloadObj->exp) && $payloadObj->exp < $currentTime;

            return [
                'valid' => true,
                'header' => $headerObj,
                'payload' => $payloadObj,
                'signature' => $signature,
                'expired' => $expired,
                'current_time' => $currentTime,
                'expiry_time' => $payloadObj->exp ?? null,
                'time_until_expiry' => isset($payloadObj->exp) ? $payloadObj->exp - $currentTime : null
            ];
        } catch (Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }
}
?>