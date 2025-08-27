<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';

if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) { die("DB connection error."); }
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

$active_page = 'invoicing';
$message_html = '';
if (isset($_GET['message']) && isset($_GET['message_type'])) {
    $message_content = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['message_type']);
    $message_class = match ($message_type) {
        'success' => 'bg-green-100 border-l-4 border-green-500 text-green-700',
        'error' => 'bg-red-100 border-l-4 border-red-500 text-red-700',
        default => 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700',
    };
    $message_html = "<div class='{$message_class} p-4 mb-6 rounded-md fade-in' role='alert'><span class='font-medium'>{$message_content}</span></div>";
}

// --- Data Fetching, Filtering, Sorting & Pagination Logic ---
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$allowed_statuses = ['Paid', 'Pending', 'Overdue', 'Cancelled'];
$filter_status = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses) ? $_GET['status'] : '';

// Ensure sort_by is valid and handle default for sorting
$sort_columns = ['issue_date', 'due_date', 'total_amount', 'username', 'invoice_number']; // Added invoice_number for sorting
$sort_by = isset($_GET['sort_by']) && in_array($_GET['sort_by'], $sort_columns) ? $_GET['sort_by'] : 'issue_date';
$sort_order = isset($_GET['sort_order']) && strtolower($_GET['sort_order']) === 'asc' ? 'ASC' : 'DESC';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    $where_clauses[] = "(inv.invoice_number LIKE ? OR u.username LIKE ?)";
    array_push($params, $search_term, $search_term);
    $types .= 'ss';
}
if (!empty($filter_status)) {
    $where_clauses[] = "inv.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}
$where_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

// --- Data Fetching ---
$sql_total = "SELECT COUNT(inv.id) AS total FROM invoices inv JOIN users u ON inv.student_id = u.id" . $where_sql;
$stmt_total = $conn->prepare($sql_total);
if (!empty($params)) $stmt_total->bind_param($types, ...$params);
$stmt_total->execute();
$total_invoices = $stmt_total->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_invoices / $limit);
$stmt_total->close();

$invoices_data = [];
$sql_invoices = "SELECT inv.id, u.username, u.acca_id, inv.invoice_number, inv.issue_date, inv.due_date, inv.total_amount, inv.status
    FROM invoices inv
    JOIN users u ON inv.student_id = u.id
    $where_sql
    ORDER BY $sort_by $sort_order, inv.id DESC
    LIMIT ? OFFSET ?";
$stmt_invoices = $conn->prepare($sql_invoices);

