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

// Check if a fee ID was provided via GET request
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $fee_id = (int)$_GET['id'];

    if ($fee_id > 0) {
        $conn->begin_transaction();
        try {
            // --- SYNC FIX: START ---
            // Step 1: Find the invoice_id associated with the fee before deleting it.
            $stmt_select = $conn->prepare("SELECT invoice_id FROM fees WHERE id = ?");
            $stmt_select->bind_param("i", $fee_id);
            $stmt_select->execute();
            $result = $stmt_select->get_result();
            $fee_data = $result->fetch_assoc();
            $stmt_select->close();
            
            $invoice_id_to_delete = $fee_data ? $fee_data['invoice_id'] : null;

            // Step 2: Delete the fee record.
            $stmt_delete_fee = $conn->prepare("DELETE FROM fees WHERE id = ?");
            $stmt_delete_fee->bind_param("i", $fee_id);
            $stmt_delete_fee->execute();
            $affected_rows = $stmt_delete_fee->affected_rows;
            $stmt_delete_fee->close();
            
            if ($affected_rows > 0) {
                // Step 3: If the fee was deleted and had an associated invoice, delete the invoice too.
                if ($invoice_id_to_delete) {
                    $stmt_delete_invoice = $conn->prepare("DELETE FROM invoices WHERE invoice_number = ?");
                    $stmt_delete_invoice->bind_param("s", $invoice_id_to_delete);
                    $stmt_delete_invoice->execute();
                    $stmt_delete_invoice->close();
                }
                
                $conn->commit();
                $message_type = 'success';
                $message = 'Fee record #' . htmlspecialchars($fee_id) . ' and its associated invoice have been successfully deleted.';
            // --- SYNC FIX: END ---
            } else {
                $conn->rollback();
                $message_type = 'warning';
                $message = 'Fee record #' . htmlspecialchars($fee_id) . ' not found.';
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error during transaction: ' . $e->getMessage();
        }
    } else {
        $message = 'Invalid fee ID provided.';
    }
} else {
    $message = 'No fee ID specified for deletion.';
}

// Close the database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// Redirect back to the fees dashboard with a message
header('Location: fees.php?message_type=' . urlencode($message_type) . '&message=' . urlencode($message));
exit;
?>