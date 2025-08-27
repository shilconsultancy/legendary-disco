<?php
// THIS IS THE COMPLETE AND FINAL CODE FOR lms/admin/upload-resources.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db_config.php'; // Using the standard $conn variable from this file

// --- Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

// --- Define active page for sidebar ---
$active_page = 'resources';

// --- 1. HANDLE FILE UPLOAD ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload_resource'])) {
    if (isset($_FILES["resource_file"]) && $_FILES["resource_file"]["error"] == 0) {
        $subject = trim($_POST['subject']);
        $title = trim($_POST['title']);
        $type = trim($_POST['type']);
        $description = trim($_POST['description']);

        if (empty($subject) || empty($title) || empty($type) || empty($_FILES["resource_file"]["name"])) {
            $_SESSION['message'] = "Error: Please fill in all required fields.";
            $_SESSION['message_type'] = 'error';
        } else {
            $physical_target_dir = "uploads/";
            if (!is_dir($physical_target_dir)) {
                mkdir($physical_target_dir, 0777, true);
            }
            
            $db_path_prefix = "admin/uploads/";
            $original_filename = basename($_FILES["resource_file"]["name"]);
            $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
            $safe_filename = uniqid() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $original_filename);

            $physical_target_path = $physical_target_dir . $safe_filename;
            $db_path = $db_path_prefix . $safe_filename;

            $allowed_extensions = array("pdf", "docx", "pptx", "xlsx", "doc", "ppt", "xls");
            $max_file_size = 50 * 1024 * 1024; // 50 MB

            if (!in_array($file_extension, $allowed_extensions)) {
                $_SESSION['message'] = "Error: Invalid file type.";
                $_SESSION['message_type'] = 'error';
            } elseif ($_FILES["resource_file"]["size"] > $max_file_size) {
                $_SESSION['message'] = "Error: File size is too large (Max 50MB).";
                $_SESSION['message_type'] = 'error';
            } else {
                if (move_uploaded_file($_FILES["resource_file"]["tmp_name"], $physical_target_path)) {
                    $file_size_mb = round($_FILES["resource_file"]["size"] / 1024 / 1024, 2) . " MB";
                    $sql_insert = "INSERT INTO resources (subject, title, type, description, file_path, file_size) VALUES (?, ?, ?, ?, ?, ?)";
                    
                    if ($stmt = $conn->prepare($sql_insert)) {
                        $stmt->bind_param("ssssss", $subject, $title, $type, $description, $db_path, $file_size_mb);
                        if ($stmt->execute()) {
                            $_SESSION['message'] = "File uploaded successfully.";
                            $_SESSION['message_type'] = 'success';
                        } else {
                            $_SESSION['message'] = "Error saving to database: " . $conn->error;
                            $_SESSION['message_type'] = 'error';
                            unlink($physical_target_path);
                        }
                        $stmt->close();
                    }
                } else {
                    $_SESSION['message'] = "Error: Could not move uploaded file. Check folder permissions for lms/admin/uploads/.";
                    $_SESSION['message_type'] = 'error';
                }
            }
        }
    } else {
        $_SESSION['message'] = "Error: No file chosen or an error occurred during upload.";
        $_SESSION['message_type'] = 'error';
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- 2. HANDLE FILE DELETION ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_resource'])) {
    $resource_id = $_POST['resource_id'];
    $sql_select = "SELECT file_path FROM resources WHERE id = ?";
    if ($stmt_select = $conn->prepare($sql_select)) {
        $stmt_select->bind_param("i", $resource_id);
        $stmt_select->execute();
        $result = $stmt_select->get_result();
        $file_data = $result->fetch_assoc();
        $stmt_select->close();

        if ($file_data && !empty($file_data['file_path'])) {
            $physical_file_path = "../" . $file_data['file_path'];
            if (file_exists($physical_file_path)) {
                unlink($physical_file_path);
            }
        }

        $sql_delete = "DELETE FROM resources WHERE id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("i", $resource_id);
            $stmt_delete->execute();
            $_SESSION['message'] = "Resource deleted successfully.";
            $_SESSION['message_type'] = 'success';
            $stmt_delete->close();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- 3. FETCH ALL UPLOADED RESOURCES FOR DISPLAY ---
$all_resources = [];
$sql_fetch = "SELECT id, subject, title, type, file_path, file_size, upload_date FROM resources ORDER BY upload_date DESC";
$result = $conn->query($sql_fetch);
if ($result) {
    $all_resources = $result->fetch_all(MYSQLI_ASSOC);
}

// --- Message Display Logic ---
$message_html = '';
if (isset($_SESSION['message'])) {
    $message_content = htmlspecialchars($_SESSION['message']);
    $message_type = $_SESSION['message_type'];
    $message_class = match ($message_type) {
        'success' => 'bg-green-100 border-l-4 border-green-500 text-green-700',
        'error' => 'bg-red-100 border-l-4 border-red-500 text-red-700',
        default => 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700',
    };
    $message_html = "<div class='{$message_class} p-4 mb-6 rounded-md fade-in' role='alert'><span class='font-medium'>{$message_content}</span></div>";
    unset($_SESSION['message'], $_SESSION['message_type']);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Resources - PSB Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4F46E5', secondary: '#0EA5E9', dark: '#1E293B', light: '#F8FAFC'
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
                    <h2 class="text-2xl font-bold text-gray-800">Resource Management</h2>
                    <p class="text-gray-500 mt-1">Upload and manage all learning materials.</p>
                </div>
            </div>
            
            <?= $message_html ?>

            <div class="bg-white rounded-xl shadow-custom p-6 lg:p-8 mb-8 fade-in">
                <h2 class="text-xl font-bold text-dark mb-4 border-b pb-3">Upload New Resource</h2>
                <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="subject" class="block text-sm font-medium text-gray-700">Subject <span class="text-red-500">*</span></label>
                            <input type="text" name="subject" id="subject" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                        </div>
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" id="title" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                        </div>
                    </div>
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">Type <span class="text-red-500">*</span></label>
                        <select name="type" id="type" required class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                            <option>Textbook</option><option>Notes</option><option>Past Paper</option><option>Question Bank</option>
                        </select>
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea name="description" id="description" rows="3" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"></textarea>
                    </div>
                    <div>
                        <label for="resource_file" class="block text-sm font-medium text-gray-700">File <span class="text-red-500">*</span></label>
                        <input type="file" name="resource_file" id="resource_file" required class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20">
                        <p class="text-xs text-gray-500 mt-1">Allowed: PDF, DOCX, PPTX, XLSX. Max: 50MB.</p>
                    </div>
                    <div class="text-right">
                        <button type="submit" name="upload_resource" class="bg-primary hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg transition duration-300">
                            <i class="fas fa-upload mr-2"></i>Upload Resource
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow-custom p-6 lg:p-8 fade-in" style="animation-delay: 0.1s;">
                <h2 class="text-xl font-bold text-dark mb-4 border-b pb-3">Uploaded Resources</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm responsive-table">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Subject</th>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Title</th>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Type</th>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Size</th>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Uploaded On</th>
                                <th class="p-3 text-left text-sm font-semibold text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y md:divide-y-0 divide-gray-200">
                            <?php if (!empty($all_resources)): ?>
                                <?php foreach ($all_resources as $resource): ?>
                                    <tr>
                                        <td data-label="Subject" class="p-3 font-medium text-gray-800"><?= htmlspecialchars($resource['subject']); ?></td>
                                        <td data-label="Title" class="p-3 text-gray-700"><?= htmlspecialchars($resource['title']); ?></td>
                                        <td data-label="Type" class="p-3 text-gray-600"><?= htmlspecialchars($resource['type']); ?></td>
                                        <td data-label="Size" class="p-3 text-gray-600"><?= htmlspecialchars($resource['file_size']); ?></td>
                                        <td data-label="Uploaded" class="p-3 text-gray-600"><?= date('M d, Y', strtotime($resource['upload_date'])); ?></td>
                                        <td data-label="Actions" class="p-3">
                                            <div class="flex items-center justify-end space-x-4">
                                                <a href="<?= htmlspecialchars($resource['file_path']); ?>" target="_blank" class="text-blue-500 hover:text-blue-700" title="Download"><i class="fas fa-download"></i></a>
                                                <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" onsubmit="return confirm('Are you sure? This action is permanent.');" class="inline">
                                                    <input type="hidden" name="resource_id" value="<?= $resource['id']; ?>">
                                                    <button type="submit" name="delete_resource" class="text-red-500 hover:text-red-700 font-medium text-sm"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="p-4 text-center text-gray-500">No resources uploaded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');
            if(userMenuButton){
                userMenuButton.addEventListener('click', (e) => { e.stopPropagation(); userMenu.classList.toggle('hidden'); });
            }
            document.addEventListener('click', (e) => {
                if (userMenu && !userMenu.classList.contains('hidden') && !userMenuButton.contains(e.target)) {
                    userMenu.classList.add('hidden');
                }
            });

            const mobileMoreBtn = document.getElementById('mobile-more-btn');
            const mobileMoreMenu = document.getElementById('mobile-more-menu');
            if(mobileMoreBtn){
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
