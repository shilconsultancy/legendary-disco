<?php
// This file assumes session_start() has been called on the parent page.
?>
<header class="bg-white shadow-custom py-4 px-6 flex justify-between items-center sticky top-0 z-50">
    <div class="flex items-center space-x-4">
        <img src="PSB_LOGO.png" alt="PSB Logo" class="w-10 h-10">

        <h1 class="text-2xl font-bold bg-gradient-to-r from-primary to-secondary bg-clip-text text-transparent">
            PSB Admin
        </h1>
    </div>
    <div class="flex items-center space-x-6">
        <div class="relative">
            <button id="user-menu-button" class="flex items-center space-x-3 focus:outline-none">
                <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold">
                    <?= substr(htmlspecialchars($_SESSION['username']), 0, 1); ?>
                </div>
                <div class="hidden md:block text-left">
                    <p class="font-medium text-gray-900">Welcome, <?= htmlspecialchars($_SESSION['username']); ?>!</p>
                    <p class="text-xs text-gray-500 capitalize"><?= htmlspecialchars(str_replace('_', ' ', $_SESSION['role'])); ?> <i class="fas fa-chevron-down ml-1"></i></p>
                </div>
            </button>
            <div id="user-menu" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-md shadow-lg py-1 z-50">
                <a href="edit_profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user-circle w-5 mr-2"></i>My Profile</a>
                
                <?php // --- Super Admin Only Link ---
                if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
                    <a href="add_admin.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user-plus w-5 mr-2"></i>Add New Admin</a>
                <?php endif; ?>

                <a href="../student/dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-user-graduate w-5 mr-2"></i>Login as Student</a>
                <div class="border-t border-gray-100"></div>
                <a href="../logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><i class="fas fa-sign-out-alt w-5 mr-2"></i>Logout</a>
            </div>
        </div>
    </div>
</header>