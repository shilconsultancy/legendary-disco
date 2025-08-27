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
$active_page = 'students';

$registration_success = false;
$error_message = '';
$form_data = []; // To hold form data on error

// --- Form Submission Logic ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $form_data = $_POST; // Preserve submitted data
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $new_password = $_POST['password'];
    $new_role = $_POST['role'];
    $new_acca_id = ($new_role === 'student' && isset($_POST['acca_id'])) ? trim($_POST['acca_id']) : null;
    $allowed_roles = ['student', 'teacher', 'admin'];

    // --- Image Upload Logic ---
    $profile_image_path = 'admin/assets/images/default_avatar.png'; 
    
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'assets/images/'; 
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5 MB

        if (!in_array($_FILES['profile_image']['type'], $allowed_types)) {
            $error_message = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
        } elseif ($_FILES['profile_image']['size'] > $max_size) {
            $error_message = "File is too large. Maximum size is 5 MB.";
        } else {
            $file_ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $new_filename = 'user_' . uniqid() . '.' . $file_ext;
            $destination = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                $profile_image_path = 'admin/assets/images/' . $new_filename; 
            } else {
                $error_message = "Failed to move uploaded file.";
            }
        }
    }

    // --- Validation (Continue only if no image error) ---
    if (empty($error_message)) {
        if (empty($new_username) || empty($new_email) || empty($new_password) || empty($new_role)) {
            $error_message = "All fields are required.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Invalid email format.";
        } else {
            // --- User Insertion ---
            $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, acca_id, profile_image_path) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $new_username, $new_email, $password_hash, $new_role, $new_acca_id, $profile_image_path);

            if ($stmt->execute()) {
                $registration_success = true;
                $form_data = []; // Clear form data on success
            } else {
                $error_message = "Error registering user: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New User - PSB Admin</title>
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
        .fade-in { animation: fadeIn 0.3s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

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
            .flex.justify-between.items-center.mb-6 {
                flex-direction: column; /* Stack elements in top row */
                align-items: stretch; /* Stretch items to full width */
            }
            .flex.justify-between.items-center.mb-6 > div {
                margin-bottom: 1rem; /* Space out stacked elements */
            }
            .flex.justify-between.items-center.mb-6 > a {
                width: 100%; /* Make button full width */
            }
            /* Adjust spacing for profile picture elements */
            .mt-2.flex.items-center.space-x-4 {
                flex-direction: column; /* Stack image and file input vertically */
                align-items: flex-start; /* Align text to left */
                space-x: 0;
                space-y: 1rem; /* Add vertical space */
            }
            /* Ensure the file input looks good when stacked */
            input[type="file"].block.w-full {
                width: 100%;
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
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Register New User</h2>
                    <p class="text-gray-500 mt-1">Create a new account for a student, teacher, or admin.</p>
                </div>
                 <a href="student_dashboard.php" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back
                </a>
            </div>

            <div class="bg-white rounded-xl shadow-custom p-6 sm:p-8 max-w-2xl mx-auto fade-in">
                 <?php if ($registration_success): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert"><p><strong>Success!</strong> User registered successfully.</p></div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert"><p><strong>Error!</strong> <?= htmlspecialchars($error_message); ?></p></div>
                <?php endif; ?>

                <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" class="space-y-6">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Profile Picture (Optional)</label>
                        <div class="mt-2 flex items-center space-x-4">
                            <img id="imagePreview" src="assets/images/default_avatar.png" alt="Image Preview" class="w-20 h-20 rounded-full object-cover bg-gray-200">
                            <input type="file" id="profile_image" name="profile_image" accept="image/png, image/jpeg, image/gif"
                                   class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-primary hover:file:bg-indigo-100">
                        </div>
                    </div>

                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                        <input type="text" id="username" name="username" required value="<?= htmlspecialchars($form_data['username'] ?? ''); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" id="email" name="email" required value="<?= htmlspecialchars($form_data['email'] ?? ''); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input type="password" id="password" name="password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                    </div>
                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700">User Role</label>
                        <select id="role" name="role" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                            <option value="student" selected>Student</option>
                            <option value="teacher">Teacher</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div id="accaIdField">
                        <label for="acca_id" class="block text-sm font-medium text-gray-700">ACCA ID</label>
                        <input type="text" id="acca_id" name="acca_id" required value="<?= htmlspecialchars($form_data['acca_id'] ?? ''); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary">
                    </div>
                    <button type="submit" class="w-full flex justify-center items-center bg-primary hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-md"><i class="fas fa-user-plus mr-2"></i>Register User</button>
                </form>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Logic for this page's form ---
            const roleSelect = document.getElementById('role');
            const accaIdField = document.getElementById('accaIdField');
            const accaIdInput = document.getElementById('acca_id');

            function toggleAccaIdField() {
                if (roleSelect.value === 'student') {
                    accaIdField.classList.remove('hidden');
                    accaIdInput.setAttribute('required', 'required');
                } else {
                    accaIdField.classList.add('hidden');
                    accaIdInput.removeAttribute('required');
                }
            }
            toggleAccaIdField();
            roleSelect.addEventListener('change', toggleAccaIdField);

            const imageInput = document.getElementById('profile_image');
            const imagePreview = document.getElementById('imagePreview');
            
            imageInput.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        imagePreview.src = e.target.result;
                    }
                    reader.readAsDataURL(file);
                }
            });

            // --- FIXED: Standard Menu Scripts (Header Dropdown) ---
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');
            if(userMenuButton){
                userMenuButton.addEventListener('click', (e) => { 
                    e.stopPropagation(); 
                    userMenu.classList.toggle('hidden'); 
                });
            }
            
            // --- Mobile Menu Toggle (Crucial for sidebar visibility on mobile) ---
            const mobileMoreBtn = document.getElementById('mobile-more-btn');
            const mobileMoreMenu = document.getElementById('mobile-more-menu');
            if(mobileMoreBtn){
                mobileMoreBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    mobileMoreMenu.classList.toggle('hidden');
                });
            }
            
            // This handles closing the menu if you click outside of it
            document.addEventListener('click', (e) => { 
                if (userMenu && !userMenu.classList.contains('hidden') && !userMenuButton.contains(e.target)) {
                     userMenu.classList.add('hidden');
                }
                if (mobileMoreMenu && !mobileMoreMenu.classList.contains('hidden') && !mobileMoreBtn.contains(e.target) && !mobileMoreMenu.contains(e.target)) {
                     mobileMoreMenu.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>