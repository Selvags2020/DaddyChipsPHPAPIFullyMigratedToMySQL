<?php
require_once 'token_verifier.php';

class AuthMiddleware {
    // Require authentication - will stop execution if not authenticated
    public static function requireAuth() {
        $tokenVerifier = new TokenVerifier();
        $payload = $tokenVerifier->verifyToken();
        
        if (!$payload) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }
        
        return $payload;
    }

    // Optional authentication - returns user data if authenticated, false if not
    public static function optionalAuth() {
        $tokenVerifier = new TokenVerifier();
        return $tokenVerifier->verifyToken(); // Returns payload or false
    }

    // Require specific role
    public static function requireRole($requiredRole) {
        $payload = self::requireAuth();
        
        if ($payload->role !== $requiredRole) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
            exit;
        }
        
        return $payload;
    }
}
?>