<?php
require_once __DIR__ . '/../config/appconfig.php';

class TokenGenerator {
    private $secret_key;

    public function __construct($secret_key = null) {
        if ($secret_key) {
            $this->secret_key = $secret_key;
        } else {
            // Get secret key from appconfig to ensure consistency
            $jwtConfig = AppConfig::getJWTConfig();
            $this->secret_key = $jwtConfig['secret'];
            
            // Log in development for debugging
            if (AppConfig::isDevelopment()) {
                AppConfig::debug("TokenGenerator initialized - Secret key: " . (empty($this->secret_key) ? 'EMPTY' : 'SET (' . strlen($this->secret_key) . ' chars)'));
            }
        }
    }

    public function generateToken($user) {
        AppConfig::debug("Generating token for user: " . $user['email']);
        
        // Verify secret key is set
        if (empty($this->secret_key)) {
            AppConfig::error("JWT Secret key is empty - cannot generate token");
            throw new Exception("JWT secret key not configured");
        }

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'name' => $user['full_name'] ?? $user['email'],
            'iat' => time(),
            'exp' => time() + AppConfig::$JWT_EXPIRY_HOURS * 3600 // Use configured expiry
        ]);

        AppConfig::debug("Token payload generated for user ID: " . $user['id']);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);

        $dataToSign = $base64UrlHeader . "." . $base64UrlPayload;
        $signature = hash_hmac('sha256', $dataToSign, $this->secret_key, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        $token = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
        
        AppConfig::debug("Token generated successfully - Length: " . strlen($token) . " chars");
        AppConfig::debug("Token preview: " . substr($token, 0, 50) . '...');
        
        return $token;
    }

    private function base64UrlEncode($data) {
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data);
        }
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // Utility method to get the secret key (for debugging)
    public function getSecretKey() {
        return $this->secret_key;
    }

    // Utility method to decode a token without verification (for debugging)
    public function inspectToken($token) {
        try {
            $tokenParts = explode('.', $token);
            if (count($tokenParts) != 3) {
                return ['valid' => false, 'error' => 'Invalid token structure'];
            }

            list($header, $payload, $signature) = $tokenParts;

            $decodedHeader = $this->base64UrlDecode($header);
            $decodedPayload = $this->base64UrlDecode($payload);

            if (!$decodedHeader || !$decodedPayload) {
                return ['valid' => false, 'error' => 'Token decoding failed'];
            }

            $headerObj = json_decode($decodedHeader);
            $payloadObj = json_decode($decodedPayload);

            if (!$headerObj || !$payloadObj) {
                return ['valid' => false, 'error' => 'Token JSON parsing failed'];
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

    private function base64UrlDecode($data) {
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }
        
        $decoded = base64_decode(strtr($data, '-_', '+/'));
        
        if ($decoded === false) {
            return false;
        }
        
        return $decoded;
    }
}
?>