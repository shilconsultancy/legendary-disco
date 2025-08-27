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

$notice_record = null;
$error_message = '';
$notice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- Fetch Notice Data for Editing ---
if ($notice_id > 0) {
    $stmt = $conn->prepare("SELECT id, title, content, published_date, status FROM notices WHERE id = ?");
    $stmt->bind_param("i", $notice_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $notice_record = $result->fetch_assoc();
    } else {
        $error_message = "Notice record not found.";
    }
    $stmt->close();
} else {
    header('Location: notices.php?message_type=error&message=No Notice ID provided.');
    exit;
}


// --- Handle Form Submission (Update Notice) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && $notice_record) {
    $updated_title = trim($_POST['title']);
    $updated_content = trim($_POST['content']);
    $raw_updated_published_date = trim($_POST['published_date']);
    $updated_status = $_POST['status'];
    
    $allowed_statuses = ['Draft', 'Published', 'Archived'];

    // --- Validation ---
    if (empty($updated_title)) {
        $error_message = "Notice title is required.";
    } elseif (empty($updated_content)) {
        $error_message = "Notice content is required.";
    } else {
        $date_parts = explode('-', $raw_updated_published_date);
        if (count($date_parts) === 3 && checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            $published_date_formatted = $raw_updated_published_date;
        } else {
            $error_message = "Invalid published date format. Please use YYYY-MM-DD.";
        }
    }

    if (empty($error_message) && !in_array($updated_status, $allowed_statuses)) {
        $error_message = "Invalid status selected.";
    }
    
    if (empty($error_message)) {
        $sql_update = "UPDATE notices SET title = ?, content = ?, published_date = ?, status = ? WHERE id = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ssssi", $updated_title, $updated_content, $published_date_formatted, $updated_status, $notice_id);

        if ($stmt_update->execute()) {
            header('Location: notices.php?message_type=success&message=' . urlencode('Notice updated successfully!'));
            exit;
        } else {
            $error_message = "Error updating notice: " . $stmt_update->error;
        }
        $stmt_update->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Notice - PSB Admin</title>
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
                    <h2 class="text-2xl font-bold text-gray-800">Edit Notice Record</h2>
                    <p class="text-gray-500 mt-1">Update details for Notice ID: #<?= htmlspecialchars($notice_id); ?></p>
                </div>
                <a href="learning_hub.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Learning Hub
                </a>
            </div>

            <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 max-w-3xl mx-auto fade-in">
                <?php if ($notice_record): ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-md relative mb-6" role="alert">
                            <strong class="font-bold">Error!</strong>
                            <span class="block sm:inline"><?php echo $error_message; ?></span>
                        </div>
                    <?php endif; ?>

                    <form action="edit_learning_hub.php?id=<?= htmlspecialchars($notice_id); ?>" method="POST" class="space-y-6">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">Notice Title</label>
                            <input type="text" id="title" name="title" required
                                value="<?= htmlspecialchars($notice_record['title']); ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                        </div>

                        <div>
                            <label for="content" class="block text-sm font-medium text-gray-700">Content</label>
                            <textarea id="content" name="content" rows="8" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary"><?= htmlspecialchars($notice_record['content']); ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="published_date" class="block text-sm font-medium text-gray-700">Published Date</label>
                                <input type="date" id="published_date" name="published_date" required
                                    value="<?= htmlspecialchars($notice_record['published_date']); ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                            </div>

                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                <select id="status" name="status" required
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                                    <?php $allowed_statuses = ['Draft', 'Published', 'Archived']; ?>
                                    <?php foreach ($allowed_statuses as $status_option): ?>
                                        <option value="<?= htmlspecialchars($status_option); ?>"
                                            <?= ($notice_record['status'] == $status_option) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($status_option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                            
                        <button type="submit" class="w-full flex justify-center items-center bg-primary hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-md transition ease-in-out duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            <i class="fas fa-save mr-2"></i>Update Notice
                        </button>
                    </form>
                <?php else: ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                        <p class="font-bold">Error!</p>
                        <p><?= htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- User profile dropdown toggle ---
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

            // --- Mobile menu toggle (Crucial for sidebar visibility on mobile) ---
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