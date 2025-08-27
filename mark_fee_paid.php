<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';

if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    die("Error: Database connection could not be established.");
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

$message_type = 'error';
$message = 'An unexpected error occurred.';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $fee_id = (int)$_GET['id'];

    if ($fee_id > 0) {
        $conn->begin_transaction();
        try {
            $stmt_select = $conn->prepare("SELECT invoice_id FROM fees WHERE id = ?");
            $stmt_select->bind_param("i", $fee_id);
            $stmt_select->execute();
            $result = $stmt_select->get_result();
            $fee_data = $result->fetch_assoc();
            $stmt_select->close();
            $invoice_id_to_update = $fee_data ? $fee_data['invoice_id'] : null;

            // --- TIMEZONE FIX: START ---
            $stmt_update_fee = $conn->prepare("UPDATE fees SET payment_status = 'Paid', paid_date = CURDATE() WHERE id = ? AND (payment_status = 'Pending' OR payment_status = 'Overdue')");
            $stmt_update_fee->bind_param("i", $fee_id);
            // --- TIMEZONE FIX: END ---
            $stmt_update_fee->execute();
            $affected_rows = $stmt_update_fee->affected_rows;
            $stmt_update_fee->close();

            if ($affected_rows > 0) {
                if ($invoice_id_to_update) {
                    // --- TIMEZONE FIX: START ---
                    $stmt_update_invoice = $conn->prepare("UPDATE invoices SET status = 'Paid', paid_date = CURDATE() WHERE invoice_number = ?");
                    $stmt_update_invoice->bind_param("s", $invoice_id_to_update);
                    // --- TIMEZONE FIX: END ---
                    $stmt_update_invoice->execute();
                    $stmt_update_invoice->close();
                }
                $conn->commit();
                $message_type = 'success';
                $message = 'Fee record #' . htmlspecialchars($fee_id) . ' marked as PAID.';
            } else {
                $conn->rollback();
                $message_type = 'warning';
                $message = 'Fee record could not be changed (might already be paid/waived).';
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error during transaction: ' . $e->getMessage();
        }
    } else {
        $message = 'Invalid fee ID provided.';
    }
} else {
    $message = 'No fee ID specified.';
}

$conn->close();
header('Location: fees.php?message_type=' . urlencode($message_type) . '&message=' . urlencode($message));
exit;
?>