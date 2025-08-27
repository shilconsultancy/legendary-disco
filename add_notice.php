<?php
session_start();

// Enable error reporting for debugging.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

// --- Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

// --- Define active page for sidebar ---
$active_page = 'notices';

$add_success = false;
$error_message = '';

// --- Handle Form Submission (Add New Notice) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and retrieve form data
    $title = trim($_POST['title']);
    $content = $_POST['content']; // Get raw content
    $raw_published_date = trim($_POST['published_date']);
    $status = $_POST['status'];

    // Clean content before saving
    $cleaned_content = preg_replace('/^\s+/m', '', $content);
    $cleaned_content = trim($cleaned_content);

    $allowed_statuses = ['Draft', 'Published', 'Archived'];

    // --- Validation ---
    if (empty($title)) {
        $error_message = "Notice title is required.";
    } elseif (empty($cleaned_content)) {
        $error_message = "Notice content is required.";
    } elseif (empty($raw_published_date)) {
        $error_message = "Published date is required.";
    } else {
        $date_parts = explode('-', $raw_published_date);
        if (count($date_parts) === 3 && checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            $published_date_formatted = $raw_published_date;
        } else {
            $error_message = "Invalid published date format or date. Please use YYYY-MM-DD.";
        }
    }

    if (empty($error_message) && !in_array($status, $allowed_statuses)) {
        $error_message = "Invalid status selected.";
    }
    
    if (empty($error_message)) {
        $stmt = $conn->prepare("INSERT INTO notices (title, content, published_date, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $title, $cleaned_content, $published_date_formatted, $status);

        if ($stmt->execute()) {
            header('Location: notices.php?message_type=success&message=' . urlencode('Notice added successfully!'));
            exit;
        } else {
            $error_message = "Error adding notice: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Notice - PSB Admin</title>
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
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in { animation: fadeIn 0.3s ease-out forwards; }
        .active-tab {
            background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%);
            border-left: 4px solid #4F46E5;
            color: #4F46E5;
        }
        .shadow-custom { box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .sidebar-link:hover { background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%); }

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
                    <h2 class="text-2xl font-bold text-gray-800">Add New Notice</h2>
                    <p class="text-gray-600 mt-1">Create a new announcement for the institution.</p>
                </div>
                <a href="notices.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Notices
                </a>
            </div>

            <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 max-w-3xl mx-auto fade-in">
                <?php if (!empty($error_message)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-md relative mb-6" role="alert">
                        <strong class="font-bold">Error!</strong>
                        <span class="block sm:inline"><?php echo $error_message; ?></span>
                    </div>
                <?php endif; ?>

                <form action="add_notice.php" method="POST" class="space-y-6">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Notice Title</label>
                        <input type="text" id="title" name="title" required
                            value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                    </div>

                    <div>
                        <label for="content" class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                        <textarea id="content" name="content" rows="8" required
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"><?php echo htmlspecialchars($_POST['content'] ?? ''); ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="published_date" class="block text-sm font-medium text-gray-700 mb-1">Published Date</label>
                            <input type="date" id="published_date" name="published_date" required
                                value="<?php echo htmlspecialchars($_POST['published_date'] ?? date('Y-m-d')); ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                                <?php
                                $current_status = $_POST['status'] ?? 'Published';
                                ?>
                                <option value="Draft" <?php echo ($current_status == 'Draft') ? 'selected' : ''; ?>>Draft</option>
                                <option value="Published" <?php echo ($current_status == 'Published') ? 'selected' : ''; ?>>Published</option>
                                <option value="Archived" <?php echo ($current_status == 'Archived') ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                    </div>
                        
                    <button type="submit" class="w-full bg-primary hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-md transition ease-in-out duration-150 flex items-center justify-center">
                        <i class="fas fa-plus-circle mr-2"></i>Add Notice
                    </button>
                </form>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- User profile dropdown toggle ---
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

            // --- Mobile menu toggle (Crucial for sidebar visibility on mobile) ---
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