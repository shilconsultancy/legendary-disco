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

$message_type = 'error'; // Default message type
$message = 'An unexpected error occurred.';

// Check if a notice ID was provided via GET request
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $notice_id = (int)$_GET['id']; // Cast to integer for security

    // Ensure the ID is a positive integer
    if ($notice_id > 0) {
        // Prepare a SQL statement to delete the notice record.
        $stmt = $conn->prepare("DELETE FROM notices WHERE id = ?");
        $stmt->bind_param("i", $notice_id);

        if ($stmt->execute()) {
            // Check if any rows were affected (meaning a notice record was actually deleted)
            if ($stmt->affected_rows > 0) {
                $message_type = 'success';
                $message = 'Notice record with ID #' . htmlspecialchars($notice_id) . ' has been successfully deleted.';
            } else {
                // This could happen if the notice ID doesn't exist
                $message_type = 'warning';
                $message = 'Notice record with ID #' . htmlspecialchars($notice_id) . ' not found.';
            }
        } else {
            $message = 'Error deleting notice record: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Invalid notice ID provided.';
    }
} else {
    $message = 'No notice ID specified for deletion.';
}

// Close the database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// Redirect back to the notices dashboard with a message
header('Location: learning_hub.php?message_type=' . urlencode($message_type) . '&message=' . urlencode($message));
exit;
?>
