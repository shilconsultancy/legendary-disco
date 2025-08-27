<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';

// --- Super Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../index.php?error=unauthorized');
    exit;
}
$active_page = 'system_management';

// Message variables for success/error alerts
$message = '';
$message_type = ''; // 'success' or 'error'

// ====================================================================
// HANDLE PRICE UPDATES (NEW LOGIC)
// ====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['update_fee_type_price'])) {
        $id = (int)$_POST['id'];
        $price = (float)$_POST['price'];

        if ($price < 0) {
            $message = "Price cannot be negative.";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE fee_types SET price = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("di", $price, $id);
                if ($stmt->execute()) {
                    $message = "Fee type price updated successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error updating fee type price: " . $stmt->error;
                    $message_type = "error";
                }
                $stmt->close();
            } else {
                $message = "Database error preparing fee type update: " . $conn->error;
                $message_type = "error";
            }
        }
    } elseif (isset($_POST['update_subject_price'])) {
        $id = (int)$_POST['id'];
        $price = (float)$_POST['price'];

        if ($price < 0) {
            $message = "Price cannot be negative.";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE fee_subjects SET price = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("di", $price, $id);
                if ($stmt->execute()) {
                    $message = "Subject price updated successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error updating subject price: " . $stmt->error;
                    $message_type = "error";
                }
                $stmt->close();
            } else {
                $message = "Database error preparing subject update: " . $conn->error;
                $message_type = "error";
            }
        }
    }
}


// ====================================================================
// DATA FETCHING - ALL USERS
// ====================================================================
$users_limit = 10;
$users_page = isset($_GET['users_page']) ? (int)$_GET['users_page'] : 1;
$users_offset = ($users_page - 1) * $users_limit;
$users_search = isset($_GET['users_search']) ? trim($_GET['users_search']) : '';
$role_filter = isset($_GET['role_filter']) ? trim($_GET['role_filter']) : '';

$users_where_clauses = [];
$users_params = [];
$users_types = '';

if (!empty($users_search)) {
    $search_term = '%' . $users_search . '%';
    $users_where_clauses[] = "(username LIKE ? OR email LIKE ?)";
    array_push($users_params, $search_term, $search_term);
    $users_types .= 'ss';
}
if (!empty($role_filter)) {
    $users_where_clauses[] = "role = ?";
    $users_params[] = $role_filter;
    $users_types .= 's';
}

$users_where_sql = '';
if (!empty($users_where_clauses)) {
    $users_where_sql = ' WHERE ' . implode(' AND ', $users_where_clauses);
}

// Total users count for pagination
$total_users_stmt = $conn->prepare("SELECT COUNT(id) as total FROM users" . $users_where_sql);
if (!empty($users_params)) $total_users_stmt->bind_param($users_types, ...$users_params);
$total_users_stmt->execute();
$total_users = $total_users_stmt->get_result()->fetch_assoc()['total'];
$total_users_pages = ceil($total_users / $users_limit);
$total_users_stmt->close();

// Fetch users for the current page
$users_stmt = $conn->prepare("SELECT id, username, email, role, acca_id, created_at FROM users" . $users_where_sql . " ORDER BY created_at DESC LIMIT ? OFFSET ?");
$current_users_params = $users_params;
$current_users_params[] = $users_limit;
$current_users_params[] = $users_offset;
$current_users_types = $users_types . 'ii';
$users_stmt->bind_param($current_users_types, ...$current_users_params);
$users_stmt->execute();
$users_result = $users_stmt->get_result();

// ====================================================================
// DATA FETCHING - PAYMENT HISTORY
// ====================================================================
$payments_limit = 10;
$payments_page = isset($_GET['payments_page']) ? (int)$_GET['payments_page'] : 1;
$payments_offset = ($payments_page - 1) * $payments_limit;
$payments_search = isset($_GET['payments_search']) ? trim($_GET['payments_search']) : '';
$date_filter = isset($_GET['date_filter']) ? trim($_GET['date_filter']) : '';

