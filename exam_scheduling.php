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
    header('Location: ../index.php');
    exit;
}

// --- Define active page for sidebar ---
$active_page = 'exams';

// --- Message Display Logic ---
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

// --- Data Fetching, Filtering, & Sorting Logic ---
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$allowed_statuses = ['Scheduled', 'Completed', 'Cancelled'];
$filter_status = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses) ? $_GET['status'] : '';

$sort_columns = ['title', 'username', 'exam_date', 'status'];
$sort_by = isset($_GET['sort_by']) && in_array($_GET['sort_by'], $sort_columns) ? $_GET['sort_by'] : 'exam_date';
$sort_order = isset($_GET['sort_order']) && strtolower($_GET['sort_order']) === 'asc' ? 'ASC' : 'DESC';

$where_clauses = [];
$params = [];
$types = '';

if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    $where_clauses[] = "(e.title LIKE ? OR u.username LIKE ? OR u.acca_id LIKE ?)";
    array_push($params, $search_term, $search_term, $search_term);
    $types .= 'sss';
}

if (!empty($filter_status)) {
    $where_clauses[] = "e.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

// Fetch Total Exams for Pagination
$sql_total = "SELECT COUNT(e.id) AS total FROM exams e JOIN users u ON e.student_id = u.id" . $where_sql;
$stmt_total = $conn->prepare($sql_total);
if (!empty($params)) {
    $stmt_total->bind_param($types, ...$params);
}
$stmt_total->execute();
$total_exams = $stmt_total->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_exams / $limit);
$stmt_total->close();

// Fetch Exam Records
$exams_data = [];
$sql_exams = "
    SELECT e.id, u.username, u.acca_id, e.title, e.exam_date, e.time_slot, e.status
    FROM exams e
    JOIN users u ON e.student_id = u.id
    $where_sql
    ORDER BY $sort_by $sort_order, e.id DESC
    LIMIT ? OFFSET ?
";
$stmt_exams = $conn->prepare($sql_exams);
if (!empty($params)) {
    $current_params = array_merge($params, [$limit, $offset]);
    $stmt_exams->bind_param($types . 'ii', ...$current_params);
} else {
    $stmt_exams->bind_param('ii', $limit, $offset);
}
$stmt_exams->execute();
$result_exams = $stmt_exams->get_result();
while ($row = $result_exams->fetch_assoc()) {
    $exams_data[] = $row;
}
$stmt_exams->close();

