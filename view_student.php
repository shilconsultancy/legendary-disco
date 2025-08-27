<?php
session_start();

// Enable error reporting for debugging.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// This now includes the BASE_URL constant
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

$student = null;
$fees_history = [];
$exams_history = [];
$stats = [
    'total_billed' => 0,
    'total_paid' => 0,
    'total_pending' => 0,
    'exams_scheduled' => 0,
];
$error_message = '';
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($student_id > 0) {
    // 1. Fetch Student's Primary Details
    $stmt_student = $conn->prepare("SELECT id, username, email, acca_id, created_at, profile_image_path FROM users WHERE id = ? AND role = 'student'");
    $stmt_student->bind_param("i", $student_id);
    $stmt_student->execute();
    $result_student = $stmt_student->get_result();
    
    if ($result_student->num_rows === 1) {
        $student = $result_student->fetch_assoc();

        // --- Corrected Image Path Handling ---
        // Use BASE_URL from db_config.php to construct the full path
        $default_image_relative_path = 'admin/assets/images/default_avatar.png'; // Path relative to project root
        $image_relative_path = !empty($student['profile_image_path']) ? $student['profile_image_path'] : $default_image_relative_path;

        // Ensure BASE_URL is defined and correctly configured in db_config.php
        // Example: define('BASE_URL', '/your_project_folder/');
        // Then: $profile_pic = BASE_URL . ltrim($image_relative_path, '/');
        // If BASE_URL already includes "admin/" from outside this folder, adjust.
        // Assuming BASE_URL is like `/projects/psb/` and image_relative_path is `admin/assets/images/foo.png`
        $profile_pic = rtrim(BASE_URL, '/') . '/' . ltrim($image_relative_path, '/');
        // Ensure the path is correct whether 'admin/' is part of BASE_URL or the image_relative_path
        // For robustness, you might want to explicitly strip 'admin/' if it's duplicated.
        $profile_pic = str_replace('//', '/', $profile_pic); // Remove any double slashes


        // 2. Fetch Fee History and Calculate Stats
        $stmt_fees = $conn->prepare("SELECT fee_type, amount, due_date, payment_status FROM fees WHERE student_id = ? ORDER BY due_date DESC");
        $stmt_fees->bind_param("i", $student_id);
        $stmt_fees->execute();
        $result_fees = $stmt_fees->get_result();
        while($row = $result_fees->fetch_assoc()) {
            $fees_history[] = $row;
            $stats['total_billed'] += $row['amount'];
            if ($row['payment_status'] === 'Paid') {
                $stats['total_paid'] += $row['amount'];
            }
        }
        $stats['total_pending'] = $stats['total_billed'] - $stats['total_paid'];
        $stmt_fees->close();

        // 3. Fetch Exam History and Stats
        $stmt_exams = $conn->prepare("SELECT title, exam_date, time_slot, status FROM exams WHERE student_id = ? ORDER BY exam_date DESC");
        $stmt_exams->bind_param("i", $student_id);
        $stmt_exams->execute();
        $result_exams = $stmt_exams->get_result();
        while($row = $result_exams->fetch_assoc()) {
            $exams_history[] = $row;
        }
        $stats['exams_scheduled'] = count($exams_history);
        $stmt_exams->close();

    } else {
        $error_message = "Student not found.";
    }
    $stmt_student->close();
} else {
    $error_message = "No student ID provided.";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Student - PSB Admin</title>
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
        
        /* Responsive Table Styles for mobile */
        @media (max-width: 767px) { /* Changed to max-width: 767px for typical mobile breakpoint */
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
                padding: 1rem; /* Adjust as needed */
            }
            .grid.grid-cols-1.lg\:grid-cols-3.gap-6 {
                grid-template-columns: 1fr; /* Stack columns on small screens */
            }
            .grid.grid-cols-2.md\:grid-cols-4.gap-4 {
                grid-template-columns: 1fr; /* Stack stat cards on small screens */
            }
            .tab-btn {
                padding-left: 1rem;
                padding-right: 1rem;
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
                    <h2 class="text-2xl font-bold text-gray-800">Student Profile</h2>
                    <p class="text-gray-500 mt-1">Viewing profile for Student ID #S<?= htmlspecialchars($student_id); ?></p>
                </div>
                <div class="flex items-center space-x-3 mt-4 sm:mt-0 w-full sm:w-auto">
                    <a href="student_dashboard.php" class="w-1/2 sm:w-auto bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center"><i class="fas fa-arrow-left mr-2"></i> Back</a>
                    <?php if ($student): ?>
                    <a href="edit_student.php?id=<?= $student['id']; ?>" class="w-1/2 sm:w-auto bg-primary hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center justify-center"><i class="fas fa-edit mr-2"></i> Edit</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($student): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-1 bg-white rounded-xl shadow-custom p-6 text-center fade-in">
                    
                    <img src="<?= htmlspecialchars($profile_pic); ?>" alt="Profile Picture" class="w-24 h-24 rounded-full object-cover mx-auto mb-4 border-4 border-white shadow-lg">
                    
                    <h3 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($student['username']); ?></h3>
                    <p class="text-gray-500">ACCA ID: <?= htmlspecialchars($student['acca_id']); ?></p>
                    <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars($student['email']); ?></p>
                    <p class="text-xs text-gray-400 mt-4">Enrolled on: <?= date('M d, Y', strtotime($student['created_at'])); ?></p>
                </div>

                <div class="lg:col-span-2 space-y-6">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 fade-in" style="animation-delay: 0.1s;">
                        <div class="bg-white p-4 rounded-xl shadow-custom text-center"><p class="text-sm text-gray-500">Total Billed</p><p class="text-xl font-bold mt-1">BDT <?= number_format($stats['total_billed'], 2); ?></p></div>
                        <div class="bg-white p-4 rounded-xl shadow-custom text-center"><p class="text-sm text-gray-500">Total Paid</p><p class="text-xl font-bold mt-1 text-green-600">BDT <?= number_format($stats['total_paid'], 2); ?></p></div>
                        <div class="bg-white p-4 rounded-xl shadow-custom text-center"><p class="text-sm text-gray-500">Pending</p><p class="text-xl font-bold mt-1 text-red-600">BDT <?= number_format($stats['total_pending'], 2); ?></p></div>
                        <div class="bg-white p-4 rounded-xl shadow-custom text-center"><p class="text-sm text-gray-500">Exams</p><p class="text-xl font-bold mt-1"><?= $stats['exams_scheduled']; ?></p></div>
                    </div>

                    <div class="bg-white rounded-xl shadow-custom fade-in" style="animation-delay: 0.2s;">
                        <div class="flex border-b" id="history-tabs">
                            <button class="tab-btn px-6 py-4 font-medium text-primary border-b-2 border-primary" data-tab="fees">Fee History</button>
                            <button class="tab-btn px-6 py-4 font-medium text-gray-500" data-tab="exams">Exam History</button>
                        </div>
                        <div class="p-4">
                            <div id="tab-fees" class="tab-content">
                                <div class="overflow-x-auto">
                                <table class="w-full text-sm responsive-table">
                                    <thead class="text-left"><tr class="border-b"><th class="py-2 px-3 font-medium text-gray-600">Type</th><th class="py-2 px-3 font-medium text-gray-600">Amount</th><th class="py-2 px-3 font-medium text-gray-600">Due Date</th><th class="py-2 px-3 font-medium text-gray-600">Status</th></tr></thead>
                                    <tbody class="divide-y md:divide-y-0">
                                        <?php if (!empty($fees_history)): foreach ($fees_history as $fee): ?>
                                        <tr><td data-label="Type" class="py-3 px-3"><?= ucwords(htmlspecialchars($fee['fee_type'])); ?></td><td data-label="Amount" class="py-3 px-3">BDT <?= number_format($fee['amount'], 2); ?></td><td data-label="Due Date" class="py-3 px-3"><?= date('M d, Y', strtotime($fee['due_date'])); ?></td><td data-label="Status" class="py-3 px-3"><span class="text-xs font-medium px-2 py-1 rounded-full <?= match ($fee['payment_status']) { 'Paid' => 'bg-green-100 text-green-800', 'Pending' => 'bg-yellow-100 text-yellow-800', 'Overdue' => 'bg-red-100 text-red-800', default => 'bg-gray-100 text-gray-800' }; ?>"><?= htmlspecialchars($fee['payment_status']); ?></span></td></tr>
                                        <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center py-4 text-gray-500">No fee records found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
                            <div id="tab-exams" class="tab-content hidden">
                                <div class="overflow-x-auto">
                                <table class="w-full text-sm responsive-table">
                                    <thead class="text-left"><tr class="border-b"><th class="py-2 px-3 font-medium text-gray-600">Subject</th><th class="py-2 px-3 font-medium text-gray-600">Date</th><th class="py-2 px-3 font-medium text-gray-600">Time Slot</th><th class="py-2 px-3 font-medium text-gray-600">Status</th></tr></thead>
                                    <tbody class="divide-y md:divide-y-0">
                                        <?php if (!empty($exams_history)): foreach ($exams_history as $exam): ?>
                                        <tr><td data-label="Subject" class="py-3 px-3"><?= htmlspecialchars($exam['title']); ?></td><td data-label="Date" class="py-3 px-3"><?= date('M d, Y', strtotime($exam['exam_date'])); ?></td><td data-label="Time Slot" class="py-3 px-3"><?= htmlspecialchars($exam['time_slot']); ?></td><td data-label="Status" class="py-3 px-3"><span class="text-xs font-medium px-2 py-1 rounded-full <?= match ($exam['status']) { 'Scheduled' => 'bg-blue-100 text-blue-800', 'Completed' => 'bg-green-100 text-green-800', 'Cancelled' => 'bg-red-100 text-red-800', default => 'bg-gray-100 text-gray-800' }; ?>"><?= htmlspecialchars($exam['status']); ?></span></td></tr>
                                        <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center py-4 text-gray-500">No exam records found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <div class="bg-white rounded-xl shadow-custom p-8 text-center"><div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert"><p class="font-bold">Error!</p><p><?= htmlspecialchars($error_message); ?></p></div></div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab functionality
            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    tabButtons.forEach(btn => {
                        btn.classList.remove('text-primary', 'border-primary');
                        btn.classList.add('text-gray-500');
                    });
                    this.classList.add('text-primary', 'border-primary');
                    this.classList.remove('text-gray-500');
                    
                    const targetTab = this.dataset.tab;
                    tabContents.forEach(content => {
                        if (content.id === 'tab-' + targetTab) {
                            content.classList.remove('hidden');
                        } else {
                            content.classList.add('hidden');
                        }
                    });
                });
            });

            // --- Dropdown Menu Script ---
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
            // --- End Dropdown Menu Script ---
        });
    </script>
</body>
</html>