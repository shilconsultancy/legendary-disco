<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';

if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    error_log("Database connection failed in add_fee.php: " . $conn->connect_error);
    header('Location: ../index.php');
    exit;
}
$active_page = 'fees';
$error_message = '';
$students_list = [];
$fee_types_list = [];
$fees_subject_list = []; // To store subject data

// Fetch fee types from database
$result_fee_types = $conn->query("SELECT id, type_name, price, needs_subject, is_custom_amount FROM fee_types ORDER BY type_name ASC");
if ($result_fee_types) {
    while ($row = $result_fee_types->fetch_assoc()) {
        $fee_types_list[] = $row;
    }
} else {
    $error_message = "Error fetching fee types: " . $conn->error;
}

// Fetch fee subjects from database
$result_fees_subject = $conn->query("SELECT id, subject_code, price, category FROM fee_subjects ORDER BY category, subject_code ASC");
if ($result_fees_subject) {
    while ($row = $result_fees_subject->fetch_assoc()) {
        $fees_subject_list[] = $row;
    }
} else {
    $error_message .= (empty($error_message) ? "" : "<br>") . "Error fetching fee subjects: " . $conn->error;
}


$result_students = $conn->query("SELECT id, username, acca_id FROM users WHERE role = 'student' ORDER BY username ASC");
if ($result_students) {
    while ($row = $result_students->fetch_assoc()) {
        $students_list[] = $row;
    }
} else {
    $error_message .= (empty($error_message) ? "" : "<br>") . "Error fetching student list: " . $conn->error;
}


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $student_id = (int)$_POST['student_id'];
    $due_date = trim($_POST['due_date']);
    $payment_status = trim($_POST['payment_status']); // Trim status too
    $discount_applied = (float)($_POST['discount'] ?? 0); // Total discount for the whole transaction
    $cart_items_json = $_POST['cart_items'] ?? '[]';
    $cart_items = json_decode($cart_items_json, true);

    // Get student's username for customer_name fields
    $customer_name = null;
    foreach ($students_list as $student_item) {
        if ($student_item['id'] == $student_id) {
            $customer_name = $student_item['username'];
            break;
        }
    }
    // Default subject to NULL as there's no input field for it in the main form data
    // It will be pulled from $item['subject_code'] within the loop if present.

    if (!is_array($cart_items)) {
        $cart_items = [];
    }

    if (empty($student_id) || empty($due_date) || empty($payment_status) || empty($cart_items)) {
        $error_message = "All required fields must be filled and at least one fee item must be added.";
    } elseif (!in_array($payment_status, ['Pending', 'Paid', 'Overdue', 'Waived'])) {
        $error_message = "Invalid payment status.";
    } elseif (is_null($customer_name)) {
        $error_message = "Selected student not found.";
    }

    $total_original_amount = 0;
    foreach ($cart_items as $item) {
        $total_original_amount += (float)$item['amount'];
    }

    $final_amount_after_discount = $total_original_amount - $discount_applied;

    if ($final_amount_after_discount < 0) {
        $error_message = "Final amount cannot be negative after discount. Please check discount value.";
    }

    // Server-side validation for discount if not super_admin
    if (empty($error_message) && $discount_applied > 0 && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin')) {
         $error_message = "Only Superadmins can apply discounts.";
    } elseif (empty($error_message) && $discount_applied > 0 && (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin')) {
        $superadmin_username = trim($_POST['superadmin_username'] ?? '');
        $superadmin_password = $_POST['superadmin_password'] ?? '';

        if (empty($superadmin_username) || empty($superadmin_password)) {
             $error_message = "Superadmin username and password are required for discounts.";
        } else {
            $stmt_superadmin = $conn->prepare("SELECT password_hash FROM users WHERE username = ? AND role = 'super_admin'");
            if ($stmt_superadmin) {
                $stmt_superadmin->bind_param("s", $superadmin_username);
                $stmt_superadmin->execute();
                $result_superadmin = $stmt_superadmin->get_result();
                $superadmin_user = $result_superadmin->fetch_assoc();
                $stmt_superadmin->close();

                if (!$superadmin_user || !password_verify($superadmin_password, $superadmin_user['password_hash'])) {
                    $error_message = "Invalid Superadmin credentials for discount authorization.";
                }
            } else {
                $error_message = "Database error during superadmin check: " . $conn->error;
            }
        }
    }


    if (empty($error_message)) {
        $conn->begin_transaction();
        try {
            // Determine paid_date for binding (string 'YYYY-MM-DD' or NULL)
            // If payment_status is 'Paid', use current date, otherwise NULL
            $paid_date_for_db = ($payment_status === 'Paid') ? date('Y-m-d') : null; 

            // Generate the human-readable invoice number once (more robust uniqueness with microtime)
            $invoice_display_number = 'INV-' . uniqid(date('YmdHis'), true); 
            $issue_date = date('Y-m-d');
            $invoice_status = ($payment_status === 'Paid') ? 'Paid' : 'Pending';

            // 1. Create a single invoice for all fees in this transaction first
            $sql_invoice = "INSERT INTO invoices (student_id, customer_name, invoice_number, issue_date, due_date, total_amount, fee_type, subject, status, paid_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_invoice = $conn->prepare($sql_invoice);
            
            // Concatenate fee types for the invoice description or use 'Multiple Fees'
            $invoice_fee_type_summary_array = [];
            foreach ($cart_items as $item) {
                if (!empty($item['custom_fee_name'])) {
                    $invoice_fee_type_summary_array[] = $item['custom_fee_name'];
                } else if (!empty($item['subject_code'])) {
                    $invoice_fee_type_summary_array[] = $item['fee_type'] . ' (' . $item['subject_code'] . ')';
                } else {
                    $invoice_fee_type_summary_array[] = $item['fee_type'];
                }
            }
            $invoice_fee_type_summary = implode(', ', $invoice_fee_type_summary_array);
            if (strlen($invoice_fee_type_summary) > 255) { // Ensure it fits DB column
                $invoice_fee_type_summary = 'Multiple Fees';
            }
            
            // For the overall invoice, use a general subject if specific subjects exist, else null
            $invoice_subject_summary = null;
            $has_subjects = false;
            foreach($cart_items as $item) {
                if (!empty($item['subject_code'])) {
                    $has_subjects = true;
                    break;
                }
            }
            if ($has_subjects) {
                 $invoice_subject_summary = 'Multiple Subjects'; // Or concatenate if feasible/desired
            }
            // Bind parameters for the invoice
            // Types: student_id(i), customer_name(s), invoice_number(s), issue_date(s), due_date(s), total_amount(d), fee_type(s), subject(s), status(s), paid_date(s)
            // Total 10 parameters: issssdssss
            $stmt_invoice->bind_param("issssdssss", 
                $student_id, 
                $customer_name, 
                $invoice_display_number, 
                $issue_date, 
                $due_date, 
                $final_amount_after_discount, 
                $invoice_fee_type_summary, 
                $invoice_subject_summary, 
                $invoice_status, 
                $paid_date_for_db
            );
            
            if (!$stmt_invoice->execute()) {
                throw new Exception("Error inserting invoice: " . $stmt_invoice->error);
            }
            // No need to get insert_id for invoice here, we use invoice_display_number for fees.invoice_id
            $stmt_invoice->close();
            
            // 2. Now, insert each fee item and link it to the newly created invoice's invoice_number
            foreach ($cart_items as $item) {
                $fee_type = $item['fee_type'];
                $item_amount = (float)$item['amount']; // This is the amount for THIS item
                $item_subject_code = $item['subject_code'] ?? null; // Get subject_code if present
                $custom_fee_name = $item['custom_fee_name'] ?? null; // Get custom fee name if present

                // If it's a custom amount fee and has a custom name, prepend it to fee_type for clarity in records
                // Or if it's an 'exam fees' item with a custom price
                if ($fee_type === 'other' && !empty($custom_fee_name)) {
                    $fee_type_for_db = 'other (' . $custom_fee_name . ')';
                } else if ($fee_type === 'exam fees' && !empty($item_subject_code)) { // NEW: Exam fees with subject details
                     $fee_type_for_db = 'exam fees (' . $item_subject_code . ')';
                }
                else {
                    $fee_type_for_db = $fee_type;
                }

                // Individual fee item discount is 0 if the discount is handled at the total invoice level.
                $individual_item_discount = 0.00; 
                
                // All values are placeholders for the fee item.
                $sql_fee = "INSERT INTO fees (student_id, customer_name, fee_type, subject, amount, original_amount, discount_applied, due_date, payment_status, paid_date, invoice_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_fee = $conn->prepare($sql_fee);
                
                // Corrected Type string: student_id(i), customer_name(s), fee_type(s), subject(s), amount(d), original_amount(d), discount_applied(d), due_date(s), payment_status(s), paid_date(s), invoice_id(s)
                // Total 11 parameters: isssdddssss
                $stmt_fee->bind_param("isssdddssss", 
                    $student_id, 
                    $customer_name, 
                    $fee_type_for_db, // Use the potentially modified fee_type for DB display
                    $item_subject_code, // subject for fee item (still useful for filtering/reporting)
                    $item_amount, // `amount` in fees is the item's final amount (after item-level discount if any, here assumed 0)
                    $item_amount, // `original_amount` for this item (before any item-level discount)
                    $individual_item_discount, // Corrected to 'd'
                    $due_date, // Corrected to 's'
                    $payment_status, 
                    $paid_date_for_db, 
                    $invoice_display_number // Link by invoice_number (varchar)
                );
                
                if (!$stmt_fee->execute()) {
                    throw new Exception("Error inserting fee: " . $stmt_fee->error . " for fee type: " . $fee_type);
                }
                $stmt_fee->close();
            }
            
            // If all inserts are successful, commit the transaction
            $conn->commit();
            header('Location: fees.php?message_type=success&message=' . urlencode('Transaction processed successfully and Invoice generated: ' . $invoice_display_number));
            exit;
        } catch (Exception $e) {
            // If any error occurs, rollback the transaction
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
    <title>Add New Fee - PSB Admin</title>
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

        /* POS-like specific styles */
        .pos-section-title {
            @apply text-lg font-semibold text-gray-700 mb-4 pb-2 border-b border-gray-200;
        }
        .fee-card {
            @apply bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-center justify-between text-blue-800;
        }
        .pos-total-display {
            @apply bg-gray-800 text-white p-6 rounded-lg text-right;
        }
        .fee-type-btn.selected {
            @apply ring-2 ring-offset-2 ring-primary border-primary bg-primary text-white;
        }
        /* Modal styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Darker overlay */
            justify-content: center; /* Center horizontally */
            align-items: center; /* Center vertically */
            backdrop-filter: blur(8px); /* Stronger blur effect */
            animation: fadeIn 0.3s ease-out forwards; /* Fade in animation */
        }
        .modal-content {
            background-color: #fefefe;
            padding: 2rem;
            border-radius: 0.75rem; /* More rounded corners */
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 8px 10px -6px rgba(0, 0, 0, 0.1); /* Stronger shadow */
            width: 90%;
            max-width: 650px; /* Slightly wider */
            position: relative;
            animation: slideIn 0.3s ease-out forwards;
            display: flex;
            flex-direction: column;
            max-height: 90vh; /* Limit modal height */
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .close-button {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 30px; /* Larger close button */
            cursor: pointer;
            color: #9CA3AF; /* text-gray-400 */
            transition: color 0.2s ease-in-out;
        }
        .close-button:hover {
            color: #4B5563; /* text-gray-600 */
        }
        .subject-grid-container {
            flex-grow: 1; /* Allow subject grid to fill space */
            overflow-y: auto; /* Scrollable content */
            padding-right: 1rem; /* Space for scrollbar */
            margin-bottom: 1.5rem; /* Space before summary */
        }
        /* NEW: Subject List Styling */
        .subject-list {
            display: flex;
            flex-direction: column;
            gap: 8px; /* Spacing between list items */
        }
        .subject-list-item {
            @apply p-3 border border-gray-200 rounded-lg cursor-pointer bg-white;
            @apply shadow-sm transition-all duration-200 ease-in-out;
            display: flex;
            align-items: center;
            justify-content: space-between; /* Space out left and right content */
            width: 100%;
        }
        .subject-list-item:hover {
            @apply border-blue-400 bg-blue-50;
        }
        .subject-list-item .subject-checkbox {
            @apply w-5 h-5 text-primary bg-gray-100 border-gray-300 rounded-md focus:ring-primary focus:ring-offset-0;
            flex-shrink: 0;
            margin-right: 0.75rem;
        }
        .subject-list-item .subject-code-display {
            @apply font-bold text-base text-gray-800 flex-grow; /* Allow subject code to take space */
        }
        .subject-list-item .default-subject-price-display {
            @apply text-base font-semibold text-gray-700; /* Larger, bolder price */
            flex-shrink: 0; /* Prevent price from shrinking */
        }

        /* Styles for selected subject items */
        .subject-list-item.selected-subject {
            @apply bg-primary text-white border-primary ring-2 ring-offset-2 ring-primary-500;
        }
        .subject-list-item.selected-subject .subject-code-display, 
        .subject-list-item.selected-subject .default-subject-price-display {
            @apply text-white; /* Ensure text is white when selected */
        }
        .subject-list-item.selected-subject .subject-checkbox {
            @apply bg-white border-white;
        }
        .subject-list-item.selected-subject .subject-checkbox:checked {
            @apply text-blue-600; /* Reapply a visible checkmark color */
        }

        /* Styles for custom price input group */
        .subject-custom-input-group {
            display: flex;
            align-items: center;
            gap: 0.25rem; /* Tighter spacing between BDT and input */
            flex-shrink: 0; /* Prevent from shrinking */
            margin-left: 1rem; /* Space between subject code and price input */
        }
        .subject-custom-input-group label {
            @apply text-sm text-gray-700 font-semibold;
            flex-shrink: 0;
        }
        .subject-list-item.selected-subject .subject-custom-input-group label {
            @apply text-white; /* White BDT label when selected */
        }
        .subject-custom-price-input {
            @apply w-28 p-1.5 border-2 border-blue-400 rounded-md text-base font-bold text-right text-gray-800;
            transition: all 0.2s ease-in-out;
        }
        .subject-custom-price-input:focus {
            @apply outline-none ring-2 ring-blue-300;
        }
        .subject-list-item.selected-subject .subject-custom-price-input {
            @apply bg-white text-primary border-primary;
        }


        /* Modal Summary */
        #modal_selected_subjects_summary {
            @apply p-4 bg-blue-100 border border-blue-300 rounded-lg text-blue-800; /* Adjusted to blue-themed like cart */
        }
        #modal_summary_fee_type {
            @apply text-xl font-semibold mb-2;
        }
        #modal_selected_subjects_list {
            @apply list-disc list-inside pl-0 text-sm;
        }
        #modal_selected_subjects_list li {
            @apply py-0.5;
        }
        #modal_total_selected_amount {
            @apply text-2xl font-extrabold;
        }
    </style>

    <style>
        /* General adjustments for smaller screens */
        @media (max-width: 1024px) {
            main {
                padding: 1.5rem; /* Reduce padding on medium devices */
            }
        }

        /* Specific adjustments for mobile devices */
        @media (max-width: 767px) {
            main {
                padding: 1rem;
                padding-top: 80px; /* Account for sticky header */
                padding-bottom: 100px; /* Account for mobile navigation */
            }

            /* Stack form elements vertically */
            .flex-col.sm\:flex-row {
                flex-direction: column;
                align-items: stretch;
            }

            /* Full-width back button on small screens */
            .w-full.sm\:w-auto {
                width: 100%;
            }

            /* Center text in the back button on mobile */
            .sm\:text-left {
                text-align: center;
            }

            /* Stack grid columns on small screens */
            .grid.grid-cols-1.md\:grid-cols-2.gap-6,
            .grid.grid-cols-1.md\:grid-cols-3.gap-6 {
                grid-template-columns: 1fr;
            }
            /* Adjust POS layout for small screens */
            .pos-grid {
                grid-template-columns: 1fr;
            }
            .order-md-last {
                order: initial; /* Reset order on small screens */
            }
        }

        /* Restore default padding for larger screens */
        @media (min-width: 768px) {
            main {
                padding-top: 1.5rem; /* Default p-6 for desktop */
                padding-bottom: 1.5rem; /* Default p-6 for desktop */
            }
            .pos-grid {
                grid-template-columns: 2fr 1fr; /* Main content wider than summary */
            }
            .order-md-last {
                order: 99; /* Push summary to last column on medium+ screens */
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
                    <h2 class="text-3xl font-extrabold text-gray-900 leading-tight">New Fee Transaction</h2>
                    <p class="text-gray-500 mt-2">Process fee payments and generate invoices for students.</p>
                </div>
                <a href="fees.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center transition duration-200 ease-in-out">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Fee Records
                </a>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md fade-in" role="alert">
                    <p class="font-bold">Error:</p>
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>

            <form action="add_fee.php" method="POST" class="grid pos-grid gap-6">
                <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 fade-in space-y-6">
                    <h3 class="pos-section-title"><i class="fas fa-user-graduate mr-2"></i>Student & Fee Details</h3>

                    <div>
                        <label for="student_id" class="block text-sm font-medium text-gray-700 mb-2">Select Student:</label>
                        <select id="student_id" name="student_id" required class="mt-1 block w-full p-3 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary text-base">
                            <option value="">-- Search or Select a Student --</option>
                            <?php foreach ($students_list as $student_item): ?>
                                <option value="<?php echo htmlspecialchars($student_item['id']); ?>"><?php echo htmlspecialchars($student_item['username']); ?> (ID: <?php echo htmlspecialchars($student_item['acca_id'] ?? 'N/A'); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Choose Fee Type:</label>
                        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3" id="fee_type_buttons">
                            <?php foreach ($fee_types_list as $fee_type_data): ?>
                                <button type="button" 
                                        data-fee-type-name="<?php echo htmlspecialchars($fee_type_data['type_name']); ?>" 
                                        data-fee-price="<?php echo htmlspecialchars($fee_type_data['price'] ?? '0.00'); ?>"
                                        data-needs-subject="<?php echo htmlspecialchars($fee_type_data['needs_subject']); ?>"
                                        data-is-custom-amount="<?php echo htmlspecialchars($fee_type_data['is_custom_amount']); ?>"
                                        class="fee-type-btn p-4 rounded-lg border border-gray-300 bg-white text-gray-700 hover:border-primary hover:bg-primary-50 transition-all duration-200 ease-in-out text-center">
                                    <i class="fas <?php
                                        switch($fee_type_data['type_name']) {
                                            case 'tuition': echo 'fa-book-open'; break;
                                            case 'admission': echo 'fa-user-plus'; break;
                                            case 'exam fees': echo 'fa-pencil-alt'; break;
                                            case 'event fees': echo 'fa-calendar-alt'; break;
                                            case 'fine': echo 'fa-exclamation-triangle'; break;
                                            case 'other': echo 'fa-tags'; break;
                                            default: echo 'fa-money-bill-wave'; break; // Generic icon
                                        }
                                    ?> mb-1 text-lg"></i>
                                    <span class="block text-sm font-medium"><?php echo ucfirst(str_replace('_', ' ', $fee_type_data['type_name'])); ?></span>
                                    <?php if ($fee_type_data['price'] !== null && $fee_type_data['price'] > 0): // Only show price if it's not null and greater than 0 ?>
                                        <span class="block text-xs text-gray-500">BDT <?php echo number_format($fee_type_data['price'], 2); ?></span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="custom_amount_area_main" class="bg-gray-50 p-4 rounded-lg border border-gray-200 space-y-2 hidden">
                        <label for="custom_fee_name_input" class="block text-sm font-medium text-gray-700">Fee Name/Description:</label>
                        <input type="text" id="custom_fee_name_input" placeholder="e.g., Lab Equipment Fee, Late Fine" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm">
                        
                        <label for="current_item_amount" class="block text-sm font-medium text-gray-700">Custom Fee Amount (BDT):</label>
                        <input type="number" id="current_item_amount" step="0.01" min="0" value="0.00" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm text-lg font-semibold">
                        <p class="text-sm text-gray-500 mt-1">Enter a specific amount for this custom fee.</p>
                        <button type="button" id="add_custom_item_to_cart_btn" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-cart-plus mr-2"></i> Add Custom Fee to Cart
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                         <div>
                            <label for="due_date" class="block text-sm font-medium text-gray-700">Due Date:</label>
                            <input type="date" id="due_date" name="due_date" required value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                        </div>
                        <div>
                            <label for="payment_status" class="block text-sm font-medium text-gray-700">Payment Status:</label>
                            <select id="payment_status" name="payment_status" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <option value="Pending" selected>Pending</option>
                                <option value="Paid">Paid</option>
                                <option value="Overdue">Overdue</option>
                                <option value="Waived">Waived</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="order-md-last bg-white rounded-xl shadow-custom p-6 sm:p-8 fade-in flex flex-col justify-between">
                    <div>
                        <h3 class="pos-section-title"><i class="fas fa-receipt mr-2"></i>Transaction Summary</h3>
                        
                        <div id="cart_items_display" class="space-y-3 mb-6 max-h-60 overflow-y-auto pr-2">
                            <p class="text-gray-500 text-center py-4" id="empty_cart_message">No items in cart.</p>
                            </div>

                        <div class="space-y-3 mt-6">
                            <div class="flex justify-between items-center text-gray-700">
                                <span>Total Original Amount:</span>
                                <span class="font-medium text-lg" id="summary_original_amount">BDT 0.00</span>
                                <input type="hidden" name="amount" id="total_original_amount_input"> </div>
                            <div class="flex justify-between items-center text-gray-700">
                                <label for="discount" class="text-sm font-medium text-gray-700 block">Discount (BDT):</label>
                                <input type="number" id="discount" name="discount" step="0.01" min="0" value="0" class="w-28 p-1 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary text-right font-semibold">
                            </div>
                            <hr class="border-gray-200 my-4">
                            <div class="flex justify-between items-center pos-total-display">
                                <span class="text-2xl font-bold">Total Due:</span>
                                <span class="text-4xl font-extrabold" id="summary_final_amount">BDT 0.00</span>
                            </div>
                        </div>

                        <div id="superadmin_auth" class="hidden bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md space-y-4 fade-in mt-6">
                            <p class="font-bold text-yellow-800"><i class="fas fa-shield-alt mr-2"></i>Superadmin Authorization Required for Discount</p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="superadmin_username" class="block text-sm font-medium text-gray-700">Superadmin Username:</label>
                                    <input type="text" id="superadmin_username" name="superadmin_username" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm">
                                </div>
                                <div>
                                    <label for="superadmin_password" class="block text-sm font-medium text-gray-700">Superadmin Password:</label>
                                    <input type="password" id="superadmin_password" name="superadmin_password" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-8">
                        <button type="submit" class="w-full flex justify-center py-4 px-4 border border-transparent rounded-lg shadow-lg text-lg font-semibold text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-200 ease-in-out">
                            <i class="fas fa-cash-register mr-3"></i> Process Fee Transaction
                        </button>
                    </div>
                </div>
                <input type="hidden" name="cart_items" id="cart_items_hidden_input">
            </form>
        </main>
    </div>

    <div id="subjectModal" class="modal">
        <div class="modal-content">
            <span class="close-button" id="modalCloseButton">&times;</span> 
            <h3 class="text-2xl font-bold mb-4" id="modal_fee_type_title">Select Subject(s)</h3>
            <p class="text-gray-600 mb-6" id="modal_subject_instruction">Check the subjects you wish to add:</p>
            
            <div class="subject-grid-container">
                <?php
                // Group subjects by category for display
                $grouped_subjects = [];
                foreach ($fees_subject_list as $subject_data) {
                    $grouped_subjects[$subject_data['category']][] = $subject_data;
                }
                ?>
                <?php if (empty($fees_subject_list)): ?>
                    <p class="text-center text-gray-500">No subjects available.</p>
                <?php else: ?>
                    <?php foreach ($grouped_subjects as $category => $subjects): ?>
                        <div class="category-group mb-6">
                            <h4 class="text-lg font-semibold text-gray-800 mb-3 pb-1 border-b border-gray-200"><?= htmlspecialchars($category); ?></h4>
                            <div class="subject-list"> <?php foreach ($subjects as $subject): ?>
                                    <div class="subject-list-item"> <div class="flex items-center flex-grow"> <input type="checkbox" 
                                                   class="subject-checkbox"
                                                   data-subject-id="<?= htmlspecialchars($subject['id']); ?>"
                                                   data-subject-code="<?= htmlspecialchars($subject['subject_code']); ?>"
                                                   data-subject-price="<?= htmlspecialchars(floatval($subject['price'])); ?>"> 
                                            <p class="subject-code-display"><?= htmlspecialchars($subject['subject_code']); ?></p>
                                        </div>
                                        
                                        <div class="flex items-center justify-end flex-shrink-0"> <span class="default-subject-price-display">BDT <?= number_format($subject['price'], 2); ?></span>
                                            
                                            <div class="subject-custom-input-group hidden">
                                                <label for="subject_custom_price_<?= $subject['id'] ?>">BDT</label>
                                                <input type="number" 
                                                       step="0.01" min="0" 
                                                       id="subject_custom_price_<?= $subject['id'] ?>" 
                                                       class="subject-custom-price-input" 
                                                       value="<?= htmlspecialchars(number_format($subject['price'], 2, '.', '')) ?>">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="modal_selected_subjects_summary" class="p-4 bg-blue-100 border border-blue-300 rounded-lg text-blue-800 mt-6 hidden">
                <p class="font-semibold text-lg" id="modal_summary_fee_type"></p>
                <ul id="modal_selected_subjects_list" class="list-disc list-inside pl-0 text-sm mb-2">
                    </ul>
                <p class="text-lg font-bold mt-1">Total Amount: <span id="modal_total_selected_amount">BDT 0.00</span></p>
            </div>

            <button type="button" id="add_subjects_to_cart_btn" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed mt-4">
                <i class="fas fa-check-circle mr-2"></i> Add Selected Subjects to Cart
            </button>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // PHP data passed to JavaScript
            const feeTypesList = <?php echo json_encode($fee_types_list); ?>;
            const feesSubjectList = <?php echo json_encode($fees_subject_list); ?>;
            const currentLoggedInUserRole = "<?php echo isset($_SESSION['role']) ? $_SESSION['role'] : 'guest'; ?>"; 

            // DOM Elements (main page)
            const feeTypeButtonsContainer = document.getElementById('fee_type_buttons');
            const customAmountAreaMain = document.getElementById('custom_amount_area_main');
            const customFeeNameInput = document.getElementById('custom_fee_name_input'); 
            const currentItemAmountInput = document.getElementById('current_item_amount');
            const addCustomItemToCartBtn = document.getElementById('add_custom_item_to_cart_btn'); 

            const discountInput = document.getElementById('discount');
            const superadminAuthSection = document.getElementById('superadmin_auth');
            const cartItemsDisplay = document.getElementById('cart_items_display');
            const emptyCartMessage = document.getElementById('empty_cart_message');
            const summaryOriginalAmount = document.getElementById('summary_original_amount');
            const summaryFinalAmount = document.getElementById('summary_final_amount');
            const totalOriginalAmountInput = document.getElementById('total_original_amount_input');
            const cartItemsHiddenInput = document.getElementById('cart_items_hidden_input');
            
            // Modal Elements
            const subjectModal = document.getElementById('subjectModal');
            const modalCloseButton = document.getElementById('modalCloseButton'); 
            const modalFeeTypeTitle = document.getElementById('modal_fee_type_title'); 
            const modalSubjectInstruction = document.getElementById('modal_subject_instruction'); 
            const subjectsByCategoryContainer = subjectModal.querySelector('.subject-grid-container'); 
            const modalSelectedSubjectsSummary = document.getElementById('modal_selected_subjects_summary');
            const modalSummaryFeeType = document.getElementById('modal_summary_fee_type');
            const modalSelectedSubjectsList = document.getElementById('modal_selected_subjects_list');
            const modalTotalSelectedAmount = document.getElementById('modal_total_selected_amount');
            const addSubjectsToCartBtn = document.getElementById('add_subjects_to_cart_btn'); 

            let cart = []; 
            let currentlySelectedFeeTypeData = null; 
            let allowCustomSubjectPrice = false; 

            // Helper to capitalize first letter
            function ucfirst(str) {
                return str.charAt(0).toUpperCase() + str.slice(1);
            }

            // Function to update the main cart display and recalculate totals
            function updateCartDisplay() {
                cartItemsDisplay.innerHTML = ''; 
                if (cart.length === 0) {
                    emptyCartMessage.classList.remove('hidden');
                } else {
                    emptyCartMessage.classList.add('hidden');
                    cart.forEach((item, index) => {
                        const itemDiv = document.createElement('div');
                        itemDiv.classList.add('flex', 'justify-between', 'items-center', 'bg-blue-50', 'border', 'border-blue-200', 'rounded-md', 'p-3', 'text-blue-800', 'fade-in');
                        // Ensure item.amount is always a number for display
                        const displayAmount = (parseFloat(item.amount) || 0).toFixed(2); 
                        
                        // Display the custom fee name if available, otherwise fee_type and subject
                        let itemName = ucfirst(item.fee_type.replace('_', ' '));
                        if (item.custom_fee_name) { 
                            itemName = item.custom_fee_name;
                        } else if (item.subject_code) { 
                            itemName += ` - ${item.subject_code}`;
                        }

                        itemDiv.innerHTML = `
                            <div>
                                <p class="font-semibold">${itemName}</p>
                                <p class="text-sm">BDT ${displayAmount}</p>
                            </div>
                            <button type="button" data-index="${index}" class="remove-item-btn text-red-500 hover:text-red-700 ml-4">
                                <i class="fas fa-times-circle"></i>
                            </button>
                        `;
                        cartItemsDisplay.appendChild(itemDiv);
                    });
                }
                calculateTotals();
            }

            // Function to calculate and update overall transaction totals
            function calculateTotals() {
                // Ensure every item.amount is treated as a number during sum
                let totalOriginal = cart.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);
                let discount = parseFloat(discountInput.value) || 0;

                if (discount > totalOriginal) {
                    discount = totalOriginal;
                    discountInput.value = totalOriginal.toFixed(2);
                }

                let finalTotal = totalOriginal - discount;

                summaryOriginalAmount.textContent = `BDT ${totalOriginal.toFixed(2)}`;
                summaryFinalAmount.textContent = `BDT ${finalTotal.toFixed(2)}`;
                totalOriginalAmountInput.value = totalOriginal.toFixed(2); 

                cartItemsHiddenInput.value = JSON.stringify(cart);

                if (discount > 0 && currentLoggedInUserRole !== 'super_admin') {
                    superadminAuthSection.classList.remove('hidden');
                } else {
                    superadminAuthSection.classList.add('hidden');
                }
            }

            // --- Modal Functions and Logic ---
            function openModal() {
                subjectModal.style.display = 'flex'; 
                // Reset modal state
                document.querySelectorAll('.subject-checkbox').forEach(checkbox => {
                    checkbox.checked = false; // Uncheck all
                    // Reset custom price inputs too
                    const customPriceInput = checkbox.closest('.subject-list-item').querySelector('.subject-custom-price-input'); // Changed selector
                    if (customPriceInput) {
                        // Ensure price is formatted with two decimal places
                        const defaultPrice = parseFloat(checkbox.dataset.subjectPrice).toFixed(2);
                        customPriceInput.value = defaultPrice; // Reset to default
                    }
                });
                document.querySelectorAll('.subject-list-item').forEach(item => { // Changed selector
                    item.classList.remove('selected-subject'); // Remove visual selection
                });

                modalSelectedSubjectsSummary.classList.add('hidden');
                modalSelectedSubjectsList.innerHTML = '';
                modalTotalSelectedAmount.textContent = 'BDT 0.00';
                addSubjectsToCartBtn.disabled = true;

                // NEW: Show/hide custom price inputs based on fee type
                if (currentlySelectedFeeTypeData && currentlySelectedFeeTypeData.type_name === 'exam fees') {
                    allowCustomSubjectPrice = true;
                    modalSubjectInstruction.textContent = 'Select subjects and customize their prices:';
                    document.querySelectorAll('.subject-custom-input-group').forEach(group => group.classList.remove('hidden')); // Changed selector
                    document.querySelectorAll('.default-subject-price-display').forEach(span => span.classList.add('hidden'));
                } else {
                    allowCustomSubjectPrice = false;
                    modalSubjectInstruction.textContent = 'Check the subjects you wish to add:';
                    document.querySelectorAll('.subject-custom-input-group').forEach(group => group.classList.add('hidden')); // Changed selector
                    document.querySelectorAll('.default-subject-price-display').forEach(span => span.classList.remove('hidden'));
                }
                updateModalPreview(); // Initial update based on state
            }

            function closeModal() {
                subjectModal.style.display = 'none';
                currentlySelectedFeeTypeData = null; // Clear selected fee type data when modal closes
                allowCustomSubjectPrice = false; // Reset flag
                document.querySelectorAll('.fee-type-btn').forEach(btn => btn.classList.remove('selected')); // Deselect any fee type button
            }

            // Function to update the modal's preview and enable/disable add button
            function updateModalPreview() {
                const checkedSubjects = Array.from(subjectsByCategoryContainer.querySelectorAll('.subject-checkbox:checked'));
                let totalSelectedAmount = 0;
                let allPricesValid = true; 
                modalSelectedSubjectsList.innerHTML = ''; // Clear previous list

                if (checkedSubjects.length === 0) {
                    modalSelectedSubjectsSummary.classList.add('hidden');
                    addSubjectsToCartBtn.disabled = true; 
                } else {
                    modalSelectedSubjectsSummary.classList.remove('hidden');
                    
                    checkedSubjects.forEach(checkbox => {
                        const subjectCode = checkbox.dataset.subjectCode || 'Unknown Subject';
                        let subjectPrice = 0;

                        if (allowCustomSubjectPrice) {
                            const customPriceInput = checkbox.closest('.subject-list-item').querySelector('.subject-custom-price-input'); // Changed selector
                            subjectPrice = parseFloat(customPriceInput.value) || 0;
                            if (isNaN(subjectPrice) || subjectPrice < 0) {
                                allPricesValid = false; 
                            }
                        } else {
                            subjectPrice = parseFloat(checkbox.dataset.subjectPrice) || 0;
                        }

                        totalSelectedAmount += subjectPrice;
                        
                        const listItem = document.createElement('li');
                        listItem.textContent = `${subjectCode} (BDT ${subjectPrice.toFixed(2)})`; 
                        modalSelectedSubjectsList.appendChild(listItem);
                    });
                    
                    addSubjectsToCartBtn.disabled = !(checkedSubjects.length > 0 && allPricesValid);
                }
                
                if (currentlySelectedFeeTypeData) {
                    modalSummaryFeeType.textContent = ucfirst(currentlySelectedFeeTypeData.type_name.replace('_', ' '));
                } else {
                    modalSummaryFeeType.textContent = 'Selected Subjects'; 
                }
                
                modalTotalSelectedAmount.textContent = `BDT ${totalSelectedAmount.toFixed(2)}`;
            }

            // --- Event Listeners ---

            // Main Fee Type Buttons click handler
            feeTypeButtonsContainer.addEventListener('click', function(event) {
                const button = event.target.closest('.fee-type-btn');
                if (button) {
                    document.querySelectorAll('.fee-type-btn').forEach(btn => {
                        btn.classList.remove('selected');
                    });
                    button.classList.add('selected');

                    currentlySelectedFeeTypeData = feeTypesList.find(
                        type => type.type_name === button.dataset.feeTypeName
                    );

                    customAmountAreaMain.classList.add('hidden'); // Hide custom amount area by default
                    currentItemAmountInput.value = '0.00'; // Reset custom amount
                    customFeeNameInput.value = ''; // Reset custom name input

                    if (currentlySelectedFeeTypeData.needs_subject == 1) {
                        // For Tuition/Exam Fees, open modal
                        modalFeeTypeTitle.textContent = `Select Subject(s) for ${ucfirst(currentlySelectedFeeTypeData.type_name.replace('_', ' '))}`;
                        openModal();
                    } else if (currentlySelectedFeeTypeData.is_custom_amount == 1) {
                        // For 'Other' fee, show custom amount input AND name input
                        customAmountAreaMain.classList.remove('hidden');
                        customFeeNameInput.focus(); // Focus on name input first
                        addCustomItemToCartBtn.disabled = true; 
                        updateCustomFeeAddButtonState();
                    } else {
                        // For fixed-price fees, directly add to cart
                        cart.push({
                            fee_type: currentlySelectedFeeTypeData.type_name,
                            amount: parseFloat(currentlySelectedFeeTypeData.price) || 0, // Ensure amount is a number
                            subject_code: null,
                            custom_fee_name: null 
                        });
                        updateCartDisplay();
                        
                        // Reset selection after adding
                        button.classList.remove('selected');
                        currentlySelectedFeeTypeData = null; 
                    }
                }
            });

            // Event delegation for clicks within the subjectsByCategoryContainer
            subjectsByCategoryContainer.addEventListener('click', function(event) {
                const subjectItem = event.target.closest('.subject-list-item'); // Changed selector
                if (subjectItem) {
                    const checkbox = subjectItem.querySelector('.subject-checkbox');
                    // Toggle checkbox if click was not directly on the checkbox or its label, or a price input
                    if (event.target !== checkbox && !event.target.closest('label') && !event.target.classList.contains('subject-custom-price-input')) {
                        checkbox.checked = !checkbox.checked;
                    }
                    // Dispatch change event to trigger visual update and preview update
                    checkbox.dispatchEvent(new Event('change'));
                }
            });

            // Handle checkbox changes directly (for visual selection and preview update)
            subjectsByCategoryContainer.addEventListener('change', function(event) {
                if (event.target.classList.contains('subject-checkbox')) {
                    const subjectItem = event.target.closest('.subject-list-item'); // Changed selector
                    if (event.target.checked) {
                        subjectItem.classList.add('selected-subject');
                    } else {
                        subjectItem.classList.remove('selected-subject');
                    }
                    updateModalPreview(); // Update preview based on checked items
                }
            });

            // Listen for input changes on custom subject prices
            subjectsByCategoryContainer.addEventListener('input', function(event) {
                if (event.target.classList.contains('subject-custom-price-input')) {
                    // Also check the checkbox if the price is manually changed without selecting
                    const checkbox = event.target.closest('.subject-list-item').querySelector('.subject-checkbox'); // Changed selector
                    if (!checkbox.checked) {
                        checkbox.checked = true;
                        checkbox.closest('.subject-list-item').classList.add('selected-subject'); // Changed selector
                    }
                    updateModalPreview(); // Recalculate preview on price change
                }
            });


            // Add Selected Subjects to Cart button inside modal
            if (addSubjectsToCartBtn) { 
                addSubjectsToCartBtn.addEventListener('click', function() {
                    const checkedSubjects = Array.from(subjectsByCategoryContainer.querySelectorAll('.subject-checkbox:checked'));
                    if (checkedSubjects.length === 0) {
                        alert('Please select at least one subject.');
                        return;
                    }

                    if (!currentlySelectedFeeTypeData) { 
                        alert("Error: Fee type not selected. Please close and re-open the modal.");
                        closeModal(); 
                        return;
                    }

                    let hasInvalidPrice = false;
                    checkedSubjects.forEach(checkbox => {
                        let subjectPrice = 0;
                        if (allowCustomSubjectPrice) {
                            const customPriceInput = checkbox.closest('.subject-list-item').querySelector('.subject-custom-price-input'); // Changed selector
                            subjectPrice = parseFloat(customPriceInput.value) || 0;
                            if (isNaN(subjectPrice) || subjectPrice < 0) {
                                hasInvalidPrice = true;
                            }
                        } else {
                            subjectPrice = parseFloat(checkbox.dataset.subjectPrice) || 0;
                        }

                        cart.push({
                            fee_type: currentlySelectedFeeTypeData.type_name,
                            amount: subjectPrice, 
                            subject_code: checkbox.dataset.subjectCode,
                            custom_fee_name: null 
                        });
                    });

                    if (hasInvalidPrice) {
                        alert("Please ensure all selected subjects have a valid, non-negative price.");
                        return;
                    }

                    updateCartDisplay();
                    closeModal(); 
                });
            }
            

            // Close button for the modal 
            if (modalCloseButton) { 
                modalCloseButton.addEventListener('click', function(event) {
                    event.stopPropagation(); 
                    closeModal();
                });
            }
            
            // Close modal if click outside content (but inside modal overlay)
            subjectModal.addEventListener('click', function(event) {
                if (event.target === subjectModal) { 
                    closeModal();
                }
            });

            // Function to check custom fee inputs and enable/disable button
            function updateCustomFeeAddButtonState() {
                const amount = parseFloat(currentItemAmountInput.value) || 0;
                const customName = customFeeNameInput.value.trim();
                addCustomItemToCartBtn.disabled = !(amount > 0 && customName.length > 0);
            }

            // Custom amount input and custom name input validation
            currentItemAmountInput.addEventListener('input', updateCustomFeeAddButtonState);
            customFeeNameInput.addEventListener('input', updateCustomFeeAddButtonState);


            // Add Custom Item to Cart button (for 'other' fee type)
            addCustomItemToCartBtn.addEventListener('click', function() {
                const amount = parseFloat(currentItemAmountInput.value) || 0;
                const customName = customFeeNameInput.value.trim();

                if (currentlySelectedFeeTypeData && currentlySelectedFeeTypeData.is_custom_amount == 1 && amount > 0 && customName.length > 0) {
                    cart.push({
                        fee_type: currentlySelectedFeeTypeData.type_name,
                        amount: amount,
                        subject_code: null,
                        custom_fee_name: customName 
                    });
                    updateCartDisplay();
                    // Reset UI after adding
                    customAmountAreaMain.classList.add('hidden');
                    currentlySelectedFeeTypeData = null;
                    document.querySelectorAll('.fee-type-btn').forEach(btn => btn.classList.remove('selected'));
                } else {
                    alert("Please enter a positive amount and a name for the custom fee.");
                }
            });

            // Event listener for discount input
            discountInput.addEventListener('input', calculateTotals);

            // Event listener for removing items from cart
            cartItemsDisplay.addEventListener('click', function(event) {
                const removeButton = event.target.closest('.remove-item-btn');
                if (removeButton) {
                    const indexToRemove = parseInt(removeButton.dataset.index);
                    cart.splice(indexToRemove, 1); 
                    updateCartDisplay(); 
                }
            });

            // Initial display update
            updateCartDisplay();


            // --- Dropdown Menu Script (for header.php) ---
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');

            if (userMenuButton && userMenu) {
                userMenuButton.addEventListener('click', function() {
                    userMenu.classList.toggle('hidden');
                });

                document.addEventListener('click', function(event) {
                    if (!userMenuButton.contains(event.target) && !userMenu.contains(event.target)) {
                        userMenu.classList.add('hidden');
                    }
                });

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
                    e.preventDefault();
                    mobileMoreMenu.classList.toggle('hidden');
                });
            }
            document.addEventListener('click', (e) => {
                if (mobileMoreMenu && !mobileMoreMenu.classList.contains('hidden') && !mobileMoreBtn.contains(e.target) && !mobileMoreMenu.contains(e.target)) {
                    mobileMoreMenu.classList.add('hidden'); 
                }
            });
        });
    </script>
</body>
</html>