$upcoming_count = $conn->query("SELECT COUNT(id) FROM exams WHERE status = 'Scheduled' AND exam_date >= CURDATE()")->fetch_row()[0] ?? 0;
$completed_count = $conn->query("SELECT COUNT(id) FROM exams WHERE status = 'Completed'")->fetch_row()[0] ?? 0;
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Management - PSB Admin</title>
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
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #c5c5c5; border-radius: 4px; }
        .active-tab {
            background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%);
            border-left: 4px solid #4F46E5;
            color: #4F46E5;
        }
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
                /* Padding-top for sticky header */
                padding-top: 80px; /* Adjust based on your header's actual height */
                /* Padding-bottom to ensure pagination/content is above fixed mobile nav */
                padding-bottom: 100px; /* Adjust based on mobile nav height */
            }
            /* Adjust top section layout for small screens */
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
            /* Adjust filter forms to stack */
            form.flex.flex-col.md\:flex-row.gap-4 {
                flex-direction: column;
                align-items: stretch;
            }
            form.flex.flex-col.md\:flex-row.gap-4 > * {
                width: 100%;
            }
            /* Adjust grid layouts to stack */
            .grid.grid-cols-1.md\:grid-cols-3.gap-6 {
                grid-template-columns: 1fr;
            }
            /* Align actions to the left on mobile tables */
            td[data-label="Actions"] {
                justify-content: flex-start;
                flex-wrap: wrap; /* Allow action links to wrap */
            }
            td[data-label="Actions"] a {
                margin-bottom: 0.5rem; /* Add spacing between action items */
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

        <main class="flex-1 p-4 sm:p-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Exam Management</h2>
                    <p class="text-gray-500 mt-1">Schedule, view, and manage all exams.</p>
                </div>
                <a href="add_exam.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-primary hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                    <i class="fas fa-plus-circle mr-2"></i> Schedule New Exam
                </a>
            </div>

            <?= $message_html; ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">Total Exams Scheduled</p><h3 class="text-3xl font-bold mt-1"><?= $total_exams; ?></h3></div>
                <div class="bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">Upcoming Exams</p><h3 class="text-3xl font-bold mt-1"><?= $upcoming_count; ?></h3></div>
                <div class="bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">Total Completed</p><h3 class="text-3xl font-bold mt-1"><?= $completed_count; ?></h3></div>
            </div>

            <div class="bg-white rounded-xl shadow-md">
                <div class="p-6 border-b">
                    <form method="GET" action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="flex flex-col md:flex-row gap-4">
                        <div class="relative flex-grow">
                            <input type="text" name="search" placeholder="Search by name, subject, ACCA ID..." class="border rounded-lg py-2 px-4 pl-10 w-full focus:ring-primary focus:border-primary" value="<?= htmlspecialchars($search_query); ?>">
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
                        <thead class="text-left bg-gray-50">
                            <tr>
                                <th class="py-3 px-4 font-medium text-gray-600"><a href="?<?= http_build_query(array_merge($_GET, ['sort_by' => 'username', 'sort_order' => ($sort_by == 'username' && $sort_order == 'asc') ? 'desc' : 'asc'])) ?>">Student Name</a></th>
                                <th class="py-3 px-4 font-medium text-gray-600"><a href="?<?= http_build_query(array_merge($_GET, ['sort_by' => 'title', 'sort_order' => ($sort_by == 'title' && $sort_order == 'asc') ? 'desc' : 'asc'])) ?>">Subject</a></th>
                                <th class="py-3 px-4 font-medium text-gray-600"><a href="?<?= http_build_query(array_merge($_GET, ['sort_by' => 'exam_date', 'sort_order' => ($sort_by == 'exam_date' && $sort_order == 'asc') ? 'desc' : 'asc'])) ?>">Date</a></th>
                                <th class="py-3 px-4 font-medium text-gray-600">Time Slot</th>
                                <th class="py-3 px-4 font-medium text-gray-600"><a href="?<?= http_build_query(array_merge($_GET, ['sort_by' => 'status', 'sort_order' => ($sort_by == 'status' && $sort_order == 'asc') ? 'desc' : 'asc'])) ?>">Status</a></th>
                                <th class="py-3 px-4 font-medium text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php if (!empty($exams_data)): ?>
                                <?php foreach ($exams_data as $exam): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td data-label="Student Name" class="p-4 font-semibold text-gray-900"><?= htmlspecialchars($exam['username']); ?><span class="block text-gray-500 font-normal"><?= htmlspecialchars($exam['acca_id'] ?? 'N/A'); ?></span></td>
                                        <td data-label="Subject" class="p-4 text-gray-600"><?= htmlspecialchars($exam['title']); ?></td>
                                        <td data-label="Date" class="p-4 text-gray-600"><?= date('M d, Y', strtotime($exam['exam_date'])); ?></td>
                                        <td data-label="Time Slot" class="p-4 text-gray-600"><?= htmlspecialchars($exam['time_slot'] ?? 'N/A'); ?></td>
                                        <td data-label="Status" class="p-4">
                                            <?php
                                                $status_class = match ($exam['status']) {
                                                    'Scheduled' => 'bg-blue-100 text-blue-800',
                                                    'Completed' => 'bg-green-100 text-green-800',
                                                    'Cancelled' => 'bg-red-100 text-red-800',
                                                    default => 'bg-gray-100 text-gray-800',
                                                };
                                            ?>
                                            <span class="text-xs font-medium px-2 py-1 rounded-full <?= $status_class; ?>"><?= htmlspecialchars($exam['status']); ?></span>
                                        </td>
                                        <td data-label="Actions" class="p-4 text-gray-500 space-x-3">
                                            <a href="view_exam.php?id=<?= $exam['id']; ?>" class="hover:text-secondary" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="edit_exam.php?id=<?= $exam['id']; ?>" class="hover:text-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="py-10 px-4 text-center text-gray-500">No exam schedules found for the selected criteria.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="p-4 border-t flex flex-col sm:flex-row justify-between items-center gap-4 text-sm">
                    <p class="text-sm text-gray-600">Showing <strong><?= $offset + 1; ?></strong> to <strong><?= min($offset + $limit, $total_exams); ?></strong> of <strong><?= $total_exams; ?></strong> results</p>
                    <div class="flex space-x-1 mt-2 sm:mt-0">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">&laquo;</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="px-3 py-1 rounded-md <?= ($i == $page) ? 'bg-primary text-white' : 'bg-gray-200 hover:bg-gray-300'; ?>"><?= $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">&raquo;</a>
                        <?php endif; ?>
                    </div>
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
                    e.preventDefault();
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