if (!empty($params)) {
    $current_params = array_merge($params, [$limit, $offset]);
    $current_types = $types . 'ii';
    $stmt_invoices->bind_param($current_types, ...$current_params);
} else {
    $stmt_invoices->bind_param('ii', $limit, $offset);
}
$stmt_invoices->execute();
$result_invoices = $stmt_invoices->get_result();
while ($row = $result_invoices->fetch_assoc()) {
    $invoices_data[] = $row;
}
$stmt_invoices->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoicing Management - PSB Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#4F46E5', secondary: '#0EA5E9', dark: '#1E293B', light: '#F8FAFC' }
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
        .active-tab { background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%); border-left: 4px solid #4F46E5; color: #4F46E5; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: #fefefe; margin: auto; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 450px; }

        /* Responsive Table Styles for mobile */
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
                /* Padding-top for sticky header (same height as header, e.g. 64px from py-4 header) */
                padding-top: 80px; /* Adjust if your header height changes */
                /* Padding-bottom to ensure pagination is above fixed mobile nav */
                padding-bottom: 100px; /* Adjust based on mobile nav height */
            }
            /* Adjust filter forms for small screens */
            .flex-col.md\:flex-row.gap-4 {
                flex-direction: column;
                align-items: stretch;
            }
            .w-full.md\:w-auto {
                width: 100%;
            }
            /* Ensure the action button is full width on mobile */
            .w-full.sm\:w-auto.mt-4.sm\:mt-0 {
                width: 100%;
            }
             /* Align actions to the left on mobile tables */
            .flex.items-center.space-x-3 {
                justify-content: flex-start;
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
<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen">
    <?php require_once 'header.php'; ?>
    <div class="flex flex-1">
        <?php require_once 'sidebar.php'; ?>
        <main class="flex-1 p-4 sm:p-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Invoicing Management</h2>
                    <p class="text-gray-500 mt-1">Create, view, and manage invoices.</p>
                </div>
                <a href="add_fee.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-primary hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                    <i class="fas fa-plus-circle mr-2"></i> Generate Invoice
                </a>
            </div>
            <?= $message_html ?>
            <div class="bg-white rounded-xl shadow-custom">
                <div class="p-6 border-b">
                     <form method="GET" action="invoicing.php" class="flex flex-col md:flex-row gap-4">
                        <div class="relative flex-grow">
                            <input type="text" name="search" placeholder="Search by invoice # or student..." class="border rounded-lg py-2 px-4 pl-10 w-full focus:ring-primary focus:border-primary" value="<?= htmlspecialchars($search_query) ?>">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <select name="status" class="border rounded-lg py-2 px-4 w-full md:w-auto focus:ring-primary focus:border-primary">
                            <option value="">All Statuses</option>
                             <?php foreach ($allowed_statuses as $status): ?>
                            <option value="<?= $status ?>" <?= ($filter_status === $status) ? 'selected' : '' ?>><?= $status ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="bg-primary text-white font-semibold py-2 px-6 rounded-lg w-full md:w-auto hover:bg-indigo-700">Filter</button>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm responsive-table">
                        <thead class="bg-gray-50 text-left">
                           <tr>
                                <th class="py-3 px-4 font-medium text-gray-600">Invoice #</th>
                                <th class="py-3 px-4 font-medium text-gray-600"><a href="?<?= http_build_query(array_merge($_GET, ['sort_by' => 'username', 'sort_order' => ($sort_by == 'username' && $sort_order == 'asc') ? 'desc' : 'asc'])) ?>">Student Name</a></th>
                                <th class="py-3 px-4 font-medium text-gray-600"><a href="?<?= http_build_query(array_merge($_GET, ['sort_by' => 'total_amount', 'sort_order' => ($sort_by == 'total_amount' && $sort_order == 'asc') ? 'desc' : 'asc'])) ?>">Amount</a></th>
                                <th class="py-3 px-4 font-medium text-gray-600"><a href="?<?= http_build_query(array_merge($_GET, ['sort_by' => 'issue_date', 'sort_order' => ($sort_by == 'issue_date' && $sort_order == 'asc') ? 'desc' : 'asc'])) ?>">Issue Date</a></th>
                                <th class="py-3 px-4 font-medium text-gray-600"><a href="?<?= http_build_query(array_merge($_GET, ['sort_by' => 'due_date', 'sort_order' => ($sort_by == 'due_date' && $sort_order == 'asc') ? 'desc' : 'asc'])) ?>">Due Date</a></th>
                                <th class="py-3 px-4 font-medium text-gray-600">Status</th>
                                <th class="py-3 px-4 font-medium text-gray-600">Actions</th>
                           </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($invoices_data)): foreach ($invoices_data as $invoice): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td data-label="Invoice #" class="p-4 font-mono text-gray-600"><?= htmlspecialchars($invoice['invoice_number']); ?></td>
                                    <td data-label="Student Name" class="p-4 font-semibold text-gray-900"><?= htmlspecialchars($invoice['username']); ?> <span class="block text-gray-500 font-normal"><?= htmlspecialchars($invoice['acca_id']); ?></span></td>
                                    <td data-label="Amount" class="p-4 text-gray-600">BDT <?= number_format($invoice['total_amount'], 2); ?></td>
                                    <td data-label="Issue Date" class="p-4 text-gray-600"><?= date('M d, Y', strtotime($invoice['issue_date'])); ?></td>
                                    <td data-label="Due Date" class="p-4 text-gray-600"><?= date('M d, Y', strtotime($invoice['due_date'])); ?></td>
                                    <td data-label="Status" class="p-4">
                                        <span class="text-xs font-medium px-2 py-1 rounded-full <?= match ($invoice['status']) { 'Paid' => 'bg-green-100 text-green-800', 'Pending' => 'bg-yellow-100 text-yellow-800', 'Overdue' => 'bg-red-100 text-red-800', 'Cancelled' => 'bg-gray-100 text-gray-800', default => 'bg-gray-100 text-gray-800' }; ?>"><?= htmlspecialchars($invoice['status']); ?></span>
                                    </td>
                                    <td data-label="Actions" class="p-4 text-gray-500">
                                        <div class="flex items-center space-x-3">
                                            <a href="view_invoice.php?invoice_num=<?= urlencode($invoice['invoice_number']); ?>" class="hover:text-blue-600" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="edit_invoice.php?id=<?= $invoice['id']; ?>" class="hover:text-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="py-10 px-4 text-center text-gray-500">No invoices found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                 <?php if ($total_pages > 1): ?>
                <div class="p-4 border-t flex flex-col sm:flex-row justify-between items-center gap-4 text-sm">
                    <p class="text-sm text-gray-600">Showing <strong><?= $offset + 1; ?></strong> to <strong><?= min($offset + $limit, $total_invoices); ?></strong> of <strong><?= $total_invoices; ?></strong> invoices</p>
                    <div class="flex space-x-1 mt-2 sm:mt-0">
                        <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">&laquo;</a><?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="px-3 py-1 rounded-md <?= ($i == $page) ? 'bg-primary text-white' : 'bg-gray-200 hover:bg-gray-300'; ?>"><?= $i; ?></a><?php endfor; ?>
                        <?php if ($page < $total_pages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">&raquo;</a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <div id="deleteModal" class="modal">
        </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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