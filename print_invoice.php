<?php
session_start();

// Enable error reporting for debugging.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

// --- Database Connection Validation ---
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    die("Error: Database connection could not be established.");
}

// --- Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Access Denied.");
}

$invoice = null;
$fee_items = []; // To store individual fee items for this invoice
$error_message = '';

// Crucially, fetch by invoice_number, not invoice primary ID
// The URL should now pass the invoice_number (e.g., print_invoice.php?invoice_num=INV-2025...)
$invoice_number_param = isset($_GET['invoice_num']) ? trim($_GET['invoice_num']) : '';

if (!empty($invoice_number_param)) {
    // 1. Fetch main invoice details along with student details using invoice_number
    $stmt = $conn->prepare("
        SELECT 
            inv.id, inv.student_id, inv.customer_name, u.email AS student_email,
            u.acca_id AS student_acca_id, inv.invoice_number, inv.issue_date, 
            inv.due_date, inv.total_amount, inv.fee_type, inv.subject AS invoice_subject_summary, inv.status,
            inv.paid_date, inv.created_at, inv.updated_at
        FROM invoices inv
        LEFT JOIN users u ON inv.student_id = u.id -- Use LEFT JOIN in case student_id is null for some reason
        WHERE inv.invoice_number = ?
    ");
    $stmt->bind_param("s", $invoice_number_param);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $invoice = $result->fetch_assoc();

        // If customer_name is null from invoice, try to get from users (if student_id exists)
        if (empty($invoice['customer_name']) && !empty($invoice['student_username'])) {
            $invoice['customer_name'] = $invoice['student_username'];
        }

        // 2. Fetch all individual fee items associated with this invoice_number
        $stmt_fees = $conn->prepare("
            SELECT 
                fee.fee_type, fee.subject, fee.amount, fee.original_amount, fee.discount_applied
            FROM fees fee
            WHERE fee.invoice_id = ?
        ");
        $stmt_fees->bind_param("s", $invoice_number_param);
        $stmt_fees->execute();
        $fees_result = $stmt_fees->get_result();
        while ($row = $fees_result->fetch_assoc()) {
            $fee_items[] = $row;
        }
        $stmt_fees->close();

    } else {
        $error_message = "Invoice not found or multiple invoices with the same number (should not happen for unique invoice_number).";
    }
    $stmt->close();
} else {
    $error_message = "No invoice number provided.";
}

// Calculate subtotal and total discount from fee_items for display coherence
$calculated_subtotal = 0;
foreach ($fee_items as $item) {
    $calculated_subtotal += $item['original_amount']; // Sum original amounts of individual fees
}
// The total discount is stored in the main invoice record's total_amount.
$total_discount_for_display = $calculated_subtotal - ($invoice['total_amount'] ?? 0);
if ($total_discount_for_display < 0) $total_discount_for_display = 0; // Avoid negative if amounts are off or invoice total is greater due to error

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Invoice #<?= htmlspecialchars($invoice['invoice_number'] ?? 'N/A'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base styles for the invoice */
        body {
            background-color: #F3F4F6; /* bg-gray-100 */
        }
        .invoice-container {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            padding: 2.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        .invoice-table th, .invoice-table td { 
            padding: 0.75rem; 
            text-align: left; 
            border-bottom: 1px solid #E5E7EB; /* border-gray-200 */
        }
        .invoice-table th { 
            background-color: #F9FAFB; /* bg-gray-50 */
            font-weight: 600; 
            color: #374151; /* text-gray-700 */
        }
        /* Styles for printing */
        @media print {
            body {
                background-color: white;
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none;
            }
            .invoice-container {
                box-shadow: none;
                border: none;
                margin: 0;
                padding: 0;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

    <?php if ($invoice): ?>
    <div class="invoice-container">
        <div class="no-print mb-6 flex justify-end gap-x-2">
             <button onclick="window.print()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-print mr-2"></i> Print
            </button>
            <button onclick="window.close()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                 <i class="fas fa-times-circle mr-2"></i> Close
            </button>
        </div>

        <div class="flex justify-between items-start mb-8 border-b pb-6">
            <div>
                <img src="PSB_LOGO.png" alt="PSB Logo" class="h-12 mb-4">
                <p class="font-semibold text-gray-800">Professional School of Business</p>
                <p class="text-sm text-gray-500">123 Learning Street, Dhaka, Bangladesh</p>
                <p class="text-sm text-gray-500">contact@psb.com</p>
                <p class="text-sm text-gray-500">+880 123 456 7890</p>
            </div>
            <div class="text-right">
                <h2 class="text-2xl font-bold text-gray-800">INVOICE</h2>
                <p class="text-gray-600">#<?= htmlspecialchars($invoice['invoice_number']); ?></p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-8 mb-10">
            <div>
                <p class="text-sm font-semibold text-gray-500">BILLED TO</p>
                <p class="font-bold text-gray-800 mt-1"><?= htmlspecialchars($invoice['customer_name']); ?></p>
                <p class="text-gray-600">ACCA ID: <?= htmlspecialchars($invoice['student_acca_id'] ?? 'N/A'); ?></p>
                <p class="text-gray-600"><?= htmlspecialchars($invoice['student_email']); ?></p>
            </div>
            <div class="text-right">
                <p><span class="font-semibold text-gray-600">Issue Date:</span> <?= date('M d, Y', strtotime($invoice['issue_date'])); ?></p>
                <p><span class="font-semibold text-gray-600">Due Date:</span> <?= date('M d, Y', strtotime($invoice['due_date'])); ?></p>
            </div>
        </div>

        <table class="w-full invoice-table mb-10">
            <thead>
                <tr>
                    <th class="px-4 py-2">Description</th>
                    <th class="px-4 py-2 text-right">Unit Price</th>
                    <th class="px-4 py-2 text-right">Discount</th>
                    <th class="px-4 py-2 text-right">Amount (BDT)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($fee_items)): ?>
                    <?php foreach ($fee_items as $item): ?>
                        <tr>
                            <td class="px-4 py-3">
                                <?= ucwords(htmlspecialchars($item['fee_type'])); ?>
                                <?php if (!empty($item['subject'])): ?>
                                    <span class="block text-xs text-gray-500">- Subject: <?= htmlspecialchars($item['subject']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-right"><?= number_format($item['original_amount'], 2); ?></td>
                            <td class="px-4 py-3 text-right"><?= number_format($item['discount_applied'], 2); ?></td>
                            <td class="px-4 py-3 text-right"><?= number_format($item['amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-center text-gray-500">No fee items found for this invoice.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="flex justify-end mb-8">
            <div class="w-full max-w-xs text-gray-700">
                <div class="flex justify-between py-2">
                    <span>Subtotal</span>
                    <span>BDT <?= number_format($calculated_subtotal, 2); ?></span>
                </div>
                <div class="flex justify-between py-2">
                    <span>Total Item Discount</span>
                    <span>BDT 0.00</span> </div>
                <?php if (($invoice['total_amount'] < $calculated_subtotal) && ($total_discount_for_display > 0)): // Show overall transaction discount only if applied ?>
                    <div class="flex justify-between py-2 font-semibold text-red-600">
                        <span>Transaction Discount</span>
                        <span>- BDT <?= number_format($total_discount_for_display, 2); ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between py-2 border-t">
                    <span class="font-bold text-lg">Total Due</span>
                    <span class="font-bold text-lg">BDT <?= number_format($invoice['total_amount'], 2); ?></span>
                </div>
            </div>
        </div>

        <div class="text-center border-t pt-6">
            <p class="text-lg font-bold mb-2">Status: 
                <span class="px-3 py-1 rounded-full text-sm
                    <?php 
                        if ($invoice['status'] == 'Paid') echo 'bg-green-100 text-green-800';
                        elseif ($invoice['status'] == 'Overdue') echo 'bg-red-100 text-red-800';
                        else echo 'bg-yellow-100 text-yellow-800';
                    ?>">
                    <?= htmlspecialchars($invoice['status']); ?>
                </span>
            </p>
            <p class="text-sm text-gray-500">Thank you for your business!</p>
        </div>

    </div>
    <?php else: ?>
        <div class="max-w-md mx-auto mt-10 bg-white rounded-xl shadow-custom p-8 text-center">
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                <p class="font-bold">Error!</p>
                <p><?= htmlspecialchars($error_message); ?></p>
            </div>
        </div>
    <?php endif; ?>
    
    <script>
        // This script runs after the page content is loaded
        window.onload = function() {
            // It automatically triggers the browser's print function
            window.print();
        };
    </script>
</body>
</html>