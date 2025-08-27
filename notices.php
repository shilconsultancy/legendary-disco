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
$active_page = 'notices';

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

// --- Data Fetching & Pagination ---
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_clause = '';
$params = [];
$types = '';

if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    $where_clause = " WHERE (title LIKE ? OR content LIKE ?)";
    $params = [$search_term, $search_term];
    $types = 'ss';
}

// Fetch Total Notices for Pagination
$sql_total = "SELECT COUNT(id) AS total FROM notices" . $where_clause;
$stmt_total = $conn->prepare($sql_total);
if (!empty($params)) {
    $stmt_total->bind_param($types, ...$params);
}
$stmt_total->execute();
$total_notices = $stmt_total->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_notices / $limit);
$stmt_total->close();

// Fetch Notice Records for the Current Page
$notices_data = [];
$sql_notices = "
    SELECT id, title, content, published_date, status
    FROM notices
    " . $where_clause . "
    ORDER BY published_date DESC, id DESC
    LIMIT ? OFFSET ?
";
$stmt_notices = $conn->prepare($sql_notices);
$current_params = $params;
$current_params[] = $limit;
$current_params[] = $offset;
$current_types = $types . 'ii';

if (!empty($types)) {
    $stmt_notices->bind_param($current_types, ...$current_params);
} else {
    $stmt_notices->bind_param('ii', $limit, $offset);
}
$stmt_notices->execute();
$result_notices = $stmt_notices->get_result();
while ($row = $result_notices->fetch_assoc()) {
    $notices_data[] = $row;
}
$stmt_notices->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notices Management - PSB Admin</title>
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
            /* Adjust filter form layout */
            .p-6.border-b.flex.flex-col.sm\:flex-row.justify-between.items-center.gap-4 {
                flex-direction: column;
                align-items: stretch; /* Stretch items to full width */
            }
            .p-6.border-b.flex.flex-col.sm\:flex-row.justify-between.items-center.gap-4 > * {
                width: 100%; /* Make search input and other items full width */
            }
            /* Align actions to the left on mobile tables */
            td[data-label="Actions"] {
                justify-content: flex-start;
                flex-wrap: wrap; /* Allow action links to wrap */
            }
            td[data-label="Actions"] a, td[data-label="Actions"] form {
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

        <main class="flex-1 p-6 pb-24 md:pb-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Notices & Announcements</h2>
                    <p class="text-gray-600">Manage all institutional notices.</p>
                </div>
                <a href="add_notice.php" class="bg-primary hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-plus-circle mr-2"></i> Add New Notice
                </a>
            </div>

            <?= $message_html ?>

            <div class="bg-white rounded-xl shadow-custom">
                <div class="p-6 border-b flex flex-col sm:flex-row justify-between items-center gap-4">
                    <h3 class="text-lg font-semibold text-gray-800">All Notices (<?= $total_notices; ?>)</h3>
                    <form method="GET" action="notices.php" class="relative w-full sm:w-64">
                        <input type="text" name="search" placeholder="Search notices..." class="border rounded-lg py-2 px-4 pl-10 w-full" value="<?= htmlspecialchars($search_query); ?>">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm responsive-table">
                        <thead class="bg-gray-50 text-left">
                            <tr>
                                <th class="py-3 px-4 font-medium text-gray-600">Title</th>
                                <th class="py-3 px-4 font-medium text-gray-600">Content Snippet</th>
                                <th class="py-3 px-4 font-medium text-gray-600">Published On</th>
                                <th class="py-3 px-4 font-medium text-gray-600">Status</th>
                                <th class="py-3 px-4 font-medium text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php if (!empty($notices_data)): ?>
                                <?php foreach ($notices_data as $notice): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td data-label="Title" class="py-4 px-4 font-semibold text-gray-900"><?= htmlspecialchars($notice['title']); ?></td>
                                        <td data-label="Content Snippet" class="py-4 px-4 text-gray-600"><?= htmlspecialchars(substr($notice['content'], 0, 50)) . (strlen($notice['content']) > 50 ? '...' : ''); ?></td>
                                        <td data-label="Published On" class="py-4 px-4 text-gray-600"><?= date('M d, Y', strtotime($notice['published_date'])); ?></td>
                                        <td data-label="Status" class="py-4 px-4">
                                            <?php
                                                $status_class = match ($notice['status']) {
                                                    'Published' => 'bg-green-100 text-green-800',
                                                    'Draft' => 'bg-gray-200 text-gray-800',
                                                    'Archived' => 'bg-red-100 text-red-800',
                                                    default => 'bg-gray-100 text-gray-800',
                                                };
                                            ?>
                                            <span class="text-xs font-medium px-2 py-1 rounded-full <?= $status_class; ?>"><?= htmlspecialchars($notice['status']); ?></span>
                                        </td>
                                        <td data-label="Actions" class="py-4 px-4 text-gray-500">
                                            <a href="view_notice.php?id=<?= $notice['id']; ?>" class="hover:text-blue-600 mr-3" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="edit_notice.php?id=<?= $notice['id']; ?>" class="hover:text-primary mr-3" title="Edit"><i class="fas fa-edit"></i></a>
                                            <a href="delete_notice.php?id=<?= $notice['id']; ?>" class="hover:text-red-600" title="Delete" onclick="return confirm('Are you sure you want to delete this notice?');"><i class="fas fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="py-6 px-4 text-center text-gray-500">No notices found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="p-4 border-t flex flex-col sm:flex-row justify-between items-center gap-4">
                    <p class="text-sm text-gray-600">Showing <strong><?= $offset + 1; ?></strong> to <strong><?= min($offset + $limit, $total_notices); ?></strong> of <strong><?= $total_notices; ?></strong> notices</p>
                    <div class="flex space-x-1 mt-2 sm:mt-0">
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

            // --- Mobile Menu Toggle Script (Crucial for sidebar visibility on mobile) ---
            const mobileMoreBtn = document.getElementById('mobile-more-btn');
            const mobileMoreMenu = document.getElementById('mobile-more-menu');
            if (mobileMoreBtn) {
                mobileMoreBtn.addEventListener('click', (e) => { e.preventDefault(); mobileMoreMenu.classList.toggle('hidden'); });
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