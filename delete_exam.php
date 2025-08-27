<?php
session_start();

// Enable error reporting for debugging. Turn this off in a production environment.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database configuration file.
require_once 'db_config.php';

// --- CRITICAL DATABASE CONNECTION VALIDATION ---
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    die("Error: Database connection could not be established. Please check 'db_config.php' and your MySQL server status.");
}

// --- Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php'); // Redirect to login page
    exit;
}

// Default message values, will only be set if a deletion attempt occurs
$message_type = null;
$message = null;

// --- Super Admin Password Configuration ---
$SUPER_ADMIN_PASSWORD = 'quickbook123'; // Define the super admin password

// Only process deletion if it's a POST request and parameters are set
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['exam_id']) && isset($_POST['super_admin_password'])) {
    if ($_POST['super_admin_password'] === $SUPER_ADMIN_PASSWORD) {
        // Password is correct, proceed with deletion logic
        $exam_id = (int)$_POST['exam_id'];

        if ($exam_id > 0) {
            $stmt = $conn->prepare("DELETE FROM exams WHERE id = ?");
            $stmt->bind_param("i", $exam_id);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $message_type = 'success';
                    $message = 'Exam schedule with ID #' . htmlspecialchars($exam_id) . ' has been successfully deleted.';
                } else {
                    $message_type = 'warning';
                    $message = 'Exam schedule with ID #' . htmlspecialchars($exam_id) . ' not found.';
                }
            } else {
                $message = 'Error deleting exam schedule: ' . $stmt->error;
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = 'Invalid exam ID provided.';
            $message_type = 'error';
        }
    } else {
        // Incorrect super admin password
        $message = 'Incorrect super admin password. Deletion denied.';
        $message_type = 'error';
    }
} 
// If it's not a POST request with the expected parameters, or a direct GET request,
// we do nothing here, letting exam_scheduling.php load normally.
// The redirection below only happens if $message_type is set.


// Close the database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// Redirect back to the exam scheduling dashboard ONLY if a message needs to be displayed
if ($message_type !== null) {
    header('Location: exam_scheduling.php?message_type=' . urlencode($message_type) . '&message=' . urlencode($message));
    exit;
}
// If no message was generated (i.e., it was a direct GET request without a deletion attempt),
// the script simply finishes execution and the page loads normally.
?>
