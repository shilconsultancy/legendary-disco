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

$active_page = 'learning_hub';

// --- HANDLE ALL FORM & ACTION LOGIC (NOW USING POST FOR DELETES) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // --- Delete Notice ---
    if (isset($_POST['delete_notice'])) {
        $id = isset($_POST['notice_id']) ? intval($_POST['notice_id']) : 0;
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM notices WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $_SESSION['message'] = "Notice deleted successfully.";
                $_SESSION['message_type'] = 'success';
            }
            $stmt->close();
        }
        header("Location: learning_hub.php#announcements");
        exit();
    }

    // --- Delete Resource ---
    if (isset($_POST['delete_resource'])) {
        $id = isset($_POST['resource_id']) ? intval($_POST['resource_id']) : 0;
        if ($id > 0) {
            $stmt_select = $conn->prepare("SELECT file_path FROM resources WHERE id = ?");
            $stmt_select->bind_param("i", $id);
            if ($stmt_select->execute()) {
                $result = $stmt_select->get_result();
                if ($row = $result->fetch_assoc()) {
                    if (!empty($row['file_path']) && file_exists($row['file_path'])) {
                        unlink($row['file_path']);
                    }
                }
            }
            $stmt_select->close();

            $stmt_delete = $conn->prepare("DELETE FROM resources WHERE id = ?");
            $stmt_delete->bind_param("i", $id);
            if ($stmt_delete->execute()) {
                $_SESSION['message'] = "Resource deleted successfully.";
                $_SESSION['message_type'] = 'success';
            }
            $stmt_delete->close();
        }
        header("Location: learning_hub.php#resources");
        exit();
    }

    // --- Add Notice ---
    if (isset($_POST['add_notice'])) {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $status = trim($_POST['status']);
        $published_date = trim($_POST['published_date']);
        if (!empty($title) && !empty($content) && !empty($status) && !empty($published_date)) {
            $stmt = $conn->prepare("INSERT INTO notices (title, content, published_date, status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $title, $content, $published_date, $status);
            if ($stmt->execute()) {
                $_SESSION['message'] = "Notice added successfully.";
                $_SESSION['message_type'] = 'success';
            }
            $stmt->close();
        }
        header("Location: learning_hub.php#announcements");
        exit();
    }
    
    // --- Upload Resource ---
    if (isset($_POST['upload_resource'])) {
        if (isset($_FILES["resource_file"]) && $_FILES["resource_file"]["error"] == 0) {
            $subject = trim($_POST['subject']);
            $title = trim($_POST['title']);
            $type = trim($_POST['type']);
            if (!empty($subject) && !empty($title) && !empty($type)) {
                $physical_target_dir = "uploads/";
                if (!is_dir($physical_target_dir)) mkdir($physical_target_dir, 0755, true);
                
                $original_filename = basename($_FILES["resource_file"]["name"]);
                $safe_filename = uniqid() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $original_filename);
                $physical_target_path = $physical_target_dir . $safe_filename;
                
                if (move_uploaded_file($_FILES["resource_file"]["tmp_name"], $physical_target_path)) {
                    $file_size_mb = round($_FILES["resource_file"]["size"] / 1024 / 1024, 2) . " MB";
                    $sql_insert = "INSERT INTO resources (subject, title, type, file_path, file_size) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql_insert);
                    $stmt->bind_param("sssss", $subject, $title, $type, $physical_target_path, $file_size_mb);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = "Resource uploaded successfully.";
                        $_SESSION['message_type'] = 'success';
                    }
                    $stmt->close();
                }
            }
        }
        header("Location: learning_hub.php#resources");
        exit();
    }
}

