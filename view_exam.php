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

$exam = null;
$error_message = '';
$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($exam_id > 0) {
    // Fetch exam details along with student details
    $stmt = $conn->prepare("
        SELECT 
            e.id, 
            e.student_id,
            u.username AS student_username, 
            u.acca_id AS student_acca_id,
            e.title, 
            e.exam_date, 
            e.time_slot,
            e.status,
            e.created_at,
            e.updated_at
        FROM exams e
        JOIN users u ON e.student_id = u.id
        WHERE e.id = ?
    ");
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $exam = $result->fetch_assoc();
    } else {
        $error_message = "Exam record not found.";
    }
    $stmt->close();
} else {
    $error_message = "No exam ID provided.";
}

// Close the database connection
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Exam - PSB Admin</title>
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
        
        .info-row { display: flex; justify-content: space-between; padding: 0.75rem 0; border-bottom: 1px dashed #e5e7eb; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 500; color: #6b7280; }
        .info-value { font-weight: 600; color: #1f2937; text-align: right; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: #fefefe; margin: auto; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); width: 90%; max-width: 450px; animation: slideIn 0.3s ease-out; }
        @keyframes slideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .close-button { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close-button:hover, .close-button:focus { color: black; }

        /* Mobile adjustments for padding and layout */
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
            /* Adjust top section layout (title and action buttons) */
            .flex-col.sm\:flex-row {
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
            /* Redesign for action button group: stack vertically, full width */
            .action-buttons-group { /* Added this class to the div containing the buttons */
                flex-direction: column; /* Stack buttons vertically */
                align-items: stretch; /* Stretch buttons to full width */
                gap: 0.5rem; /* Space between vertically stacked buttons */
                width: 100%; /* Ensure the container itself takes full width */
            }
            .action-buttons-group > a,
            .action-buttons-group > button {
                width: 100%; /* Make each button full width within its container */
            }
            /* Remove space-x-3 from the action-buttons-group on mobile */
            .action-buttons-group.space-x-3 {
                space-x: 0;
            }

            /* Adjust the grid layout for info sections to stack on small screens */
            .grid.grid-cols-1.md\:grid-cols-2.gap-x-12.gap-y-6 {
                grid-template-columns: 1fr; /* Stack into a single column */
                gap-x: 0; /* Remove horizontal gap when stacked */
                gap-y: 1.5rem; /* Ensure vertical spacing between stacked sections */
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
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Exam Details</h2>
                    <p class="text-gray-500 mt-1">Viewing record for Exam ID #E<?= htmlspecialchars($exam_id); ?></p>
                </div>
                <div class="flex items-center space-x-3 mt-4 sm:mt-0 w-full sm:w-auto action-buttons-group">
                    <a href="exam_scheduling.php" class="w-1/3 sm:w-auto bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center"><i class="fas fa-arrow-left mr-2"></i> Back</a>
                    <?php if ($exam): ?>
                    <a href="edit_exam.php?id=<?= $exam['id']; ?>" class="w-1/3 sm:w-auto bg-primary hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center justify-center"><i class="fas fa-edit mr-2"></i> Edit</a>
                    <button type="button" onclick="openDeleteModal(<?= $exam['id']; ?>)" class="w-1/3 sm:w-auto bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg flex items-center justify-center"><i class="fas fa-trash mr-2"></i> Delete</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($exam): ?>
            <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 fade-in max-w-4xl mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-6">
                    <div>
                        <h3 class="text-lg font-semibold text-primary mb-4 border-b pb-2">Exam Information</h3>
                        <div class="space-y-3">
                            <div class="info-row"><span class="info-label">Title (Subject)</span><span class="info-value"><?= htmlspecialchars($exam['title']); ?></span></div>
                            <div class="info-row"><span class="info-label">Date</span><span class="info-value"><?= date('M d, Y', strtotime($exam['exam_date'])); ?></span></div>
                            <div class="info-row"><span class="info-label">Time Slot</span><span class="info-value"><?= htmlspecialchars($exam['time_slot']); ?></span></div>
                            <div class="info-row"><span class="info-label">Status</span><span class="info-value"><span class="text-sm px-2 py-1 rounded-full <?= match ($exam['status']) { 'Scheduled' => 'bg-blue-100 text-blue-800', 'Completed' => 'bg-green-100 text-green-800', 'Cancelled' => 'bg-red-100 text-red-800', default => 'bg-gray-100 text-gray-800' }; ?>"><?= htmlspecialchars($exam['status']); ?></span></span></div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-primary mb-4 border-b pb-2">Student Information</h3>
                        <div class="space-y-3">
                            <div class="info-row"><span class="info-label">Student Name</span><span class="info-value"><?= htmlspecialchars($exam['student_username']); ?></span></div>
                            <div class="info-row"><span class="info-label">ACCA ID</span><span class="info-value"><?= htmlspecialchars($exam['student_acca_id'] ?? 'N/A'); ?></span></div>
                            <div class="info-row"><span class="info-label">Student ID</span><span class="info-value">#S<?= htmlspecialchars($exam['student_id']); ?></span></div>
                        </div>
                    </div>
                </div>
                <div class="border-t pt-6 mt-8">
                    <h3 class="text-lg font-semibold text-primary mb-4 border-b pb-2">Audit Information</h3>
                    <div class="space-y-3">
                        <div class="info-row"><span class="info-label">Record Created</span><span class="info-value"><?= date('M d, Y h:i A', strtotime($exam['created_at'])); ?></span></div>
                        <div class="info-row"><span class="info-label">Last Updated</span><span class="info-value"><?= date('M d, Y h:i A', strtotime($exam['updated_at'])); ?></span></div>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-custom p-8 text-center"><div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert"><p class="font-bold">Error!</p><p><?= htmlspecialchars($error_message); ?></p></div></div>
            <?php endif; ?>
        </main>
    </div>

    <div id="deleteModal" class="modal"><div class="modal-content"><div class="flex justify-between items-center mb-4"><h3 class="text-xl font-semibold text-gray-800">Confirm Deletion</h3><span class="close-button" onclick="closeDeleteModal()">&times;</span></div><p class="text-gray-600 mb-6">Are you sure? This will permanently delete the exam record. Please enter the super admin password to confirm.</p><form id="deleteExamForm" action="delete_exam.php" method="POST" class="space-y-4"><input type="hidden" id="modalExamId" name="exam_id"><div><label for="superAdminPassword" class="block text-sm font-medium text-gray-700">Super Admin Password</label><input type="password" id="superAdminPassword" name="super_admin_password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-red-500 focus:border-red-500"></div><div class="flex justify-end space-x-3 pt-2"><button type="button" onclick="closeDeleteModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-md">Cancel</button><button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md">Delete Exam</button></div></form></div></div>

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
            
            // --- Modal JavaScript (already here, but ensuring it's robust) ---
            const deleteModal = document.getElementById('deleteModal');
            const modalExamIdInput = document.getElementById('modalExamId');
            window.openDeleteModal = function(examId) { modalExamIdInput.value = examId; deleteModal.style.display = 'flex'; }
            window.closeDeleteModal = function() { deleteModal.style.display = 'none'; }
            window.onclick = function(event) { if (event.target == deleteModal) { closeDeleteModal(); } }
        });
    </script>
</body>
</html>