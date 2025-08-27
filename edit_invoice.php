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
$active_page = 'invoicing';
$invoice_record = null;
$error_message = '';
$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($invoice_id > 0) {
    $stmt = $conn->prepare("SELECT inv.id, inv.student_id, u.username AS student_username, u.acca_id, inv.invoice_number, inv.issue_date, inv.due_date, inv.total_amount, inv.fee_type, inv.status, inv.paid_date FROM invoices inv JOIN users u ON inv.student_id = u.id WHERE inv.id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $invoice_record = $result->fetch_assoc();
    } else {
        $error_message = "Invoice record not found.";
    }
    $stmt->close();
} else {
    header('Location: invoicing.php?message_type=error&message=No Invoice ID provided.');
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $invoice_record) {
    $updated_due_date_raw = trim($_POST['due_date']);
    $updated_total_amount = (float)$_POST['total_amount'];
    $updated_fee_type = trim($_POST['fee_type']);
    $updated_status = $_POST['status'];
    
    if ($invoice_record['status'] === 'Paid' && $updated_status !== 'Paid' && $_SESSION['role'] !== 'super_admin') {
        $error_message = "Permission Denied: Only a Super Admin can change the status of a paid invoice.";
    }

    if ($updated_total_amount <= 0) {
        $error_message = "Total amount must be a positive number.";
    } else {
        $dateTimeDue = DateTime::createFromFormat('Y-m-d', $updated_due_date_raw);
        if (!$dateTimeDue || $dateTimeDue->format('Y-m-d') !== $updated_due_date_raw) {
            $error_message = "Invalid due date format. Please use YYYY-MM-DD.";
        } else {
            $due_date_formatted = $dateTimeDue->format('Y-m-d');
        }
    }
    
    if (empty($error_message)) {
        $conn->begin_transaction();
        try {
            $sql_update_invoice = "UPDATE invoices SET due_date = ?, total_amount = ?, fee_type = ?, status = ?, paid_date = " . ($updated_status === 'Paid' ? "CURDATE()" : "NULL") . " WHERE id = ?";
            $stmt_update_invoice = $conn->prepare($sql_update_invoice);
            $stmt_update_invoice->bind_param("sdssi", $due_date_formatted, $updated_total_amount, $updated_fee_type, $updated_status, $invoice_id);
            $stmt_update_invoice->execute();
            $stmt_update_invoice->close();

            $fee_status_map = ['Paid' => 'Paid', 'Pending' => 'Pending', 'Overdue' => 'Overdue', 'Cancelled' => 'Waived'];
            $fee_status = $fee_status_map[$updated_status] ?? 'Pending';
            
            $sql_update_fee = "UPDATE fees SET amount = ?, due_date = ?, payment_status = ?, fee_type = ?, paid_date = " . ($fee_status === 'Paid' ? "CURDATE()" : "NULL") . " WHERE invoice_id = ?";
            $stmt_update_fee = $conn->prepare($sql_update_fee);

            // --- BIND_PARAM FIX: Corrected the type string from 'dsss' to 'dssss' ---
            $stmt_update_fee->bind_param("dssss", $updated_total_amount, $due_date_formatted, $fee_status, $updated_fee_type, $invoice_record['invoice_number']);
            
            $stmt_update_fee->execute();
            $stmt_update_fee->close();
            
            $conn->commit();
            header('Location: invoicing.php?message_type=success&message=' . urlencode('Invoice and associated fee updated successfully!'));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Transaction failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Invoice - PSB Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4F46E5',
                        secondary: '#0EA5E9',
                        dark: '#1E293B',
                        light: '#F8FAFC'
                    }
                }
            }
        }
    </script>
    <style>
        /* Ensure body and html take full height for fixed positioning */
        html, body {
            height: 100%;
        }
        body {
            background-color: #F8FAFC;
            display: flex; /* Use flexbox for overall layout */
            flex-direction: column; /* Stack header, content, and mobile nav vertically */
        }
        ::-webkit-scrollbar { width: 8px; } ::-webkit-scrollbar-track { background: #f1f1f1; } ::-webkit-scrollbar-thumb { background: #c5c5c5; border-radius: 4px; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } } .fade-in { animation: fadeIn 0.3s ease-out forwards; }
        .active-tab { background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%); border-left: 4px solid #4F46E5; color: #4F46E5; }
        .shadow-custom { box-shadow: 0 4px 20px rgba(0,0,0,0.08); } .sidebar-link:hover { background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%); }

        /* Mobile adjustments for padding and form layout */
        @media (max-width: 767px) {
            main {
                padding-left: 1rem; /* Consistent padding */
                padding-right: 1rem; /* Consistent padding */
                flex-grow: 1; /* Allow main to grow to fill space */
                /* Padding-top for sticky header */
                padding-top: 80px; /* Adjust based on your header's actual height */
                /* Padding-bottom to ensure content is above fixed mobile nav */
                padding-bottom: 100px; /* Adjust based on mobile nav height */
            }
            .flex-col.sm\:flex-row {
                flex-direction: column;
                align-items: stretch; /* Stretch items to full width */
            }
            .w-full.sm\:w-auto {
                width: 100%; /* Ensure buttons/inputs take full width */
            }
            /* Add margin for spacing between stacked elements in the top row */
            .flex-col.sm\:flex-row.justify-between.items-start.sm\:items-center > * {
                margin-top: 1rem;
            }
            .flex-col.sm\:flex-row.justify-between.items-start.sm\:items-center > *:first-child {
                margin-top: 0; /* No top margin for the first element */
            }
            /* Adjust grid layouts to stack on small screens */
            .grid.grid-cols-1.md\:grid-cols-2.gap-6 {
                grid-template-columns: 1fr;
            }
            /* Ensure submit button is full width */
            button[type="submit"] {
                width: 100%;
            }
        }

        /* Desktop specific padding for main */
        @media (min-width: 768px) {
            main {
                padding-top: 1.5rem; /* Default p-6 for desktop */
                padding-bottom: 1.5rem; /* Default p-6 for desktop */
            }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen">
    <?php require_once 'header.php'; ?>
    <div class="flex flex-1">
        <?php require_once 'sidebar.php'; ?>
        <main class="flex-1 p-4 sm:p-6 pb-24 md:pb-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Edit Invoice Record</h2>
                    <p class="text-gray-500 mt-1">Update details for Invoice: #<?= htmlspecialchars($invoice_record['invoice_number'] ?? 'N/A'); ?></p>
                </div>
                <a href="invoicing.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Invoices
                </a>
            </div>
            <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 max-w-4xl mx-auto fade-in">
                <?php if ($invoice_record): ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert">
                            <p class="font-bold">Error!</p>
                            <p><?= $error_message; ?></p>
                        </div>
                    <?php endif; ?>
                    <form action="edit_invoice.php?id=<?= htmlspecialchars($invoice_id); ?>" method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Student</label>
                                <input type="text" disabled value="<?= htmlspecialchars($invoice_record['student_username'] . ' (' . ($invoice_record['acca_id'] ?? 'N/A') . ')'); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm bg-gray-100 cursor-not-allowed">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Invoice Number</label>
                                <input type="text" disabled value="<?= htmlspecialchars($invoice_record['invoice_number']); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm bg-gray-100 cursor-not-allowed">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Issue Date</label>
                                <input type="date" disabled value="<?= htmlspecialchars($invoice_record['issue_date']); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm bg-gray-100 cursor-not-allowed">
                            </div>
                            <div>
                                <label for="due_date" class="block text-sm font-medium text-gray-700">Due Date</label>
                                <input type="date" id="due_date" name="due_date" required value="<?= htmlspecialchars($invoice_record['due_date']); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="fee_type" class="block text-sm font-medium text-gray-700">Fee Type</label>
                                <select id="fee_type" name="fee_type" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                    <?php $allowed_fee_types = ['tuition', 'admission', 'fine', 'exam fees', 'event fees', 'other']; ?>
                                    <?php foreach ($allowed_fee_types as $type): ?>
                                        <option value="<?= htmlspecialchars($type); ?>" <?= ($invoice_record['fee_type'] == $type) ? 'selected' : ''; ?>>
                                            <?= ucwords(str_replace('_', ' ', $type)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="total_amount" class="block text-sm font-medium text-gray-700">Total Amount (BDT)</label>
                                <input type="number" id="total_amount" name="total_amount" step="0.01" min="0.01" required value="<?= htmlspecialchars($invoice_record['total_amount']); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                            </div>
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Invoice Status</label>
                            <select id="status" name="status" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <?php $allowed_invoice_statuses = ['Pending', 'Paid', 'Overdue', 'Cancelled']; ?>
                                <?php foreach ($allowed_invoice_statuses as $status_option): ?>
                                    <option value="<?= htmlspecialchars($status_option); ?>" <?= ($invoice_record['status'] == $status_option) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($status_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full flex justify-center items-center bg-primary hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-md transition ease-in-out duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            <i class="fas fa-save mr-2"></i>Update Invoice
                        </button>
                    </form>
                <?php else: ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                        <p class="font-bold">Error!</p>
                        <p><?= htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Dropdown Menu Script (for header.php) ---
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');
            if (userMenuButton) { userMenuButton.addEventListener('click', (e) => { e.stopPropagation(); userMenu.classList.toggle('hidden'); }); }
            document.addEventListener('click', (e) => { if (userMenu && !userMenu.classList.contains('hidden') && !userMenuButton.contains(e.target)) { userMenu.classList.add('hidden'); } });

            // --- Mobile Menu Toggle Script (Crucial for sidebar visibility on mobile) ---
            const mobileMoreBtn = document.getElementById('mobile-more-btn');
            const mobileMoreMenu = document.getElementById('mobile-more-menu');
            if(mobileMoreBtn){
                mobileMoreBtn.addEventListener('click', (e) => {
                    e.preventDefault(); // Prevent default link behavior
                    mobileMoreMenu.classList.toggle('hidden');
                });
            }
            // Close the mobile menu if clicked outside
            document.addEventListener('click', (e) => {
                if (mobileMoreMenu && !mobileMoreMenu.classList.contains('hidden') && !mobileMoreBtn.contains(e.target) && !mobileMoreMenu.contains(e.target)) {
                    mobileMoreMenu.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>