// --- DATA FETCHING FOR DISPLAY ---
$student_count = $conn->query("SELECT COUNT(id) FROM users WHERE role = 'student'")->fetch_row()[0] ?? 0;
$total_mocks_taken = $conn->query("SELECT COUNT(id) FROM exam_sessions WHERE completed = 1")->fetch_row()[0] ?? 0;
$average_score = round($conn->query("SELECT AVG(score) FROM exam_sessions WHERE completed = 1")->fetch_row()[0] ?? 0, 1);
$avg_mocks_per_student = ($student_count > 0) ? round($total_mocks_taken / $student_count, 1) : 0;
$highest_scores = $conn->query("SELECT t1.subject, t1.score, u.username FROM exam_sessions AS t1 INNER JOIN (SELECT subject, MAX(score) AS max_score FROM exam_sessions WHERE completed = 1 GROUP BY subject) AS t2 ON t1.subject = t2.subject AND t1.score = t2.max_score JOIN users u ON t1.user_id = u.id WHERE t1.completed = 1 GROUP BY t1.subject ORDER BY t1.subject ASC")->fetch_all(MYSQLI_ASSOC);

// Pagination for Notices
$notices_limit = 5;
$notices_page = isset($_GET['notices_page']) ? (int)$_GET['notices_page'] : 1;
$notices_offset = ($notices_page - 1) * $notices_limit;
$total_notices = $conn->query("SELECT COUNT(id) as total FROM notices")->fetch_assoc()['total'] ?? 0;
$total_notices_pages = ceil($total_notices / $notices_limit);
$all_notices = $conn->query("SELECT id, title, status, published_date FROM notices ORDER BY published_date DESC LIMIT $notices_limit OFFSET $notices_offset")->fetch_all(MYSQLI_ASSOC);

// Pagination for Resources
$resources_limit = 5;
$resources_page = isset($_GET['resources_page']) ? (int)$_GET['resources_page'] : 1;
$resources_offset = ($resources_page - 1) * $resources_limit;
$total_resources = $conn->query("SELECT COUNT(id) as total FROM resources")->fetch_assoc()['total'] ?? 0;
$total_resources_pages = ceil($total_resources / $resources_limit);
$all_resources = $conn->query("SELECT id, title, subject, type, file_size, file_path FROM resources ORDER BY id DESC LIMIT $resources_limit OFFSET $resources_offset")->fetch_all(MYSQLI_ASSOC);

