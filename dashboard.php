<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';

// --- Admin/Super Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php?error=unauthorized');
    exit;
}

$active_page = 'dashboard';

// ====================================================================
// NEW DATA FETCHING FOR RECEPTIONIST DASHBOARD
// ====================================================================

// --- 1. Today's Snapshot Data ---
$today_date = date('Y-m-d');

// Fees collected today
$fees_today_result = $conn->query("SELECT SUM(amount) as total FROM fees WHERE DATE(paid_date) = CURDATE()");
$fees_collected_today = $fees_today_result->fetch_assoc()['total'] ?? 0;

// New students enrolled today
$new_students_today_result = $conn->query("SELECT COUNT(id) as count FROM users WHERE role = 'student' AND DATE(created_at) = CURDATE()");
$new_students_today = $new_students_today_result->fetch_assoc()['count'] ?? 0;

// Exams scheduled for today
$exams_today_result = $conn->query("
    SELECT e.title, e.time_slot, u.username 
    FROM exams e JOIN users u ON e.student_id = u.id 
    WHERE e.exam_date = CURDATE() AND e.status = 'Scheduled' 
    ORDER BY e.time_slot ASC
");

// --- 2. Actionable Lists Data ---

// Students with overdue fees (most overdue first)
$overdue_fees_result = $conn->query("
    SELECT u.id, u.username, u.acca_id, f.amount, f.due_date, DATEDIFF(CURDATE(), f.due_date) as days_overdue
    FROM fees f JOIN users u ON f.student_id = u.id
    WHERE f.payment_status = 'Overdue'
    ORDER BY f.due_date ASC
    LIMIT 5
");

// Recent payments received (last 5)
$recent_payments_result = $conn->query("
    SELECT u.username, f.amount, f.paid_date
    FROM fees f JOIN users u ON f.student_id = u.id
    WHERE f.payment_status = 'Paid'
    ORDER BY f.paid_date DESC
    LIMIT 5
");

// --- 3. Latest Notices ---
$latest_notices_result = $conn->query("
    SELECT title, published_date 
    FROM notices 
    WHERE status = 'Published' AND published_date <= CURDATE() 
    ORDER BY published_date DESC 
    LIMIT 3
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PSB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4F46E5', secondary: '#0EA5E9', dark: '#1E293B', light: '#F8FAFC',
                        success: '#10B981', danger: '#EF4444', warning: '#F59E0B'
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
            background-color: #F1F5F9;
            display: flex; /* Use flexbox for overall layout */
            flex-direction: column; /* Stack header, content, and mobile nav vertically */
        }
        .shadow-custom { box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .action-card { transition: all 0.2s ease-in-out; }
        .action-card:hover { transform: translateY(-4px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }

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
                /* Padding-top for sticky header (header height ~64px + margin) */
                padding-top: 80px; 
                /* Padding-bottom to ensure pagination/content is above fixed mobile nav */
                padding-bottom: 100px; 
            }
            /* Adjust top section layout for small screens */
            .flex-col.md\:flex-row.items-center.justify-between {
                flex-direction: column;
                align-items: stretch; /* Stretch items to full width */
            }
            .w-full.md\:w-auto, .w-full.md\:w-1\/3 {
                width: 100%;
            }
            /* Add vertical margin between stacked elements in the top row */
            .flex-col.md\:flex-row.items-center.justify-between > div,
            .flex-col.md\:flex-row.items-center.justify-between > form,
            .flex-col.md\:flex-row.items-center.justify-between > .flex {
                margin-top: 1rem;
            }
            .flex-col.md\:flex-row.items-center.justify-between > div:first-child {
                margin-top: 0; /* No top margin for the first element */
            }
            .flex.items-stretch.gap-2 {
                flex-wrap: wrap; /* Allow action cards to wrap */
                gap: 0.5rem; /* Reduce gap for tighter fit */
            }
            .action-card {
                flex: 1 1 calc(50% - 0.25rem); /* Two cards per row on mobile */
                max-width: calc(50% - 0.25rem);
            }
            /* Adjust main content grids to stack */
            .grid.grid-cols-1.md\:grid-cols-2.lg\:grid-cols-3.gap-6 {
                grid-template-columns: 1fr; /* Stack KPI cards on small screens */
            }
            .lg\:col-span-2 {
                grid-column: span 1 / span 1; /* Ensure these also stack */
            }
            .grid.grid-cols-1.lg\:grid-cols-2.gap-6 {
                grid-template-columns: 1fr; /* Stack the bottom lists on mobile */
            }
            /* Add spacing between stacked sections (e.g. KPI section and list section) */
            .space-y-6 > div { 
                margin-bottom: 1.5rem; /* Add spacing between stacked cards/lists */
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
<body class="text-gray-800 flex flex-col min-h-screen">
    
    <?php require_once 'header.php'; ?>

    <div class="flex flex-1">
        <?php require_once 'sidebar.php'; ?>

        <main class="flex-1 p-6">
            <div class="bg-white rounded-xl shadow-custom p-6 mb-8 flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="w-full md:w-auto">
                    <h2 id="greeting" class="text-2xl font-bold text-dark">Good Morning!</h2>
                    <p id="current-time" class="text-gray-500"></p>
                </div>
                <div class="w-full md:w-1/3">
                    <form action="student_dashboard.php" method="GET" class="relative">
                        <input type="text" name="search" placeholder="Find a student by name or ID..." class="w-full border-2 border-gray-200 rounded-lg py-3 px-4 pl-12 focus:outline-none focus:border-primary">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-lg"></i>
                    </form>
                </div>
                <div class="flex items-stretch gap-2 w-full md:w-1/3">
                    <a href="registration.php" class="action-card text-center p-3 bg-primary/10 rounded-lg flex-1 flex flex-col justify-center">
                        <i class="fas fa-user-plus text-primary text-2xl"></i>
                        <p class="text-xs font-semibold text-primary mt-1">New Student</p>
                    </a>
                     <a href="add_fee.php" class="action-card text-center p-3 bg-success/10 rounded-lg flex-1 flex flex-col justify-center">
                        <i class="fas fa-money-bill-wave text-success text-2xl"></i>
                        <p class="text-xs font-semibold text-success mt-1">Add Fee</p>
                    </a>
                     <a href="learning_hub.php" class="action-card text-center p-3 bg-secondary/10 rounded-lg flex-1 flex flex-col justify-center">
                        <i class="fas fa-bullhorn text-secondary text-2xl"></i>
                        <p class="text-xs font-semibold text-secondary mt-1">New Notice</p>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <div class="lg:col-span-2 bg-white rounded-xl shadow-custom p-6">
                    <h3 class="text-lg font-bold text-dark mb-4">Exams Scheduled for Today</h3>
                    <div class="space-y-4 max-h-60 overflow-y-auto">
                        <?php if ($exams_today_result && $exams_today_result->num_rows > 0): ?>
                            <?php while($exam = $exams_today_result->fetch_assoc()): ?>
                            <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                                <div class="bg-primary/20 text-primary p-3 rounded-full flex items-center justify-center w-12 h-12">
                                    <i class="fas fa-clock fa-lg"></i>
                                </div>
                                <div class="ml-4 flex-1">
                                    <p class="font-semibold text-dark"><?= htmlspecialchars($exam['title']); ?></p>
                                    <p class="text-sm text-gray-500">Student: <?= htmlspecialchars($exam['username']); ?></p>
                                </div>
                                <span class="font-bold text-primary"><?= htmlspecialchars($exam['time_slot']); ?></span>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-center text-gray-500 py-10">No exams scheduled for today.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="space-y-6">
                    <div class="bg-white rounded-xl shadow-custom p-6">
                        <p class="text-gray-500 text-sm">Fees Collected Today</p>
                        <h3 class="text-3xl font-bold text-success mt-1">BDT <?= number_format($fees_collected_today, 2); ?></h3>
                    </div>
                    <div class="bg-white rounded-xl shadow-custom p-6">
                        <h3 class="text-lg font-bold text-dark mb-3">Latest Notices</h3>
                        <div class="space-y-3">
                        <?php if ($latest_notices_result && $latest_notices_result->num_rows > 0): ?>
                            <?php while($notice = $latest_notices_result->fetch_assoc()): ?>
                                <div class="text-sm">
                                    <p class="font-semibold truncate text-gray-700"><?= htmlspecialchars($notice['title']); ?></p>
                                    <p class="text-xs text-gray-400">Published: <?= date('M d, Y', strtotime($notice['published_date'])) ?></p>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-center text-sm text-gray-500 py-2">No recent notices.</p>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl shadow-custom">
                    <h3 class="text-lg font-bold text-dark p-6 border-b">Students with Overdue Fees</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm responsive-table">
                            <thead class="bg-gray-50"><tr>
                                <th class="p-3 text-left font-medium text-gray-600">Student</th>
                                <th class="p-3 text-left font-medium text-gray-600">Amount Due</th>
                                <th class="p-3 text-left font-medium text-gray-600">Overdue By</th>
                            </tr></thead>
                            <tbody>
                            <?php if ($overdue_fees_result && $overdue_fees_result->num_rows > 0): ?>
                                <?php while($fee = $overdue_fees_result->fetch_assoc()): ?>
                                    <tr class="border-t">
                                        <td data-label="Student" class="p-3"><a href="view_student.php?id=<?= $fee['id'] ?>" class="hover:underline font-semibold text-primary"><?= htmlspecialchars($fee['username']) ?></a></td>
                                        <td data-label="Amount Due" class="p-3">BDT <?= number_format($fee['amount']) ?></td>
                                        <td data-label="Overdue By" class="p-3"><span class="font-bold text-danger"><?= $fee['days_overdue'] ?> days</span></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-gray-500 p-6">No students with overdue fees. Great job!</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                 <div class="bg-white rounded-xl shadow-custom">
                    <h3 class="text-lg font-bold text-dark p-6 border-b">Recent Payments</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm responsive-table">
                           <thead class="bg-gray-50"><tr>
                                <th class="p-3 text-left font-medium text-gray-600">Student</th>
                                <th class="p-3 text-left font-medium text-gray-600">Amount Paid</th>
                                <th class="p-3 text-left font-medium text-gray-600">Date</th>
                            </tr></thead>
                            <tbody>
                            <?php if ($recent_payments_result && $recent_payments_result->num_rows > 0): ?>
                                <?php while($payment = $recent_payments_result->fetch_assoc()): ?>
                                    <tr class="border-t">
                                        <td data-label="Student" class="p-3 font-semibold"><?= htmlspecialchars($payment['username']) ?></td>
                                        <td data-label="Amount Paid" class="p-3 text-success font-medium">BDT <?= number_format($payment['amount']) ?></td>
                                        <td data-label="Date" class="p-3"><?= date('M d, Y', strtotime($payment['paid_date'])) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-gray-500 p-6">No recent payments found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
             </div>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Live Clock & Greeting
        const greetingEl = document.getElementById('greeting');
        const timeEl = document.getElementById('current-time');
        
        function updateTime() {
            const now = new Date();
            const hour = now.getHours();
            
            if (greetingEl) {
                if (hour < 12) {
                    greetingEl.textContent = 'Good Morning!';
                } else if (hour < 18) {
                    greetingEl.textContent = 'Good Afternoon!';
                } else {
                    greetingEl.textContent = 'Good Evening!';
                }
            }
            
            if (timeEl) {
                timeEl.textContent = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) + ' - ' + now.toLocaleTimeString('en-US');
            }
        }
        
        updateTime();
        setInterval(updateTime, 1000);

        // --- Standard Menu Scripts (Header Dropdown) ---
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenu = document.getElementById('user-menu');
        if(userMenuButton){ userMenuButton.addEventListener('click', (e) => { e.stopPropagation(); userMenu.classList.toggle('hidden'); }); }
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