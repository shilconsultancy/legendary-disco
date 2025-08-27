<?php
// db_config.php - Database Connection and Configuration
// This file is assumed to be located inside the 'student/' directory.

// --- Database Credentials ---
$db_host = "localhost";        // Make sure this is correct for your Hostinger setup
$db_user = "u338187101_lms";   // VERIFY THIS USERNAME EXACTLY IN HOSTRINGER
$db_pass = "*sX*rb4fk8O";      // VERIFY THIS PASSWORD EXACTLY IN HOSTRINGER (or set a new one)
$db_name = "u338187101_lms";   // VERIFY THIS DATABASE NAME EXACTLY IN HOSTRINGER

// --- Establish Database Connection ---
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name); // This is line 12

// Check connection
if ($conn->connect_error) {
    // In a production environment, you might want to log this error
    // and display a user-friendly message instead of dying.
    die("Connection failed: " . $conn->connect_error);
}

// Set character set to UTF-8 for proper handling of various characters
$conn->set_charset("utf8mb4");

// --- Define BASE_URL Constant ---
// This constant is used for constructing absolute URLs to assets (like images)
// and for consistent internal linking across your application.
// This calculation assumes db_config.php is in a subdirectory (e.g., 'student/').

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];

// Get the directory of the current script (e.g., /home/u338187101/domains/shilconsultancy.com/public_html/lms/student)
$current_script_dir = realpath(__DIR__);

// Go up one level to get to the project root (e.g., /home/u338187101/domains/shilconsultancy.com/public_html/lms)
$project_root_dir = realpath($current_script_dir . '/..');

// Calculate the path to the project root relative to the document root
// This will give something like '/lms'
$document_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$project_root_relative_path = str_replace($document_root, '', str_replace('\\', '/', $project_root_dir));

// Ensure it starts with a slash and ends with a slash for consistent URL construction
$base_url_calculated = '/' . ltrim($project_root_relative_path, '/') . '/';
$base_url_calculated = str_replace('//', '/', $base_url_calculated); // Remove any double slashes

define('BASE_URL', $base_url_calculated);

// You can uncomment the line below for debugging BASE_URL if needed
// error_log("DEBUG: BASE_URL is " . BASE_URL);

?>