$message_html = '';
if (isset($_SESSION['message'])) {
    $message_content = htmlspecialchars($_SESSION['message']);
    $message_type = $_SESSION['message_type'] ?? 'info';
    $message_class = match ($message_type) { 'success' => 'bg-green-100 text-green-700', 'error' => 'bg-red-100 text-red-700', default => 'bg-yellow-100 text-yellow-700', };
    $message_html = "<div class='border-l-4 {$message_class} p-4 mb-6' role='alert'><span class='font-medium'>{$message_content}</span></div>";
    unset($_SESSION['message'], $_SESSION['message_type']);
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Hub - PSB Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { primary: '#4F46E5', secondary: '#0EA5E9', dark: '#1E293B', light: '#F8FAFC' } } } }
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
        .content-tab-active { border-color: #4F46E5; color: #4F46E5; font-weight: 600; }

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
            /* Adjust top section layout (title) for small screens */
            .flex.justify-between.items-center.mb-6 {
                flex-direction: column;
                align-items: stretch;
            }
            /* Adjust KPI cards grid to stack */
            .grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-4.gap-6 {
                grid-template-columns: 1fr;
            }
            /* Adjust form grid layout to stack */
            form .grid.grid-cols-1.md\:grid-cols-2.gap-4 {
                grid-template-columns: 1fr;
            }
            /* Full width buttons in forms */
            form button[type="submit"] {
                width: 100%;
            }
            /* Adjust the content tabs (Manage Announcements / Manage Resources) */
            .mb-6.border-b.border-gray-200 > nav {
                flex-wrap: wrap; /* Allow tabs to wrap */
                justify-content: center; /* Center tabs if they wrap */
                space-x: 0; /* Remove horizontal spacing if custom gap is needed */
                gap: 0.5rem; /* Add a small gap between wrapped tabs */
            }
            .mb-6.border-b.border-gray-200 > nav a {
                flex: 1 1 auto; /* Allow tabs to grow and shrink */
                text-align: center; /* Center text in tabs */
                padding: 0.75rem 0.5rem; /* Adjust padding for smaller tabs */
            }
            /* Pagination navigation in notices/resources section */
            .p-4.flex.justify-end > nav.flex.space-x-1 {
                flex-wrap: wrap;
                justify-content: center;
                margin-top: 1rem; /* Ensure space above pagination */
            }
            .p-4.flex.justify-end > nav.flex.space-x-1 > a {
                margin: 0.25rem; /* Space around individual page numbers */
            }
            /* Table action buttons (View, Edit, Delete) */
            /* This is the key change for consistent button styling in tables */
            .action-buttons-group-table-cell { /* New class for the td containing actions */
                display: flex;
                flex-direction: row; /* Keep buttons in a row for these actions on mobile */
                justify-content: space-around; /* Distribute space evenly */
                align-items: center;
                flex-wrap: wrap; /* Allow wrapping if needed, but aim for single line */
                gap: 0.5rem; /* Small gap between buttons */
            }
            .action-buttons-group-table-cell > a,
            .action-buttons-group-table-cell > form > button { /* Target both links and buttons within forms */
                flex: 1 1 auto; /* Allow items to grow/shrink based on content */
                min-width: 48px; /* Ensure a minimum touch target size */
                padding: 0.5rem 0.75rem; /* Adjust padding for button-like appearance */
                border-radius: 0.375rem; /* Tailwind rounded-md */
                font-weight: 500; /* Medium font weight */
                text-align: center; /* Center text */
                text-decoration: none; /* Remove underline */
                box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); /* Subtle shadow */
                transition: all 0.15s ease-in-out; /* Smooth transitions */
            }
            .action-buttons-group-table-cell > a.text-secondary,
            .action-buttons-group-table-cell > a.text-primary,
            .action-buttons-group-table-cell > form > button.text-red-600 {
                /* Apply background colors for button look */
                background-color: transparent; /* Default to transparent */
            }

            /* Specific button colors (match default Tailwind primary/secondary/danger tones) */
            .action-buttons-group-table-cell a.text-secondary {
                color: #0EA5E9; /* Sky-500 */
                border: 1px solid #0EA5E9;
            }
            .action-buttons-group-table-cell a.text-secondary:hover {
                background-color: #E0F2FE; /* Sky-100 */
            }
            .action-buttons-group-table-cell a.text-primary {
                color: #4F46E5; /* Indigo-600 */
                border: 1px solid #4F46E5;
            }
            .action-buttons-group-table-cell a.text-primary:hover {
                background-color: #EEF2FF; /* Indigo-100 */
            }
            .action-buttons-group-table-cell button.text-red-600 {
                color: #DC2626; /* Red-600 */
                border: 1px solid #DC2626;
            }
            .action-buttons-group-table-cell button.text-red-600:hover {
                background-color: #FEE2E2; /* Red-100 */
            }

            /* For the "inline" form that contains the delete button */
            table .inline {
                display: flex; /* Make it a flex container so button within it aligns */
            }

            /* Pagination container (the div that holds "Showing X of Y results" and the page numbers) */
            .pagination-container-responsive { /* New class added to this div */
                flex-direction: column; /* Stack count and nav vertically */
                align-items: center; /* Center items when stacked */
                gap: 0.5rem; /* Space between stacked elements */
            }
            .pagination-container-responsive > p.hidden.sm\:block {
                display: block !important; /* Force visibility on mobile if needed, though original had hidden sm:block */
                text-align: center;
            }
            .pagination-container-responsive > nav.flex.space-x-1.mt-2.sm\:mt-0 {
                margin-top: 0 !important; /* Remove specific top margin if stacked */
            }
        }

        /* Desktop specific padding for main */
        @media (min-width: 768px) {
            main {
                padding-top: 1.5rem; /* Default p-6 for desktop */
                padding-bottom: 1.5rem; /* Default p-6 for desktop */
            }
            /* Restore desktop specific flex behavior for tables */
            table .p-3.space-x-3.whitespace-nowrap {
                flex-direction: row; /* Horizontal actions on desktop */
                justify-content: flex-start; /* Align to start */
                gap: 0;
                space-x: 0.75rem; /* Restore space-x-3 */
            }
             /* Remove mobile-specific button styles on desktop */
            .action-buttons-group-table-cell > a,
            .action-buttons-group-table-cell > form > button {
                padding: 0; /* Reset padding to allow original text styling */
                border-radius: 0;
                box-shadow: none;
                flex: none; /* Do not grow/shrink */
                min-width: auto;
                text-align: left;
            }
            .action-buttons-group-table-cell > a.text-secondary,
            .action-buttons-group-table-cell > a.text-primary,
            .action-buttons-group-table-cell > form > button.text-red-600 {
                background-color: transparent; /* Ensure no background */
                border: none; /* Remove border */
            }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 flex flex-col min-h-screen">
    <?php require_once 'header.php'; ?>
    <div class="flex flex-1">
        <?php require_once 'sidebar.php'; ?>
        <main class="flex-1 p-6">
            <div class="flex justify-between items-center mb-6">
                 <div>
                    <h2 class="text-2xl font-bold text-gray-800">Learning Hub</h2>
                    <p class="text-gray-600">Manage announcements, resources, and view performance metrics.</p>
                </div>
            </div>
            <?= $message_html ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">Total Students</p><h3 class="text-3xl font-bold mt-1"><?= $student_count; ?></h3></div>
                <div class="bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">Total Mocks Taken</p><h3 class="text-3xl font-bold mt-1"><?= $total_mocks_taken; ?></h3></div>
                <div class="bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">Average Score</p><h3 class="text-3xl font-bold mt-1"><?= $average_score; ?>%</h3></div>
                <div class="bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">Avg. Mocks/Student</p><h3 class="text-3xl font-bold mt-1"><?= $avg_mocks_per_student; ?></h3></div>
            </div>
            <div class="bg-white rounded-xl shadow-md p-6 lg:p-8 mb-8">
                 <h2 class="text-xl font-bold text-dark mb-4 border-b pb-3">Top Performers by Subject</h2>
                <div class="overflow-x-auto responsive-table">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="p-3 text-left" data-label="Subject">Subject</th>
                                <th class="p-3 text-left" data-label="Top Score">Top Score</th>
                                <th class="p-3 text-left" data-label="Student Name">Student Name</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($highest_scores as $score): ?>
                                <tr>
                                    <td data-label="Subject" class="p-3 font-medium"><?= htmlspecialchars($score['subject']); ?></td>
                                    <td data-label="Top Score" class="p-3 font-bold text-green-600"><?= htmlspecialchars($score['score']); ?>%</td>
                                    <td data-label="Student Name" class="p-3"><?= htmlspecialchars($score['username']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mb-6 border-b border-gray-200">
                <nav class="flex flex-wrap -mb-px space-x-0 md:space-x-6" aria-label="Tabs">
                    <a href="#announcements" class="tab-link py-4 px-1 border-b-2 font-medium text-sm flex-1 md:flex-none mb-2 md:mb-0"><i class="fas fa-bullhorn mr-2"></i>Manage Announcements</a>
                    <a href="#resources" class="tab-link py-4 px-1 border-b-2 font-medium text-sm flex-1 md:flex-none mb-2 md:mb-0"><i class="fas fa-book-open mr-2"></i>Manage Resources</a>
                </nav>
            </div>
            <div id="announcements" class="tab-content space-y-8">
                <div class="bg-white rounded-xl shadow-md p-6 lg:p-8"><h2 class="text-xl font-bold text-dark mb-4">Add New Announcement</h2><form action="learning_hub.php#announcements" method="post" class="space-y-4"><div><label for="title" class="block text-sm font-medium">Title</label><input type="text" name="title" id="title" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div><div><label for="content" class="block text-sm font-medium">Content</label><textarea name="content" id="content" rows="4" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></textarea></div><div class="grid grid-cols-1 md:grid-cols-2 gap-4"><div><label for="published_date" class="block text-sm font-medium">Publish Date</label><input type="date" name="published_date" id="published_date" required value="<?= date('Y-m-d') ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div><div><label for="status" class="block text-sm font-medium">Status</label><select name="status" id="status" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"><option value="Published">Published</option><option value="Scheduled">Scheduled</option><option value="Draft">Draft</option></select></div></div><div class="text-center md:text-right"><button type="submit" name="add_notice" class="bg-secondary hover:bg-sky-600 text-white font-bold py-2 px-6 rounded-lg">Post</button></div></form></div>
                <div class="bg-white rounded-xl shadow-md p-6 lg:p-8"><h2 class="text-xl font-bold text-dark mb-4">Existing Announcements</h2><div class="overflow-x-auto responsive-table"><table class="w-full text-sm"><thead class="bg-gray-50"><tr><th class="p-3 text-left font-semibold" data-label="Title">Title</th><th class="p-3 text-left" data-label="Status">Status</th><th class="p-3 text-left" data-label="Publish Date">Publish Date</th><th class="p-3 text-left" data-label="Actions">Actions</th></tr></thead><tbody class="divide-y divide-gray-200"><?php foreach ($all_notices as $notice): ?><tr><td data-label="Title" class="p-3 font-medium"><?= htmlspecialchars($notice['title']); ?></td><td data-label="Status" class="p-3"><span class="px-2 py-1 text-xs font-semibold rounded-full <?= match($notice['status']){'Published' => 'bg-green-100 text-green-800','Scheduled' => 'bg-indigo-100 text-indigo-800',default => 'bg-yellow-100 text-yellow-800'}; ?>"><?= htmlspecialchars($notice['status']); ?></span></td><td data-label="Publish Date" class="p-3"><?= date('M d, Y', strtotime($notice['published_date'])); ?></td><td data-label="Actions" class="p-3 space-x-3 whitespace-nowrap action-buttons-group-table-cell"><a href="view_notice.php?id=<?= $notice['id']; ?>" class="font-medium text-secondary hover:underline">View</a><a href="edit_notice.php?id=<?= $notice['id']; ?>" class="font-medium text-primary hover:underline">Edit</a><form method="POST" action="learning_hub.php#announcements" onsubmit="return confirm('Are you sure?');" class="inline"><input type="hidden" name="notice_id" value="<?= $notice['id'] ?>"><button type="submit" name="delete_notice" class="font-medium text-red-600 hover:underline">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php if ($total_notices_pages > 1): ?><div class="p-4 flex justify-end"><div class="p-4 flex flex-col sm:flex-row justify-between items-center gap-4 text-sm w-full pagination-container-responsive"><p class="text-sm text-gray-600 hidden sm:block">Showing <strong><?= $notices_offset + 1; ?></strong> to <strong><?= min($notices_offset + $notices_limit, $total_notices); ?></strong> of <strong><?= $total_notices; ?></strong> notices</p><nav class="flex space-x-1 mt-2 sm:mt-0"><?php for ($i = 1; $i <= $total_notices_pages; $i++): ?><a href="?notices_page=<?= $i ?>#announcements" class="px-3 py-1 rounded-md text-sm <?= $i == $notices_page ? 'bg-primary text-white' : 'bg-gray-200 hover:bg-gray-300'; ?>"><?= $i ?></a><?php endfor; ?></nav></div></div><?php endif; ?></div>
            </div>
            <div id="resources" class="tab-content space-y-8 hidden">
                <div class="bg-white rounded-xl shadow-md p-6 lg:p-8">
                    <h2 class="text-xl font-bold text-dark mb-4">Upload New Resource</h2>
                    <form action="learning_hub.php#resources" method="post" enctype="multipart/form-data" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div><label for="res_subject" class="block text-sm font-medium">Subject</label><input type="text" name="subject" id="res_subject" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                            <div><label for="res_title" class="block text-sm font-medium">Title</label><input type="text" name="title" id="res_title" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"></div>
                        </div>
                        <div>
                            <label for="res_type" class="block text-sm font-medium">Type</label>
                            <select name="type" id="res_type" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <option>Notes</option>
                                <option>Past Paper</option>
                                <option>Textbook</option>
                                <option>Question Bank</option>
                            </select>
                        </div>
                        <div>
                            <label for="res_file" class="block text-sm font-medium">File</label>
                            <input type="file" name="resource_file" id="res_file" required class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:bg-gray-50 hover:file:bg-gray-100">
                        </div>
                        <div class="text-center md:text-right"><button type="submit" name="upload_resource" class="bg-primary hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg">Upload</button></div>
                    </form>
                </div>
                <div class="bg-white rounded-xl shadow-md p-6 lg:p-8">
                    <h2 class="text-xl font-bold text-dark mb-4">Uploaded Resources</h2>
                    <div class="overflow-x-auto responsive-table"><table class="w-full text-sm"><thead class="bg-gray-50"><tr><th class="p-3 text-left" data-label="Title">Title</th><th class="p-3 text-left" data-label="Subject">Subject</th><th class="p-3 text-left" data-label="Type">Type</th><th class="p-3 text-left" data-label="Size">Size</th><th class="p-3 text-left" data-label="Actions">Actions</th></tr></thead><tbody class="divide-y divide-gray-200"><?php foreach ($all_resources as $resource): ?><tr><td class="p-3"><a href="<?= htmlspecialchars($resource['file_path']) ?>" target="_blank" class="hover:underline text-primary"><?= htmlspecialchars($resource['title']); ?></a></td><td class="p-3"><?= htmlspecialchars($resource['subject']); ?></td><td class="p-3"><?= htmlspecialchars($resource['type']); ?></td><td class="p-3"><?= htmlspecialchars($resource['file_size']); ?></td><td class="p-3 space-x-3 whitespace-nowrap action-buttons-group-table-cell"><a href="edit_resource.php?id=<?= $resource['id']; ?>" class="font-medium text-primary hover:underline">Edit</a><form method="POST" action="learning_hub.php#resources" onsubmit="return confirm('Are you sure?');" class="inline"><input type="hidden" name="resource_id" value="<?= $resource['id'] ?>"><button type="submit" name="delete_resource" class="font-medium text-red-600 hover:underline">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php if ($total_resources_pages > 1): ?><div class="p-4 flex justify-end"><div class="p-4 flex flex-col sm:flex-row justify-between items-center gap-4 text-sm w-full pagination-container-responsive"><p class="text-sm text-gray-600 hidden sm:block">Showing <strong><?= $resources_offset + 1; ?></strong> to <strong><?= min($resources_offset + $resources_limit, $total_resources); ?></strong> of <strong><?= $total_resources; ?></strong> resources</p><nav class="flex space-x-1 mt-2 sm:mt-0"><?php for ($i = 1; $i <= $total_resources_pages; $i++): ?><a href="?resources_page=<?= $i ?>#resources" class="px-3 py-1 rounded-md text-sm <?= $i == $resources_page ? 'bg-primary text-white' : 'bg-gray-200 hover:bg-gray-300'; ?>"><?= $i ?></a><?php endfor; ?></nav></div></div><?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- User profile dropdown toggle ---
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenu = document.getElementById('user-menu');
        if(userMenuButton) { userMenuButton.addEventListener('click', (e) => { e.stopPropagation(); userMenu.classList.toggle('hidden'); }); }
        document.addEventListener('click', (e) => { if (userMenu && !userMenu.classList.contains('hidden') && !userMenuButton.contains(e.target)) { userMenu.classList.add('hidden'); } });

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

        // --- Tabs Functionality (already present and robust) ---
        const tabs = document.querySelectorAll('.tab-link');
        const contents = document.querySelectorAll('.tab-content');
        function activateTab(tabHash) {
            tabs.forEach(tab => {
                tab.classList.toggle('content-tab-active', tab.hash === tabHash);
                tab.classList.toggle('border-transparent', tab.hash !== tabHash);
                tab.classList.toggle('text-gray-500', tab.hash !== tabHash);
            });
            contents.forEach(content => { content.classList.toggle('hidden', '#' + content.id !== tabHash); });
        }
        const currentHash = window.location.hash || '#announcements';
        activateTab(currentHash);
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                activateTab(this.hash);
                history.pushState(null, '', this.hash);
            });
        });
        window.addEventListener('popstate', () => activateTab(window.location.hash || '#announcements'));
    });
    </script>
</body>
</html>