$payments_where_clauses = ["f.payment_status = 'Paid'"];
$payments_params = [];
$payments_types = '';

if (!empty($payments_search)) {
    $search_term = '%' . $payments_search . '%';
    $payments_where_clauses[] = "(u.username LIKE ? OR f.invoice_id LIKE ?)";
    array_push($payments_params, $search_term, $search_term);
    $payments_types .= 'ss';
}
if (!empty($date_filter)) {
    switch ($date_filter) {
        case 'today':
            $payments_where_clauses[] = "DATE(f.paid_date) = CURDATE()";
            break;
        case 'week':
            $payments_where_clauses[] = "f.paid_date >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)";
            break;
        case 'month':
            $payments_where_clauses[] = "MONTH(f.paid_date) = MONTH(CURDATE()) AND YEAR(f.paid_date) = YEAR(CURDATE())";
            break;
        case 'year':
            $payments_where_clauses[] = "YEAR(f.paid_date) = YEAR(CURDATE())";
            break;
    }
}
$payments_where_sql = ' WHERE ' . implode(' AND ', $payments_where_clauses);

// Total payments count for pagination
$total_payments_stmt = $conn->prepare("SELECT COUNT(f.id) as total FROM fees f JOIN users u ON f.student_id = u.id" . $payments_where_sql);
if (!empty($payments_params)) $total_payments_stmt->bind_param($payments_types, ...$payments_params);
$total_payments_stmt->execute();
$total_payments = $total_payments_stmt->get_result()->fetch_assoc()['total'];
$total_payments_pages = ceil($total_payments / $payments_limit);
$total_payments_stmt->close();

// Fetch payments for the current page
$payments_stmt = $conn->prepare("SELECT f.id, u.username, f.amount, f.fee_type, f.paid_date, f.invoice_id FROM fees f JOIN users u ON f.student_id = u.id" . $payments_where_sql . " ORDER BY f.paid_date DESC LIMIT ? OFFSET ?");
$current_payments_params = $payments_params;
$current_payments_params[] = $payments_limit;
$current_payments_params[] = $payments_offset;
$current_payments_types = $payments_types . 'ii';
$payments_stmt->bind_param($current_payments_types, ...$current_payments_params);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();

// ====================================================================
// DATA FETCHING - FOR MANAGE PRICES SECTION (NEW)
// ====================================================================
$fee_types_list = [];
$result_fee_types = $conn->query("SELECT id, type_name, price, needs_subject, is_custom_amount FROM fee_types ORDER BY type_name ASC");
if ($result_fee_types) {
    while ($row = $result_fee_types->fetch_assoc()) {
        $fee_types_list[] = $row;
    }
} else {
    $message .= (empty($message) ? "" : "<br>") . "Error fetching fee types for management: " . $conn->error;
    $message_type = "error";
}

