<?php
session_start();

// Enable error reporting for debugging. Turn this off in a production environment.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database configuration file.
require_once 'db_config.php';

// --- CRITICAL DATABASE CONNECTION VALIDATION ---
// Ensure the database connection was successfully established.
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    die("Error: Database connection could not be established. Please check 'db_config.php' and your MySQL server status.");
}

// --- Admin Authentication Check ---
// Ensures only logged-in administrators can access this script.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php'); // Redirect to login page
    exit;
}

$message_type = 'error'; // Default message type
$message = 'An unexpected error occurred.';

// Check if a student ID was provided via GET request
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $student_id = (int)$_GET['id']; // Cast to integer for security

    // Ensure the ID is a positive integer
    if ($student_id > 0) {
        // Prepare a SQL statement to delete the student.
        // We also check 'role = ?' to ensure only 'student' records are deleted via this script.
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
        $stmt->bind_param("i", $student_id);

        if ($stmt->execute()) {
            // Check if any rows were affected (meaning a student was actually deleted)
            if ($stmt->affected_rows > 0) {
                $message_type = 'success';
                $message = 'Student with ID #' . htmlspecialchars($student_id) . ' has been successfully deleted.';
            } else {
                $message_type = 'warning'; // Use warning if student not found or not a student role
                $message = 'Student with ID #' . htmlspecialchars($student_id) . ' not found or is not a student record.';
            }
        } else {
            $message = 'Error deleting student: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Invalid student ID provided.';
    }
} else {
    $message = 'No student ID specified for deletion.';
}

// Close the database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// Redirect back to the student dashboard with a message
// urlencode is used to properly format the message for URL parameter
header('Location: student_dashboard.php?message_type=' . urlencode($message_type) . '&message=' . urlencode($message));
exit;
?>
