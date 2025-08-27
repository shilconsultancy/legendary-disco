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
$active_page = 'students';

// --- Message Display Logic ---
$message_html = '';
if (isset($_GET['message_type']) && isset($_GET['message'])) {
    $message_content = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['message_type']);
    $message_class = match ($message_type) {
        'success' => 'bg-green-100 border-l-4 border-green-500 text-green-700',
        'error' => 'bg-red-100 border-l-4 border-red-500 text-red-700',
        default => 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700',
    };
    $message_html = "<div class='{$message_class} p-4 mb-6 rounded-md fade-in' role='alert'><span class='font-medium'>{$message_content}</span></div>";
}


// --- Data Fetching & Pagination ---
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$where_clause = "WHERE role = 'student'";
$params = [];
$types = '';

if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    $where_clause .= " AND (username LIKE ? OR email LIKE ? OR acca_id LIKE ?)";
    $params = [$search_term, $search_term, $search_term];
    $types = 'sss';
}

// Fetch Total Students for Pagination
$sql_total = "SELECT COUNT(id) AS total FROM users " . $where_clause;
$stmt_total = $conn->prepare($sql_total);
if (!empty($params)) {
    $stmt_total->bind_param($types, ...$params);
}
$stmt_total->execute();
$total_students = $stmt_total->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_students / $limit);
$stmt_total->close();

// Fetch Student Records for the Current Page
$students_data = [];
$sql_students = "SELECT id, username, email, acca_id, created_at FROM users " . $where_clause . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt_students = $conn->prepare($sql_students);
$current_params = $params;
$current_params[] = $limit;
$current_params[] = $offset;
$current_types = $types . 'ii';

if (!empty($types)) {
    $stmt_students->bind_param($current_types, ...$current_params);
} else {
    $stmt_students->bind_param('ii', $limit, $offset);
}
$stmt_students->execute();
$result_students = $stmt_students->get_result();
while ($row = $result_students->fetch_assoc()) {
    $students_data[] = $row;
}
$stmt_students->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - PSB Admin</title>
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
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #c5c5c5; border-radius: 4px; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.3s ease-out forwards; }
        .active-tab {
            background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%);
            border-left: 4px solid #4F46E5;
            color: #4F46E5;
        }
        .shadow-custom { box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .sidebar-link:hover { background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%); }
        
        /* Responsive Table Styles */
        @media (max-width: 768px) {
            .responsive-table thead { display: none; }
            .responsive-table tr { display: block; margin-bottom: 1rem; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 1rem; }
            .responsive-table td { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px dashed #e5e7eb; }
            .responsive-table td:last-child { border-bottom: none; }
            .responsive-table td::before { content: attr(data-label); font-weight: 600; color: #4b5563; margin-right: 1rem; }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen">

    <?php require_once 'header.php'; ?>

    <div class="flex flex-1">
        
        <?php require_once 'sidebar.php'; ?>

        <main class="flex-1 p-4 sm:p-6 pb-24 md:pb-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Student Management</h2>
                    <p class="text-gray-500 mt-1">View, search, and manage all student records.</p>
                </div>
                <a href="registration.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-primary hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                    <i class="fas fa-user-plus mr-2"></i> Add New Student
                </a>
            </div>

            <?= $message_html ?>

            <div class="bg-white rounded-xl shadow-custom">
                <div class="p-6 border-b flex flex-col sm:flex-row justify-between items-center gap-4">
                    <h3 class="text-lg font-semibold text-gray-800">All Students (<?= $total_students; ?>)</h3>
                    <form method="GET" action="student_dashboard.php" class="relative w-full sm:w-64">
                        <input type="text" name="search" placeholder="Search name, email, ACCA ID..." class="border rounded-lg py-2 px-4 pl-10 w-full" value="<?= htmlspecialchars($search_query); ?>">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm responsive-table">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th class="py-3 px-4 font-medium text-gray-600">Student Name</th>
                                <th class="py-3 px-4 font-medium text-gray-600">ACCA ID</th>
                                <th class="py-3 px-4 font-medium text-gray-600">Email</th>
                                <th class="py-3 px-4 font-medium text-gray-600">Enrolled On</th>
                                <th class="py-3 px-4 font-medium text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y md:divide-y-0">
                            <?php if (!empty($students_data)): ?>
                                <?php foreach ($students_data as $student): ?>
                                    <tr>
                                        <td data-label="Name" class="py-4 px-4 font-semibold text-gray-900"><?= htmlspecialchars($student['username']); ?></td>
                                        <td data-label="ACCA ID" class="py-4 px-4 text-gray-600"><?= htmlspecialchars($student['acca_id']); ?></td>
                                        <td data-label="Email" class="py-4 px-4 text-gray-600"><?= htmlspecialchars($student['email']); ?></td>
                                        <td data-label="Enrolled" class="py-4 px-4 text-gray-600"><?= date('M d, Y', strtotime($student['created_at'])); ?></td>
                                        <td data-label="Actions" class="py-4 px-4 text-gray-500">
                                            <div class="flex items-center justify-end space-x-4">
                                                <a href="view_student.php?id=<?= $student['id']; ?>" class="hover:text-blue-600" title="View Details"><i class="fas fa-eye"></i></a>
                                                <a href="edit_student.php?id=<?= $student['id']; ?>" class="hover:text-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                                <a href="delete_student.php?id=<?= $student['id']; ?>" class="hover:text-red-600" title="Delete" onclick="return confirm('Are you sure you want to delete this student? This action cannot be undone.');"><i class="fas fa-trash"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="py-6 px-4 text-center text-gray-500">No students found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="p-4 border-t flex flex-col sm:flex-row justify-between items-center gap-4">
                    <p class="text-sm text-gray-600">Showing <strong><?= $offset + 1; ?></strong> to <strong><?= $offset + count($students_data); ?></strong> of <strong><?= $total_students; ?></strong> students</p>
                    <div class="flex space-x-1">
                        <?php if ($page > 1): ?><a href="?page=<?= $page - 1; ?>&search=<?= urlencode($search_query); ?>" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">&laquo;</a><?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?><a href="?page=<?= $i; ?>&search=<?= urlencode($search_query); ?>" class="px-3 py-1 rounded-md <?= ($i == $page) ? 'bg-primary text-white' : 'bg-gray-200 hover:bg-gray-300'; ?>"><?= $i; ?></a><?php endfor; ?>
                        <?php if ($page < $total_pages): ?><a href="?page=<?= $page + 1; ?>&search=<?= urlencode($search_query); ?>" class="px-3 py-1 rounded-md bg-gray-200 hover:bg-gray-300">&raquo;</a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Standard Menu Scripts ---
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');
            if (userMenuButton) {
                userMenuButton.addEventListener('click', (e) => { e.stopPropagation(); userMenu.classList.toggle('hidden'); });
            }
            document.addEventListener('click', (e) => {
                if (userMenu && !userMenu.classList.contains('hidden') && !userMenuButton.contains(e.target)) {
                    userMenu.classList.add('hidden');
                }
            });

            const mobileMoreBtn = document.getElementById('mobile-more-btn');
            const mobileMoreMenu = document.getElementById('mobile-more-menu');
            if (mobileMoreBtn) {
                mobileMoreBtn.addEventListener('click', (e) => { e.preventDefault(); mobileMoreMenu.classList.toggle('hidden'); });
            }
             document.addEventListener('click', (e) => {
                if (mobileMoreMenu && !mobileMoreMenu.classList.contains('hidden') && !mobileMoreBtn.contains(e.target)) {
                    mobileMoreMenu.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>
