<?php
session_start();
require_once 'db_config.php';

// --- Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

// --- Define active page for sidebar ---
// Set to a non-existent value so no menu item is highlighted
$active_page = 'add_admin';

// Initialize variables for form feedback
$username = "";
$email = "";
$error_message = "";
$success_message = "";

// --- Form Processing Logic ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and retrieve form data
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $role = 'admin'; // Set the role for the new user

    // --- Validation ---
    if (empty($username) || empty($email) || empty($password)) {
        $error_message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } else {
        // Check if username or email already exists
        $sql_check = "SELECT id FROM users WHERE username = ? OR email = ?";
        if($stmt_check = $conn->prepare($sql_check)) {
            $stmt_check->bind_param("ss", $username, $email);
            $stmt_check->execute();
            $stmt_check->store_result();

            if ($stmt_check->num_rows > 0) {
                $error_message = "An account with that username or email already exists.";
            } else {
                // Hash the password for security
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // --- Insert the new admin into the database ---
                $sql_insert = "INSERT INTO users (username, email, password_hash, role, created_at, acca_id) VALUES (?, ?, ?, ?, NOW(), NULL)";
                if($stmt_insert = $conn->prepare($sql_insert)) {
                    $stmt_insert->bind_param("ssss", $username, $email, $hashed_password, $role);

                    if ($stmt_insert->execute()) {
                        $success_message = "New administrator '<strong>" . htmlspecialchars($username) . "</strong>' has been added successfully!";
                        // Clear form fields on success
                        $username = "";
                        $email = "";
                    } else {
                        $error_message = "Error: Could not create the account. Please check your database schema.";
                    }
                    $stmt_insert->close();
                }
            }
            $stmt_check->close();
        } else {
            $error_message = "Database error: Could not prepare statement.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Admin - PSB Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#4F46E5', // Indigo-600 equivalent
                        secondary: '#0EA5E9', // Sky-500 equivalent
                        dark: '#1E293B',    // Slate-800 equivalent
                        light: '#F8FAFC'    // Slate-50 equivalent
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
        /* Custom scrollbar for better aesthetics */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #c5c5c5; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }

        /* Keyframe animation for fade-in effect on page load */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in { animation: fadeIn 0.5s ease-out forwards; }

        /* Custom styles for sidebar links and cards */
        .active-tab {
            background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%);
            border-left: 4px solid #4F46E5;
            color: #4F46E5;
        }
        
        .shadow-custom { box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .sidebar-link:hover { background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%); }
        .sidebar-link:active { background: linear-gradient(90deg, rgba(79,70,229,0.15) 0%, rgba(14,165,233,0.1) 100%); }

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
                 <h2 class="text-2xl font-bold text-gray-800">Add New Administrator</h2>
                 <a href="dashboard.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
                </a>
            </div>

            <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 max-w-2xl mx-auto fade-in">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">

                    <?php if (!empty($success_message)): ?>
                        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-md" role="alert">
                            <p><?php echo $success_message; ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md" role="alert">
                            <p><?php echo $error_message; ?></p>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                        <div class="mt-1">
                            <input type="text" name="username" id="username" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary focus:border-primary" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                        <div class="mt-1">
                            <input type="email" name="email" id="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary focus:border-primary" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <div class="mt-1">
                            <input type="password" name="password" id="password" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary focus:border-primary" required>
                        </div>
                         <p class="mt-2 text-xs text-gray-500">Password must be at least 8 characters long.</p>
                    </div>

                    <div>
                        <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            <i class="fas fa-plus-circle mr-2"></i> Create Admin Account
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- User profile dropdown toggle ---
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');

            if(userMenuButton && userMenu) {
                userMenuButton.addEventListener('click', function(event) {
                    event.stopPropagation();
                    userMenu.classList.toggle('hidden');
                });

                document.addEventListener('click', function(event) {
                    if (!userMenu.classList.contains('hidden') && !userMenuButton.contains(event.target)) {
                        userMenu.classList.add('hidden');
                    }
                });
            }
            
            // --- Mobile menu toggle (Crucial for sidebar visibility on mobile) ---
            const mobileMoreBtn = document.getElementById('mobile-more-btn');
            const mobileMoreMenu = document.getElementById('mobile-more-menu');

            if (mobileMoreBtn && mobileMoreMenu) {
                mobileMoreBtn.addEventListener('click', function(event) {
                    event.preventDefault(); // Prevent default link behavior
                    mobileMoreMenu.classList.toggle('hidden');
                });

                document.addEventListener('click', function(event) {
                    if (!mobileMoreMenu.classList.contains('hidden') && !mobileMoreBtn.contains(event.target) && !mobileMoreMenu.contains(event.target)) {
                        mobileMoreMenu.classList.add('hidden');
                    }
                });
            }
        });
    </script>
</body>
</html>