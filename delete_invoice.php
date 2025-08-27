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

// --- Super Admin Password Configuration ---
// Note: This is NOT secure. In a real application, use hashed passwords from the database.
$SUPER_ADMIN_PASSWORD = 'quickbook123'; // Define the super admin password

// Ensure the request is a POST request, and contains the required password
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['super_admin_password']) && $_POST['super_admin_password'] === $SUPER_ADMIN_PASSWORD) {
        if (isset($_POST['invoice_id']) && !empty($_POST['invoice_id'])) {
            $invoice_id = (int)$_POST['invoice_id'];

            if ($invoice_id > 0) {
                $conn->begin_transaction();
                try {
                    // --- SYNC FIX: START ---
                    // Step 1: Get the invoice_number before deleting the invoice record.
                    $stmt_select = $conn->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
                    $stmt_select->bind_param("i", $invoice_id);
                    $stmt_select->execute();
                    $result = $stmt_select->get_result();
                    $invoice_data = $result->fetch_assoc();
                    $stmt_select->close();

                    $invoice_number_to_delete = $invoice_data ? $invoice_data['invoice_number'] : null;

                    // Step 2: Delete the invoice record.
                    $stmt_delete_invoice = $conn->prepare("DELETE FROM invoices WHERE id = ?");
                    $stmt_delete_invoice->bind_param("i", $invoice_id);
                    $stmt_delete_invoice->execute();
                    $affected_rows = $stmt_delete_invoice->affected_rows;
                    $stmt_delete_invoice->close();

                    if ($affected_rows > 0) {
                        // Step 3: If the invoice was deleted and had an associated fee, delete the fee too.
                        if ($invoice_number_to_delete) {
                            $stmt_delete_fee = $conn->prepare("DELETE FROM fees WHERE invoice_id = ?");
                            $stmt_delete_fee->bind_param("s", $invoice_number_to_delete);
                            $stmt_delete_fee->execute();
                            $stmt_delete_fee->close();
                        }
                        
                        $conn->commit();
                        $message_type = 'success';
                        $message = 'Invoice #' . htmlspecialchars($invoice_id) . ' and its associated fee have been successfully deleted.';
                    // --- SYNC FIX: END ---
                    } else {
                        $conn->rollback();
                        $message_type = 'warning';
                        $message = 'Invoice record with ID #' . htmlspecialchars($invoice_id) . ' not found.';
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = 'Error during transaction: ' . $e->getMessage();
                }
            } else {
                $message = 'Invalid invoice ID provided.';
            }
        } else {
            $message = 'No invoice ID specified for deletion.';
        }
    } else {
        $message = 'Incorrect super admin password. Deletion denied.';
        $message_type = 'error';
    }
} else {
    $message = 'Deletion must be performed via a secure POST request with a valid password.';
    $message_type = 'error';
}

// Close the database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// Redirect back to the invoicing dashboard with a message
header('Location: invoicing.php?message_type=' . urlencode($message_type) . '&message=' . urlencode($message));
exit;
?>