$fees_subject_list = [];
$result_fees_subject = $conn->query("SELECT id, subject_code, price, category FROM fee_subjects ORDER BY category, subject_code ASC");
if ($result_fees_subject) {
    while ($row = $result_fees_subject->fetch_assoc()) {
        $fees_subject_list[] = $row;
    }
} else {
    $message .= (empty($message) ? "" : "<br>") . "Error fetching fee subjects for management: " . $conn->error;
    $message_type = "error";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Management - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4F46E5', secondary: '#0EA5E9', dark: '#1E293B', light: '#F8FAFC',
                    }
                }
            }
        }
    </script>
    <style>
        /* Ensuring body and html take full height for fixed positioning */
        html, body {
            height: 100%;
        }
        body { 
            background-color: #F8FAFC; 
            display: flex; /* Use flexbox for overall layout */
            flex-direction: column; /* Stack header, content, and mobile nav vertically */
        }
        .shadow-custom { box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .sidebar-link:hover { background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%); }
        .active-tab {
            background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%);
            border-left: 4px solid #4F46E5;
            color: #4F46E5;
        }
        .sm-tab-link.active {
            border-bottom: 2px solid #4F46E5;
            color: #4F46E5;
            font-weight: 600;
        }
        /* Responsive Table Styles (Centralized where possible, or defined here for this page) */
        @media (max-width: 767px) {
            .responsive-table thead { display: none; }
            .responsive-table tr { 
                display: block; 
                margin-bottom: 1rem; 
                border: 1px solid #e5e7eb; 
                border-radius: 0.5rem; 
                padding: 1rem; 
            }
            .responsive-table td { 
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
                padding: 0.5rem 0; 
                border-bottom: 1px dashed #e5e7eb; 
            }
            .responsive-table td:last-child { border-bottom: none; }
            .responsive-table td::before { 
                content: attr(data-label); 
                font-weight: 600; 
                color: #4b5563; 
                margin-right: 1rem; 
            }
            /* Adjust padding for main content on small screens */
            main {
                padding-left: 1rem; /* Consistent padding */
                padding-right: 1rem; /* Consistent padding */
                flex-grow: 1; /* Allow main to grow to fill space */
                /* Adjusted padding-top to account for sticky header */
                padding-top: 80px; /* Assuming header height around 64-70px + some buffer for sticky */ 
                /* Padding-bottom to ensure pagination is above fixed mobile nav */
                padding-bottom: 100px; /* Adjust based on mobile nav height */ 
            }
            /* Adjust filter forms for small screens */
            .flex-col.md\:flex-row.gap-4.items-center {
                flex-direction: column;
                align-items: stretch;
            }
            .w-full.md\:w-auto, .w-full.md\:w-48 {
                width: 100%;
            }

            /* Ensure mobile navigation is fixed and on top */
            /* This is here for clarity on why main's padding-bottom is important */
            /* The actual fixed properties are in sidebar.php */
            .fixed.bottom-0.left-0.right-0 {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                width: 100%;
                z-index: 50; /* Ensure it's above other content */
            }
        }
        /* Desktop specific padding for main to avoid large top gap */
        @media (min-width: 768px) {
            main {
                padding-top: 1.5rem; /* Default p-6 for desktop */
                padding-bottom: 1.5rem; /* Default p-6 for desktop */
            }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 flex flex-col min-h-screen">
    
    <?php require_once 'header.php'; ?>
    
    <div class="flex flex-1">
        <?php require_once 'sidebar.php'; ?>
        
        <main class="flex-1 p-6">
            <h2 class="text-3xl font-bold text-dark mb-6">System Management</h2>

            <?php if (!empty($message)): ?>
            <div class="mb-4 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo $message; ?>
            </div>
            <?php endif; ?>

            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-4 sm:space-x-8" aria-label="Tabs">
                    <a href="#users" class="sm-tab-link whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm text-gray-500 hover:text-primary hover:border-primary transition-colors">User Management</a>
                    <a href="#payments" class="sm-tab-link whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm text-gray-500 hover:text-primary hover:border-primary transition-colors">Payment History</a>
                    <a href="#manage_prices" class="sm-tab-link whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm text-gray-500 hover:text-primary hover:border-primary transition-colors">Manage Prices</a>
                </nav>
            </div>

            <div id="users" class="tab-content mt-8">
                <div class="bg-white rounded-xl shadow-custom">
                    <div class="p-6 border-b">
                        <form method="GET" action="system_management.php" class="flex flex-col md:flex-row gap-4 items-center">
                            <input type="hidden" name="tab" value="users">
                            <div class="relative flex-grow w-full md:w-auto">
                                <input type="text" name="users_search" placeholder="Search by name or email..." class="border rounded-lg py-2 px-4 pl-10 w-full focus:ring-primary focus:border-primary" value="<?= htmlspecialchars($users_search); ?>">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            </div>
                            <select name="role_filter" class="border rounded-lg py-2 px-4 w-full md:w-48 focus:ring-primary focus:border-primary">
                                <option value="">All Roles</option>
                                <option value="super_admin" <?= $role_filter == 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                                <option value="admin" <?= $role_filter == 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="teacher" <?= $role_filter == 'teacher' ? 'selected' : '' ?>>Teacher</option>
                                <option value="student" <?= $role_filter == 'student' ? 'selected' : '' ?>>Student</option>
                            </select>
                            <button type="submit" class="bg-primary text-white font-semibold py-2 px-6 rounded-lg w-full md:w-auto hover:bg-indigo-700 transition-colors">Filter</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm responsive-table">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="p-4 font-medium text-left text-gray-600">Username</th>
                                    <th class="p-4 font-medium text-left text-gray-600">Email</th>
                                    <th class="p-4 font-medium text-left text-gray-600">Role</th>
                                    <th class="p-4 font-medium text-left text-gray-600">ACCA ID</th>
                                    <th class="p-4 font-medium text-left text-gray-600">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($users_result->num_rows > 0): ?>
                                <?php while($user = $users_result->fetch_assoc()): ?>
                                    <?php
                                        // Determine appropriate view/edit link based on role
                                        if (in_array($user['role'], ['admin', 'super_admin', 'teacher'])) { // Teachers also use edit_profile.php if not students
                                            $view_link = 'edit_profile.php?id=' . $user['id'];
                                            $edit_link = 'edit_profile.php?id=' . $user['id'];
                                        } else { // Students
                                            $view_link = 'view_student.php?id=' . $user['id'];
                                            $edit_link = 'edit_student.php?id=' . $user['id'];
                                        }
                                    ?>
                                    <tr class="border-b hover:bg-gray-50">
                                        <td data-label="Username" class="p-4 font-semibold text-gray-800"><?= htmlspecialchars($user['username']) ?></td>
                                        <td data-label="Email" class="p-4 text-gray-600"><?= htmlspecialchars($user['email']) ?></td>
                                        <td data-label="Role" class="p-4">
                                            <?php 
                                                $role_class = match($user['role']) {
                                                    'super_admin' => 'bg-red-100 text-red-800',
                                                    'admin' => 'bg-indigo-100 text-indigo-800',
                                                    'teacher' => 'bg-sky-100 text-sky-800',
                                                    default => 'bg-gray-100 text-gray-800',
                                                };
                                            ?>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?= $role_class ?>"><?= ucfirst(str_replace('_', ' ', $user['role'])) ?></span>
                                        </td>
                                        <td data-label="ACCA ID" class="p-4 text-gray-600"><?= htmlspecialchars($user['acca_id'] ?? 'N/A') ?></td>
                                        <td data-label="Actions" class="p-4 text-gray-500 space-x-4 flex justify-start sm:justify-end">
                                            <a href="<?= $view_link ?>" class="hover:text-secondary" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="<?= $edit_link ?>" class="hover:text-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                            <a href="delete_user.php?id=<?= $user['id']; ?>" class="hover:text-red-600" title="Delete" onclick="return confirm('Are you sure?');"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center py-10 text-gray-500">No users found for the selected criteria.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_users_pages > 1): ?>
                    <div class="p-4 flex flex-col sm:flex-row justify-between items-center text-sm text-gray-600 border-t">
                        <span>Page <?= $users_page ?> of <?= $total_users_pages ?></span>
                        <div class="flex items-center space-x-1 mt-2 sm:mt-0">
                            <?php if ($users_page > 1): ?>
                                <a href="?users_page=<?= $users_page - 1 ?>&users_search=<?=urlencode($users_search)?>&role_filter=<?=urlencode($role_filter)?>#users" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">«</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_users_pages; $i++): ?>
                                <a href="?users_page=<?= $i ?>&users_search=<?=urlencode($users_search)?>&role_filter=<?=urlencode($role_filter)?>#users" class="px-3 py-1 rounded-md <?= ($i == $users_page) ? 'bg-primary text-white' : 'bg-gray-200 hover:bg-gray-300' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($users_page < $total_users_pages): ?>
                                <a href="?users_page=<?= $users_page + 1 ?>&users_search=<?=urlencode($users_search)?>&role_filter=<?=urlencode($role_filter)?>#users" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">»</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="payments" class="tab-content mt-8 hidden">
                 <div class="bg-white rounded-xl shadow-custom">
                    <div class="p-6 border-b">
                         <form method="GET" action="system_management.php" class="flex flex-col md:flex-row gap-4 items-center">
                            <input type="hidden" name="tab" value="payments">
                            <div class="relative flex-grow w-full md:w-auto">
                                <input type="text" name="payments_search" placeholder="Search by student or invoice..." class="border rounded-lg py-2 px-4 pl-10 w-full focus:ring-primary focus:border-primary" value="<?= htmlspecialchars($payments_search); ?>">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            </div>
                            <select name="date_filter" class="border rounded-lg py-2 px-4 w-full md:w-48 focus:ring-primary focus:border-primary">
                                <option value="">All Time</option>
                                <option value="today" <?= $date_filter == 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= $date_filter == 'week' ? 'selected' : '' ?>>This Week</option>
                                <option value="month" <?= $date_filter == 'month' ? 'selected' : '' ?>>This Month</option>
                                <option value="year" <?= $date_filter == 'year' ? 'selected' : '' ?>>This Year</option>
                            </select>
                            <button type="submit" class="bg-primary text-white font-semibold py-2 px-6 rounded-lg w-full md:w-auto hover:bg-indigo-700 transition-colors">Filter</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm responsive-table">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="p-4 font-medium text-left text-gray-600">Invoice ID</th>
                                    <th class="p-4 font-medium text-left text-gray-600">Student Name</th>
                                    <th class="p-4 font-medium text-left text-gray-600">Fee Type</th>
                                    <th class="p-4 font-medium text-left text-gray-600">Amount Paid</th>
                                    <th class="p-4 font-medium text-left text-gray-600">Paid On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($payments_result->num_rows > 0): ?>
                                <?php while($payment = $payments_result->fetch_assoc()): ?>
                                    <tr class="border-b hover:bg-gray-50">
                                        <td data-label="Invoice ID" class="p-4 font-mono text-xs text-gray-500">
                                           <?= htmlspecialchars($payment['invoice_id'] ?? 'N/A') ?>
                                        </td>
                                        <td data-label="Student Name" class="p-4 font-semibold text-gray-800"><?= htmlspecialchars($payment['username']) ?></td>
                                        <td data-label="Fee Type" class="p-4 text-gray-600"><?= htmlspecialchars(ucfirst($payment['fee_type'])) ?></td>
                                        <td data-label="Amount Paid" class="p-4 font-semibold text-green-600">BDT <?= number_format($payment['amount'], 2) ?></td>
                                        <td data-label="Paid On" class="p-4 text-gray-600"><?= date('M d, Y', strtotime($payment['paid_date'])) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center py-10 text-gray-500">No payments found for the selected criteria.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_payments_pages > 1): ?>
                    <div class="p-4 flex flex-col sm:flex-row justify-between items-center text-sm text-gray-600 border-t">
                        <span>Page <?= $payments_page ?> of <?= $total_payments_pages ?></span>
                        <div class="flex items-center space-x-1 mt-2 sm:mt-0">
                             <?php if ($payments_page > 1): ?>
                                <a href="?payments_page=<?= $payments_page - 1 ?>&payments_search=<?=urlencode($payments_search)?>&date_filter=<?=urlencode($date_filter)?>#payments" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">«</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_payments_pages; $i++): ?>
                                <a href="?payments_page=<?= $i ?>&payments_search=<?=urlencode($payments_search)?>&date_filter=<?=urlencode($date_filter)?>#payments" class="px-3 py-1 rounded-md <?= ($i == $payments_page) ? 'bg-primary text-white' : 'bg-gray-200 hover:bg-gray-300' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                             <?php if ($payments_page < $total_payments_pages): ?>
                                <a href="?payments_page=<?= $payments_page + 1 ?>&payments_search=<?=urlencode($payments_search)?>&date_filter=<?=urlencode($date_filter)?>#payments" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">»</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="manage_prices" class="tab-content mt-8 hidden">
                <div class="bg-white rounded-xl shadow-custom p-6">
                    <h3 class="text-2xl font-bold text-gray-900 mb-6">Edit Fee Type Prices</h3>
                    <div class="space-y-6">
                        <?php if (empty($fee_types_list)): ?>
                            <p class="text-gray-500 text-center py-4">No fee types found to manage.</p>
                        <?php else: ?>
                            <?php foreach ($fee_types_list as $fee_type): ?>
                                <div class="flex flex-col sm:flex-row items-center justify-between p-4 border border-gray-200 rounded-lg bg-gray-50">
                                    <div class="flex-1 mb-2 sm:mb-0">
                                        <p class="font-semibold text-lg text-gray-800"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $fee_type['type_name']))) ?></p>
                                        <?php if ($fee_type['needs_subject']): ?>
                                            <span class="text-sm text-gray-600"><i class="fas fa-info-circle mr-1"></i> Prices handled by individual subjects.</span>
                                        <?php elseif ($fee_type['is_custom_amount']): ?>
                                            <span class="text-sm text-gray-600"><i class="fas fa-info-circle mr-1"></i> Custom amount entered at transaction.</span>
                                        <?php endif; ?>
                                    </div>
                                    <form method="POST" action="system_management.php?tab=manage_prices" class="flex items-center space-x-3 w-full sm:w-auto">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($fee_type['id']) ?>">
                                        <input type="hidden" name="update_fee_type_price" value="1">
                                        <label for="fee_type_price_<?= $fee_type['id'] ?>" class="sr-only">Price for <?= htmlspecialchars($fee_type['type_name']) ?></label>
                                        <input type="number" step="0.01" min="0" 
                                            id="fee_type_price_<?= $fee_type['id'] ?>" 
                                            name="price" 
                                            value="<?= htmlspecialchars(number_format($fee_type['price'], 2, '.', '')) ?>" 
                                            class="w-32 p-2 border border-gray-300 rounded-md shadow-sm text-center font-medium focus:ring-primary focus:border-primary
                                            <?= ($fee_type['needs_subject'] || $fee_type['is_custom_amount']) ? 'bg-gray-200 cursor-not-allowed' : '' ?>"
                                            <?= ($fee_type['needs_subject'] || $fee_type['is_custom_amount']) ? 'disabled' : 'required' ?>>
                                        <button type="submit" 
                                            class="bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition-colors duration-200
                                            <?= ($fee_type['needs_subject'] || $fee_type['is_custom_amount']) ? 'opacity-50 cursor-not-allowed' : '' ?>"
                                            <?= ($fee_type['needs_subject'] || $fee_type['is_custom_amount']) ? 'disabled' : '' ?>>
                                            Update
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <h3 class="text-2xl font-bold text-gray-900 mt-10 mb-6">Edit Subject Prices</h3>
                    <div class="space-y-6">
                        <?php if (empty($fees_subject_list)): ?>
                            <p class="text-gray-500 text-center py-4">No subjects found to manage.</p>
                        <?php else: ?>
                            <?php
                            // Group subjects by category for display
                            $grouped_subjects = [];
                            foreach ($fees_subject_list as $subject_data) {
                                $grouped_subjects[$subject_data['category']][] = $subject_data;
                            }
                            ?>
                            <?php foreach ($grouped_subjects as $category => $subjects): ?>
                                <div class="category-group mb-8 p-4 border border-gray-200 rounded-lg bg-gray-50">
                                    <h4 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200"><?= htmlspecialchars($category); ?></h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <?php foreach ($subjects as $subject): ?>
                                            <div class="flex items-center justify-between p-3 border border-gray-100 rounded-md bg-white shadow-sm">
                                                <p class="font-medium text-gray-800"><?= htmlspecialchars($subject['subject_code']); ?></p>
                                                <form method="POST" action="system_management.php?tab=manage_prices" class="flex items-center space-x-2">
                                                    <input type="hidden" name="id" value="<?= htmlspecialchars($subject['id']) ?>">
                                                    <input type="hidden" name="update_subject_price" value="1">
                                                    <label for="subject_price_<?= $subject['id'] ?>" class="sr-only">Price for <?= htmlspecialchars($subject['subject_code']) ?></label>
                                                    <input type="number" step="0.01" min="0" 
                                                        id="subject_price_<?= $subject['id'] ?>" 
                                                        name="price" 
                                                        value="<?= htmlspecialchars(number_format($subject['price'], 2, '.', '')) ?>" 
                                                        class="w-28 p-1 border border-gray-300 rounded-md shadow-sm text-center font-medium focus:ring-primary focus:border-primary"
                                                        required>
                                                    <button type="submit" class="bg-green-600 text-white py-1.5 px-3 rounded-md hover:bg-green-700 transition-colors duration-200 text-sm">
                                                        Update
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.sm-tab-link');
        const contents = document.querySelectorAll('.tab-content');

        function activateTab(tabHash) {
            tabs.forEach(tab => {
                tab.classList.toggle('active', tab.hash === tabHash);
            });
            contents.forEach(content => {
                content.classList.toggle('hidden', '#' + content.id !== tabHash);
            });
        }

        // Determine current tab from URL hash or 'tab' query parameter
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        let currentHash = '#users'; // Default tab
        if (tabParam) {
            currentHash = '#' + tabParam;
        } else if (window.location.hash) {
            currentHash = window.location.hash;
        }
        
        activateTab(currentHash);

        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                const newHash = this.hash;

                // Build new URL parameters for the specific tab
                const currentUrlParams = new URLSearchParams(window.location.search);
                currentUrlParams.set('tab', newHash.substring(1)); // Set the 'tab' parameter

                // Clear search/filter parameters for other tabs when switching tabs
                if (newHash === '#users') {
                    currentUrlParams.delete('payments_search');
                    currentUrlParams.delete('date_filter');
                    currentUrlParams.delete('payments_page');
                    currentUrlParams.delete('fee_type_id'); // Clear price management related params
                    currentUrlParams.delete('subject_id');
                } else if (newHash === '#payments') {
                    currentUrlParams.delete('users_search');
                    currentUrlParams.delete('role_filter');
                    currentUrlParams.delete('users_page');
                    currentUrlParams.delete('fee_type_id'); // Clear price management related params
                    currentUrlParams.delete('subject_id');
                } else if (newHash === '#manage_prices') {
                    currentUrlParams.delete('users_search');
                    currentUrlParams.delete('role_filter');
                    currentUrlParams.delete('users_page');
                    currentUrlParams.delete('payments_search');
                    currentUrlParams.delete('date_filter');
                    currentUrlParams.delete('payments_page');
                }

                // Update URL without reloading page
                history.pushState(null, '', `?${currentUrlParams.toString()}${newHash}`);
                activateTab(newHash);
            });
        });

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            activateTab(window.location.hash || (tabParam ? '#' + tabParam : '#users'));
        });


        // --- Dropdown Menu Script (from header.php) ---
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
        // --- Mobile Menu Toggle Script (from sidebar.php, now directly here for reliability) ---
        const mobileMoreBtn = document.getElementById('mobile-more-btn');
        const mobileMoreMenu = document.getElementById('mobile-more-menu');

        if (mobileMoreBtn && mobileMoreMenu) {
            mobileMoreBtn.addEventListener('click', (e) => {
                e.preventDefault(); // Prevent default link behavior
                mobileMoreMenu.classList.toggle('hidden');
            });

            // Close the mobile menu if clicked outside
            document.addEventListener('click', (e) => {
                // Check if the click was outside the button and outside the menu itself
                if (!mobileMoreBtn.contains(e.target) && !mobileMoreMenu.contains(e.target)) {
                    mobileMoreMenu.classList.add('hidden');
                }
            });
        }
    });
    </script>
</body>
</html>