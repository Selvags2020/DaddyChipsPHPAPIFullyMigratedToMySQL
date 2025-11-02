<?php
// appconfig.php - Central configuration file for the application

class AppConfig {
    // Database Configuration
    public static $DB_HOST = 'localhost';
    public static $DB_NAME = 'your_database_name';
    public static $DB_USER = 'your_db_username';
    public static $DB_PASS = 'your_db_password';
    public static $DB_CHARSET = 'utf8mb4';

    // JWT Configuration
    public static $JWT_SECRET_KEY = "@rh$%kkjf&^%$#()!@<>?_-*&^%";
    public static $JWT_EXPIRY_HOURS = 24; // Token expiry in hours
    
    // Application Settings
    public static $APP_NAME = "Your App Name";
    public static $APP_VERSION = "1.0.0";
    public static $APP_ENV = "development"; // development, staging, production
    
    // Logging Configuration
    public static $LOG_ENABLED = true;
    public static $LOG_FILE_PATH = __DIR__ . '/../logs/app_debug.log';
    public static $LOG_LEVEL = 'DEBUG'; // DEBUG, INFO, WARN, ERROR
    
    // CORS Configuration
    public static $ALLOWED_ORIGINS = [
        "http://localhost:3000",
        "http://127.0.0.1:3000",
        "https://yourdomain.com"
    ];
    
    // File Upload Configuration
    public static $MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB in bytes
    public static $ALLOWED_FILE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public static $UPLOAD_DIR = "uploads/";
    
    // Security Settings
    public static $BCRYPT_COST = 12;
    public static $RATE_LIMIT_REQUESTS = 100; // Requests per hour
    public static $RATE_LIMIT_TIME = 3600; // 1 hour in seconds
    
    // Email Configuration
    public static $SMTP_HOST = "smtp.gmail.com";
    public static $SMTP_PORT = 587;
    public static $SMTP_USER = "your-email@gmail.com";
    public static $SMTP_PASS = "your-app-password";
    public static $FROM_EMAIL = "noreply@yourdomain.com";
    public static $FROM_NAME = "Your App Name";
    
    // API Configuration
    public static $API_RATE_LIMIT = true;
    public static $API_DEBUG = true; // Set to false in production

    // Get environment-based configuration
    public static function getConfig() {
        $environment = self::getEnvironment();
        
        $configs = [
            'development' => [
                'debug' => true,
                'log_errors' => true,
                'display_errors' => false, // Changed to false for security
                'log_level' => 'DEBUG',
                'base_url' => 'http://localhost:3000'
            ],
            'staging' => [
                'debug' => true,
                'log_errors' => true,
                'display_errors' => false,
                'log_level' => 'INFO',
                'base_url' => 'https://staging.yourdomain.com'
            ],
            'production' => [
                'debug' => false,
                'log_errors' => true,
                'display_errors' => false,
                'log_level' => 'ERROR',
                'base_url' => 'https://yourdomain.com'
            ]
        ];
        
        return $configs[$environment] ?? $configs['development'];
    }
    
    // Get current environment
    public static function getEnvironment() {
        return self::$APP_ENV;
    }
    
    // Check if running in development
    public static function isDevelopment() {
        return self::$APP_ENV === 'development';
    }
    
    // Check if running in production
    public static function isProduction() {
        return self::$APP_ENV === 'production';
    }
    
    // Get database configuration as array
    public static function getDatabaseConfig() {
        return [
            'host' => self::$DB_HOST,
            'name' => self::$DB_NAME,
            'user' => self::$DB_USER,
            'pass' => self::$DB_PASS,
            'charset' => self::$DB_CHARSET
        ];
    }
    
    // Get JWT configuration
    public static function getJWTConfig() {
        return [
            'secret' => self::$JWT_SECRET_KEY,
            'expiry' => self::$JWT_EXPIRY_HOURS * 3600 // Convert to seconds
        ];
    }

    // Get logging configuration
    public static function getLogConfig() {
        return [
            'enabled' => self::$LOG_ENABLED,
            'file_path' => self::$LOG_FILE_PATH,
            'level' => self::$LOG_LEVEL
        ];
    }
    
    // Initialize logging system
    public static function initializeLogging() {
        if (!self::$LOG_ENABLED) {
            return;
        }

        // Create logs directory if it doesn't exist
        $logDir = dirname(self::$LOG_FILE_PATH);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                // Fallback to a different location if primary fails
                self::$LOG_FILE_PATH = __DIR__ . '/../tmp/app_debug.log';
                $logDir = dirname(self::$LOG_FILE_PATH);
                if (!is_dir($logDir)) {
                    mkdir($logDir, 0755, true);
                }
            }
        }

        // Set custom error log path
        ini_set('error_log', self::$LOG_FILE_PATH);
        
        // Log initialization message
        $timestamp = date('Y-m-d H:i:s');
        $initMessage = "[$timestamp] === APPLICATION STARTED ===\n";
        $initMessage .= "[$timestamp] Environment: " . self::$APP_ENV . "\n";
        $initMessage .= "[$timestamp] Log File: " . self::$LOG_FILE_PATH . "\n";
        $initMessage .= "[$timestamp] ===========================\n";
        
        file_put_contents(self::$LOG_FILE_PATH, $initMessage, FILE_APPEND | LOCK_EX);
    }

    // Simple logging method that can be used anywhere
    public static function log($message, $level = 'INFO') {
        if (!self::$LOG_ENABLED) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        
        file_put_contents(self::$LOG_FILE_PATH, $logMessage, FILE_APPEND | LOCK_EX);
    }

    // Log debug messages (only in development)
    public static function debug($message) {
        if (self::isDevelopment()) {
            self::log($message, 'DEBUG');
        }
    }

    // Log error messages
    public static function error($message) {
        self::log($message, 'ERROR');
    }

    // Log warning messages
    public static function warn($message) {
        self::log($message, 'WARN');
    }
    
    // Initialize application settings based on environment
    public static function initialize() {
        $config = self::getConfig();
        
        // Set error reporting based on environment
        if ($config['display_errors']) {
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', 0);
            ini_set('display_startup_errors', 0);
            // Still log errors but don't display them
            error_reporting(E_ALL);
        }
        
        // Set logging
        if ($config['log_errors']) {
            ini_set('log_errors', 1);
        }
        
        // Set timezone
        date_default_timezone_set('UTC');

        // Initialize custom logging
        self::initializeLogging();

        // Log initialization
        self::log("Application initialized - Environment: " . self::$APP_ENV);
        self::log("Database: " . self::$DB_HOST . "/" . self::$DB_NAME);
        self::log("JWT Secret: " . (empty(self::$JWT_SECRET_KEY) ? 'NOT SET' : 'SET'));

        if (self::isDevelopment()) {
            self::debug("Debug logging enabled");
        }
    }

    // Get allowed file types as string for file input
    public static function getAllowedFileTypesString() {
        return implode(',', self::$ALLOWED_FILE_TYPES);
    }

    // Get upload max size in human readable format
    public static function getMaxFileSizeReadable() {
        $size = self::$MAX_FILE_SIZE;
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }
        
        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    // Check if origin is allowed
    public static function isOriginAllowed($origin) {
        return in_array($origin, self::$ALLOWED_ORIGINS);
    }

    // Get the best allowed origin for CORS
    public static function getAllowedOrigin($requestOrigin = '') {
        if (!empty($requestOrigin) && self::isOriginAllowed($requestOrigin)) {
            return $requestOrigin;
        }
        return self::$ALLOWED_ORIGINS[0]; // Return first allowed origin as fallback
    }
}

// Initialize the application configuration
AppConfig::initialize();

?>