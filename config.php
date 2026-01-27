<?php
// Ensure session is started at the very beginning, before any output
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection details
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', ''); // Your database password
define('DB_NAME', 'food_delivery_db'); // Your database name

/* Attempt to connect to MySQL database */
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- Language Handling Logic ---
// 1. Determine current language from session or set default
$current_lang = $_SESSION['lang'] ?? 'en';
$supported_languages = ['en', 'am'];

// Validate if the language in session is supported, otherwise default to 'en'
if (!in_array($current_lang, $supported_languages)) {
    $current_lang = 'en';
}

// 2. Load translations
$translations = [];
// Use __DIR__ to get the current directory (where config.php resides)
// and then build the path to the lang folder
$lang_file_path = __DIR__ . "/lang/{$current_lang}.php";

if (file_exists($lang_file_path)) {
    $translations = require $lang_file_path; // Use 'require' as it's a critical include
} else {
    // Fallback to English if the selected language file is missing (e.g., if 'am.php' is gone)
    $translations = require __DIR__ . "/lang/en.php";
    $current_lang = 'en'; // Reset language to English if fallback occurred
}

// 3. Define the global translation helper function
// Check if function exists to prevent redeclaration errors if config.php is included multiple times
if (!function_exists('__')) {
    function __($key) {
        global $translations; // Access the global translations array
        // Return translated string, or the key itself if translation is missing
        return $translations[$key] ?? $key;
    }
}

// 4. Define the global HTML special characters helper function
// Check if function exists to prevent redeclaration errors
if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

?>