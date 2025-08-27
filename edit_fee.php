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
$active_page = 'fees';
$fee_record = null;
$error_message = '';
$fee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($fee_id > 0) {
    $stmt = $conn->prepare("SELECT f.id, f.student_id, u.username AS student_username, u.acca_id, f.fee_type, f.amount, f.due_date, f.payment_status, f.paid_date, f.original_amount, f.discount_applied, f.invoice_id FROM fees f JOIN users u ON f.student_id = u.id WHERE f.id = ?");
    $stmt->bind_param("i", $fee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $fee_record = $result->fetch_assoc();
    } else {
        $error_message = "Fee record not found.";
    }
    $stmt->close();
} else {
    header('Location: fees.php?message_type=error&message=No Fee ID provided.');
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $fee_record) {
    $updated_fee_type = trim($_POST['fee_type']);
    $updated_amount = (float)$_POST['amount'];
    $updated_discount = (float)$_POST['discount'];
    $updated_due_date = trim($_POST['due_date']);
    $updated_payment_status = $_POST['payment_status'];
    $final_amount = $updated_amount - $updated_discount;

    if ($fee_record['payment_status'] === 'Paid' && $updated_payment_status !== 'Paid' && $_SESSION['role'] !== 'super_admin') {
        $error_message = "Permission Denied: Only a Super Admin can change the status of a paid fee record.";
    }
    if ($final_amount < 0) {
        $error_message = "Final amount cannot be negative.";
    }
    
    if (empty($error_message)) {
        $conn->begin_transaction();
        try {
            $sql_update_fee = "UPDATE fees SET fee_type = ?, original_amount = ?, discount_applied = ?, amount = ?, due_date = ?, payment_status = ?, paid_date = " . ($updated_payment_status === 'Paid' ? "CURDATE()" : "NULL") . " WHERE id = ?";
            $stmt_update_fee = $conn->prepare($sql_update_fee);

            // --- BIND_PARAM FIX: Corrected the type string from 'sddsssi' to 'sdddssi' ---
            $stmt_update_fee->bind_param("sdddssi", $updated_fee_type, $updated_amount, $updated_discount, $final_amount, $updated_due_date, $updated_payment_status, $fee_id);
            
            $stmt_update_fee->execute();
            $stmt_update_fee->close();
            
            if ($fee_record['invoice_id']) {
                $invoice_status_map = ['Paid' => 'Paid', 'Pending' => 'Pending', 'Overdue' => 'Overdue', 'Waived' => 'Cancelled'];
                $invoice_status = $invoice_status_map[$updated_payment_status] ?? 'Pending';
                
                $sql_update_invoice = "UPDATE invoices SET total_amount = ?, due_date = ?, status = ?, fee_type = ?, paid_date = " . ($invoice_status === 'Paid' ? "CURDATE()" : "NULL") . " WHERE invoice_number = ?";
                $stmt_update_invoice = $conn->prepare($sql_update_invoice);
                $stmt_update_invoice->bind_param("dssss", $final_amount, $updated_due_date, $invoice_status, $updated_fee_type, $fee_record['invoice_id']);
                $stmt_update_invoice->execute();
                $stmt_update_invoice->close();
            }
            
            $conn->commit();
            header('Location: fees.php?message_type=success&message=' . urlencode('Fee record updated successfully!'));
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
    <title>Edit Fee - PSB Admin</title>
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
                align-items: stretch;
            }
            .w-full.sm\:w-auto {
                width: 100%;
            }
            /* Adjust grid layouts to stack on small screens */
            .grid.grid-cols-1.md\:grid-cols-2.gap-6,
            .grid.grid-cols-1.md\:grid-cols-3.gap-6 {
                grid-template-columns: 1fr;
            }
            /* Ensure the action button is full width on mobile */
            .w-full.flex.justify-center.items-center {
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
                    <h2 class="text-2xl font-bold text-gray-800">Edit Fee Record</h2>
                    <p class="text-gray-500 mt-1">Update details for Fee ID: #F<?= htmlspecialchars($fee_id); ?></p>
                </div>
                <a href="fees.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Fees
                </a>
            </div>
            <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 max-w-4xl mx-auto fade-in">
                <?php if ($fee_record): ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                            <p class="font-bold">Error:</p>
                            <p><?= $error_message; ?></p>
                        </div>
                    <?php endif; ?>
                    <form action="edit_fee.php?id=<?= htmlspecialchars($fee_id); ?>" method="POST" class="space-y-6">
                        <div>
                            <label for="student_name" class="block text-sm font-medium text-gray-700">Student</label>
                            <input type="text" id="student_name" name="student_name" disabled value="<?= htmlspecialchars($fee_record['student_username'] . ' (' . ($fee_record['acca_id'] ?? 'N/A') . ')'); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm bg-gray-100 cursor-not-allowed">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div>
                                <label for="fee_type" class="block text-sm font-medium text-gray-700">Fee Type</label>
                                <select id="fee_type" name="fee_type" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                    <?php $allowed_fee_types = ['tuition', 'admission', 'fine', 'exam fees', 'event fees', 'other']; ?>
                                    <?php foreach ($allowed_fee_types as $type): ?>
                                        <option value="<?= htmlspecialchars($type); ?>" <?= ($fee_record['fee_type'] == $type) ? 'selected' : ''; ?>>
                                            <?= ucwords(str_replace('_', ' ', $type)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="due_date" class="block text-sm font-medium text-gray-700">Due Date</label>
                                <input type="date" id="due_date" name="due_date" required value="<?= htmlspecialchars($fee_record['due_date']); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                            </div>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
                            <div>
                                <label for="amount" class="block text-sm font-medium text-gray-700">Original Amount (BDT)</label>
                                <input type="number" id="amount" name="amount" step="0.01" min="0" required value="<?= htmlspecialchars($fee_record['original_amount'] ?? $fee_record['amount']); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                            </div>
                             <div>
                                <label for="discount" class="block text-sm font-medium text-gray-700">Discount (BDT)</label>
                                <input type="number" id="discount" name="discount" step="0.01" min="0" value="<?= htmlspecialchars($fee_record['discount_applied'] ?? '0.00'); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                            </div>
                             <div>
                                <label class="block text-sm font-medium text-gray-700">Final Amount (BDT)</label>
                                <p id="final_amount_display" class="mt-1 text-2xl font-bold text-green-600">
                                    <?= number_format(($fee_record['original_amount'] ?? $fee_record['amount']) - ($fee_record['discount_applied'] ?? 0), 2); ?>
                                </p>
                            </div>
                        </div>
                        <div>
                            <label for="payment_status" class="block text-sm font-medium text-gray-700">Payment Status</label>
                            <select id="payment_status" name="payment_status" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <?php $allowed_payment_statuses = ['Pending', 'Paid', 'Overdue', 'Waived']; ?>
                                <?php foreach ($allowed_payment_statuses as $status): ?>
                                    <option value="<?= htmlspecialchars($status); ?>" <?= ($fee_record['payment_status'] == $status) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full flex justify-center items-center bg-primary hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-md transition ease-in-out duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            <i class="fas fa-save mr-2"></i>Update Fee Record
                        </button>
                    </form>
                <?php else: ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                        <p class="font-bold">Error!</p>
                        <p><?= $error_message; ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const amountInput = document.getElementById('amount');
            const discountInput = document.getElementById('discount');
            const finalAmountDisplay = document.getElementById('final_amount_display');
            function calculateFinalAmount() {
                const amount = parseFloat(amountInput.value) || 0;
                let discount = parseFloat(discountInput.value) || 0;
                if (discount > amount) { discount = amount; discountInput.value = amount.toFixed(2); }
                const finalAmount = amount - discount;
                finalAmountDisplay.textContent = finalAmount.toFixed(2);
            }
            amountInput.addEventListener('input', calculateFinalAmount);
            discountInput.addEventListener('input', calculateFinalAmount);

            // --- Dropdown Menu Script (for header.php) ---
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');

            if (userMenuButton && userMenu) {
                userMenuButton.addEventListener('click', function() {
                    userMenu.classList.toggle('hidden');
                });

                // Close the dropdown if clicked outside
                document.addEventListener('click', function(event) {
                    if (!userMenuButton.contains(event.target) && !userMenu.contains(event.target)) {
                        userMenu.classList.add('hidden');
                    }
                });

                // Optional: Close with Escape key
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        userMenu.classList.add('hidden');
                    }
                });
            }

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