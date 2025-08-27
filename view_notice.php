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

$notice = null;
$error_message = '';
$notice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($notice_id > 0) {
    $stmt = $conn->prepare("
        SELECT id, title, content, published_date, status, created_at, updated_at
        FROM notices
        WHERE id = ?
    ");
    $stmt->bind_param("i", $notice_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $notice = $result->fetch_assoc();
    } else {
        $error_message = "Notice not found.";
    }
    $stmt->close();
} else {
    $error_message = "No notice ID provided.";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Notice - PSB Admin</title>
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
        .notice-content {
            white-space: pre-wrap;
            word-wrap: break-word;
            background-color: #f9fafb;
            padding: 1.5rem;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            line-height: 1.6;
        }

        /* Mobile adjustments for padding and layout, consistent with view_invoice.php */
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
            /* Adjust top section layout (title and action buttons) to stack */
            .flex-col.sm\:flex-row { /* This is on the top-level div for header/buttons */
                flex-direction: column;
                align-items: stretch; /* Stretch items to full width */
            }
            /* Add margin for spacing between stacked elements in the top row */
            .flex-col.sm\:flex-row.justify-between.items-start.sm\:items-center > * {
                margin-top: 1rem;
            }
            .flex-col.sm\:flex-row.justify-between.items-start.sm\:items-center > *:first-child {
                margin-top: 0; /* No top margin for the first element */
            }
            /* --- Button Group Fix (50/50 horizontal with consistent sizing) --- */
            /* This targets the div containing the "Back", "Edit" buttons */
            .action-buttons-group-view { /* Existing class on the wrapping div */
                display: flex; /* Make it a flex container */
                flex-direction: row; /* Keep buttons in a row on mobile */
                justify-content: space-between; /* Distribute space between them */
                align-items: stretch; /* Crucial: Make items stretch to fill height of the tallest */
                gap: 0.5rem; /* Small gap between buttons */
                width: 100%; /* Ensure the container takes full width */
                /* Remove default space-x-3 if it causes issues on small screens for this group */
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            /* Make individual buttons take roughly 50% width with flexibility and ensure consistent internal styling */
            .action-buttons-group-view > a {
                flex: 1 1 48%; /* Allows buttons to grow/shrink, taking roughly 48% each to account for gap */
                max-width: 49%; /* Ensures they don't exceed half-width plus margin */
                text-align: center; /* Center text within each button */
                /* Ensure consistent height and vertical alignment for content within buttons */
                display: flex; /* Make button content a flex container */
                align-items: center; /* Vertically center content */
                justify-content: center; /* Horizontally center content */
                padding-top: 0.75rem; /* Standard py-3 from original buttons */
                padding-bottom: 0.75rem; /* Standard py-3 from original buttons */
            }
            /* Adjust specific button widths that were hardcoded for sm or md sizes */
            .action-buttons-group-view > a.sm\:w-auto {
                width: 100%; /* Ensure they take 100% of available space within the flex item on mobile */
            }


            /* For the text-left and sm:text-right elements within view_invoice.php's top section */
            .text-left.sm\:text-right {
                 text-align: left; /* Align text to left on mobile when stacked */
            }
            .flex.flex-col.sm\:flex-row.justify-between.items-start.mb-8.border-b.pb-6 {
                flex-direction: column;
                align-items: flex-start; /* Align logo and company info to start, and invoice # below it */
            }
            .flex.flex-col.sm\:flex-row.justify-between.items-start.mb-8.border-b.pb-6 > div {
                 width: 100%;
                 margin-top: 1rem;
            }
            .flex.flex-col.sm\:flex-row.justify-between.items-start.mb-8.border-b.pb-6 > div:first-child {
                 margin-top: 0;
            }
        }

        /* Desktop specific padding for main */
        @media (min-width: 768px) {
            main {
                padding-top: 1.5rem; /* Default p-6 for desktop */
                padding-bottom: 1.5rem; /* Default p-6 for desktop */
            }
            /* On desktop, revert button group to original spacing, keeping original w-1/3 logic */
            .action-buttons-group-view {
                flex-direction: row; /* Keep buttons in a row on desktop */
                justify-content: flex-end; /* Align to end */
                gap: 0; /* Remove gap set for mobile if space-x is used */
                space-x: 0.75rem; /* Re-apply original space-x-3 */
                width: auto; /* Allow container to shrink to content width */
            }
            .action-buttons-group-view > a {
                flex: 0 0 auto; /* Do not grow/shrink on desktop */
                width: auto; /* Reset individual button width on desktop */
            }
            /* Specific width for the buttons from original HTML */
            .action-buttons-group-view > a.w-1\/3 {
                 width: 33.333333%; /* Restore original desktop width for the 3-button scenario */
            }
            /* Need to make sure w-1/2 from original is applied correctly for desktop if only 2 buttons */
            .action-buttons-group-view > a.w-1\/2 {
                 width: 50%;
            }
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
                    <h2 class="text-2xl font-bold text-gray-800">Notice Details</h2>
                    <p class="text-gray-500 mt-1">Viewing record for Notice ID #<?= htmlspecialchars($notice_id); ?></p>
                </div>
                <div class="flex items-center space-x-3 mt-4 sm:mt-0 w-full sm:w-auto action-buttons-group-view">
                    <a href="learning_hub.php" class="w-1/2 sm:w-auto bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center"><i class="fas fa-arrow-left mr-2"></i> Back to Learning Hub</a>
                    <?php if ($notice): ?>
                        <a href="edit_notice.php?id=<?= $notice['id']; ?>" class="w-1/2 sm:w-auto bg-primary hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center justify-center"><i class="fas fa-edit mr-2"></i> Edit</a>
                        <?php /* Removed Delete button as per instruction */ ?>
                        <?php endif; ?>
                </div>
            </div>

            <?php if ($notice): ?>
            <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 fade-in max-w-4xl mx-auto">
                <div class="mb-6 border-b pb-4">
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-2"><?= htmlspecialchars($notice['title']); ?></h1>
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center text-sm text-gray-600">
                        <p>Published: <strong><?= date('M d, Y', strtotime($notice['published_date'])); ?></strong></p>
                        <span class="mt-2 sm:mt-0 font-semibold px-3 py-1 rounded-full <?= match ($notice['status']) { 'Published' => 'bg-green-100 text-green-800', 'Draft' => 'bg-gray-200 text-gray-800', 'Archived' => 'bg-red-100 text-red-800', default => 'bg-gray-100 text-gray-800' }; ?>">
                            <?= htmlspecialchars($notice['status']); ?>
                        </span>
                    </div>
                </div>
                
                <div>
                    <h3 class="text-lg font-semibold text-primary mb-3">Notice Content</h3>
                    <div class="notice-content">
                        <?= htmlspecialchars($notice['content']); ?>
                    </div>
                </div>

                <div class="border-t pt-6 mt-8 text-xs text-gray-500">
                    <p>Record Created: <?= date('M d, Y h:i A', strtotime($notice['created_at'])); ?></p>
                    <p>Last Updated: <?= date('M d, Y h:i A', strtotime($notice['updated_at'])); ?></p>
                </div>
            </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-custom p-8 text-center"><div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert"><p class="font-bold">Error!</p><p><?= htmlspecialchars($error_message); ?></p></div></div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- User profile dropdown toggle ---
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');
            if(userMenuButton){ userMenuButton.addEventListener('click', (e) => { e.stopPropagation(); userMenu.classList.toggle('hidden'); }); }
            document.addEventListener('click', (e) => { if (userMenu && !userMenu.classList.contains('hidden') && !userMenuButton.contains(e.target)) { userMenu.classList.add('hidden'); } });

            // --- Mobile Menu Toggle Script (Crucial for sidebar visibility on mobile) ---
            const mobileMoreBtn = document.getElementById('mobile-more-btn');
            const mobileMoreMenu = document.getElementById('mobile-more-menu');
            if(mobileMoreBtn){ mobileMoreBtn.addEventListener('click', (e) => { e.preventDefault(); mobileMoreMenu.classList.toggle('hidden'); }); }
            document.addEventListener('click', (e) => { if (mobileMoreMenu && !mobileMoreMenu.classList.contains('hidden') && !mobileMoreBtn.contains(e.target) && !mobileMoreMenu.contains(e.target)) { mobileMoreMenu.classList.add('hidden'); } });
        });
    </script>
</body>
</html>