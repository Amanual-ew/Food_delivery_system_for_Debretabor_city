<?php
// localization.php: Centralized Localization Management (Language Manager)
// This file must be included AFTER session_start() but BEFORE any output.

// 1. Set default language if not already set
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'en'; // Default to English
}

// 2. Handle language switch via GET request (e.g., from a link like <a href="?lang=am">አማ</a>)
// Only support 'en' and 'am'
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'am'])) {
    $_SESSION['language'] = $_GET['lang'];
    // The redirect has been removed so the URL parameter remains visible, 
    // but the language session variable is still updated.
}

// 3. Load the appropriate language file
$current_language = $_SESSION['language'];
// Assumes a 'lang' directory with files named 'en.php' and 'am.php'
$lang_file = 'lang/' . $current_language . '.php'; 

// Ensure the lang folder exists and the file is present
if (file_exists($lang_file)) {
    require_once $lang_file;
} else {
    // FALLBACK: Load English if the specific language file is missing
    require_once 'lang/en.php';
    $current_language = 'en';
}

/**
 * Global translation function: looks up a key and handles placeholders.
 * Use this everywhere instead of echoing raw text.
 * Example: echo __('nav_dashboard');
 * Example with placeholder: echo __('welcome_message', $username);
 *
 * @param string $key The translation key (e.g., 'nav_dashboard').
 * @param mixed ...$args Any number of arguments to be inserted into placeholders (%s, %d).
 * @return string The translated string, or the key itself as a fallback.
 */
function __($key, ...$args) {
    global $lang;
    // Get the raw string or use the key as fallback
    $string = isset($lang[$key]) ? $lang[$key] : '[' . $key . ']';
    
    // Apply arguments using vsprintf, then safely output HTML special characters
    if (!empty($args)) {
        return vsprintf(htmlspecialchars($string, ENT_QUOTES, 'UTF-8'), $args);
    }
    
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Safe HTML output function
 * @param string $string The string to escape.
 * @return string The escaped string.
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Set up direction (for HTML 'dir' attribute)
$dir = ($current_language == 'am' ? 'rtl' : 'ltr');
?>
