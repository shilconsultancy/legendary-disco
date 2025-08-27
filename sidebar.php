<?php
// This file assumes $active_page has been defined on the parent page.
// It also assumes a session has been started and $_SESSION['role'] is available.
?>
<nav class="bg-white w-64 min-h-screen shadow-custom hidden md:block">
    <div class="py-8 px-6">
        <ul class="space-y-1">
            <?php // --- Super Admin Only Links ---
            if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
                <li><a href="superadmin.php" class="sidebar-link flex items-center py-3 px-4 text-gray-700 rounded-lg <?= ($active_page === 'superadmin') ? 'active-tab' : '' ?>"><i class="fas fa-user-shield text-lg w-8"></i><span class="font-medium ml-2">Superadmin</span></a></li>
                <li><a href="system_management.php" class="sidebar-link flex items-center py-3 px-4 text-gray-700 rounded-lg <?= ($active_page === 'system_management') ? 'active-tab' : '' ?>"><i class="fas fa-cogs text-lg w-8"></i><span class="font-medium ml-2">System Mgt.</span></a></li>
                <hr class="my-3">
            <?php endif; ?>

            <li><a href="dashboard.php" class="sidebar-link flex items-center py-3 px-4 text-gray-700 rounded-lg <?= ($active_page === 'dashboard') ? 'active-tab' : '' ?>"><i class="fas fa-tachometer-alt text-lg w-8"></i><span class="font-medium ml-2">Dashboard</span></a></li>
            <li><a href="student_dashboard.php" class="sidebar-link flex items-center py-3 px-4 text-gray-700 rounded-lg <?= ($active_page === 'students') ? 'active-tab' : '' ?>"><i class="fas fa-users text-lg w-8"></i><span class="font-medium ml-2">Students</span></a></li>
            <li><a href="fees.php" class="sidebar-link flex items-center py-3 px-4 text-gray-700 rounded-lg <?= ($active_page === 'fees') ? 'active-tab' : '' ?>"><i class="fas fa-money-bill-wave text-lg w-8"></i><span class="font-medium ml-2">Fees</span></a></li>
            <li><a href="invoicing.php" class="sidebar-link flex items-center py-3 px-4 text-gray-700 rounded-lg <?= ($active_page === 'invoicing') ? 'active-tab' : '' ?>"><i class="fas fa-file-invoice text-lg w-8"></i><span class="font-medium ml-2">Invoicing</span></a></li>
            <li><a href="exam_scheduling.php" class="sidebar-link flex items-center py-3 px-4 text-gray-700 rounded-lg <?= ($active_page === 'exams') ? 'active-tab' : '' ?>"><i class="fas fa-calendar-alt text-lg w-8"></i><span class="font-medium ml-2">Exam Scheduling</span></a></li>
            <li><a href="learning_hub.php" class="sidebar-link flex items-center py-3 px-4 text-gray-700 rounded-lg <?= ($active_page === 'learning_hub') ? 'active-tab' : '' ?>"><i class="fas fa-lightbulb text-lg w-8"></i><span class="font-medium ml-2">Learning Hub</span></a></li>
        </ul>
    </div>
</nav>

<div class="fixed bottom-0 left-0 right-0 bg-white border-t shadow-lg z-50 md:hidden">
    <div class="flex justify-around py-3">
        <a href="dashboard.php" class="flex flex-col items-center <?= ($active_page === 'dashboard') ? 'text-primary' : 'text-gray-500' ?>"><i class="fas fa-tachometer-alt text-lg"></i><span class="text-xs">Home</span></a>
        <a href="student_dashboard.php" class="flex flex-col items-center <?= ($active_page === 'students') ? 'text-primary' : 'text-gray-500' ?>"><i class="fas fa-users text-lg"></i><span class="text-xs">Students</span></a>
        <a href="fees.php" class="flex flex-col items-center <?= ($active_page === 'fees') ? 'text-primary' : 'text-gray-500' ?>"><i class="fas fa-money-bill-wave text-lg"></i><span class="text-xs">Fees</span></a>
        <a href="#" class="flex flex-col items-center text-gray-500" id="mobile-more-btn"><i class="fas fa-bars text-lg"></i><span class="text-xs">More</span></a>
    </div>
    <div id="mobile-more-menu" class="hidden absolute bottom-full left-0 right-0 bg-white border-t shadow-lg py-2">
        <ul class="space-y-1 px-4">
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
                <li><a href="superadmin.php" class="block py-2 <?= ($active_page === 'superadmin') ? 'text-primary bg-gray-100' : 'text-gray-700' ?> hover:bg-gray-100 rounded-md">Superadmin</a></li>
                <li><a href="system_management.php" class="block py-2 <?= ($active_page === 'system_management') ? 'text-primary bg-gray-100' : 'text-gray-700' ?> hover:bg-gray-100 rounded-md">System Mgt.</a></li>
            <?php endif; ?>
            <li><a href="invoicing.php" class="block py-2 <?= ($active_page === 'invoicing') ? 'text-primary bg-gray-100' : 'text-gray-700' ?> hover:bg-gray-100 rounded-md">Invoicing</a></li>
            <li><a href="exam_scheduling.php" class="block py-2 <?= ($active_page === 'exams') ? 'text-primary bg-gray-100' : 'text-gray-700' ?> hover:bg-gray-100 rounded-md">Exam Scheduling</a></li>
            <li><a href="learning_hub.php" class="block py-2 <?= ($active_page === 'learning_hub') ? 'text-primary bg-gray-100' : 'text-gray-700' ?> hover:bg-gray-100 rounded-md">Learning Hub</a></li>
            <li><a href="../logout.php" class="block py-2 text-gray-700 hover:bg-gray-100 rounded-md">Logout</a></li>
        </ul>
    </div>
</div>