<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';
// ... (All PHP logic remains the same)
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}
if (!isset($_SESSION['user_id'])) {
    session_destroy();
    header('Location: ../index.php?error=invalid_session');
    exit;
}
$logged_in_user_id = $_SESSION['user_id'];
$logged_in_user_role = $_SESSION['role'];
$profile_user_id = $logged_in_user_id;
$is_superadmin_editing_other = false;
if (isset($_GET['id']) && is_numeric($_GET['id']) && $logged_in_user_role === 'super_admin') {
    $profile_user_id = (int)$_GET['id'];
    if ($profile_user_id !== $logged_in_user_id) {
        $is_superadmin_editing_other = true;
    }
}
$stmt = $conn->prepare("SELECT username, email, acca_id, role, profile_image_path, password_hash FROM users WHERE id = ?");
$stmt->bind_param("i", $profile_user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();
if (!$user_data) {
    die("Error: The requested user profile was not found.");
}
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $updated_username = trim($_POST['username']);
    $updated_email = trim($_POST['email']);
    $new_password = $_POST['new_password'];
    $confirm_new_password = $_POST['confirm_new_password'];
    $current_password = $_POST['current_password'] ?? '';
    $profile_image_path = $user_data['profile_image_path'];
    $error_message = '';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/profile_pictures/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($_FILES['profile_image']['type'], $allowed_types)) {
            $error_message = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
        } else {
            $file_ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $new_filename = 'user_' . $profile_user_id . '_' . uniqid() . '.' . $file_ext;
            $destination = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                if (!empty($profile_image_path) && file_exists('../' . $profile_image_path)) {
                    unlink('../' . $profile_image_path);
                }
                $profile_image_path = 'uploads/profile_pictures/' . $new_filename;
            } else {
                $error_message = "Failed to move uploaded file.";
            }
        }
    }
    if (empty($error_message)) {
        if (empty($updated_username) || empty($updated_email)) {
            $error_message = "Username and Email cannot be empty.";
        } elseif (!filter_var($updated_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Invalid email format.";
        }
    }
    $update_password = false;
    if (!empty($new_password)) {
        if ($new_password !== $confirm_new_password) {
            $error_message = "New passwords do not match.";
        } else {
            if ($is_superadmin_editing_other) {
                $update_password = true;
            } else {
                if (empty($current_password) || !password_verify($current_password, $user_data['password_hash'])) {
                    $error_message = "Incorrect current password.";
                } else {
                    $update_password = true;
                }
            }
        }
    }
    if (empty($error_message)) {
        $params = [];
        $types = "";
        if ($update_password) {
            $sql_update = "UPDATE users SET username = ?, email = ?, password_hash = ?, profile_image_path = ? WHERE id = ?";
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $params = [$updated_username, $updated_email, $password_hash, $profile_image_path, $profile_user_id];
            $types = "ssssi";
        } else {
            $sql_update = "UPDATE users SET username = ?, email = ?, profile_image_path = ? WHERE id = ?";
            $params = [$updated_username, $updated_email, $profile_image_path, $profile_user_id];
            $types = "sssi";
        }
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param($types, ...$params);
        if ($stmt_update->execute()) {
            $_SESSION['message'] = "Profile updated successfully.";
            $_SESSION['message_type'] = 'success';
            if (!$is_superadmin_editing_other) {
                $_SESSION['username'] = $updated_username;
            }
        } else {
            $_SESSION['message'] = "Error updating profile: " . $stmt_update->error;
            $_SESSION['message_type'] = 'error';
        }
        $stmt_update->close();
        header("Location: " . $_SERVER['PHP_SELF'] . ($is_superadmin_editing_other ? '?id=' . $profile_user_id : ''));
        exit();
    }
}
$message_html = '';
if (isset($_SESSION['message']) || !empty($error_message)) {
    $message_content = !empty($error_message) ? $error_message : ($_SESSION['message'] ?? '');
    $message_type = !empty($error_message) ? 'error' : ($_SESSION['message_type'] ?? 'info');
    $message_class = match ($message_type) {
        'success' => 'bg-green-100 border-green-500 text-green-700',
        'error' => 'bg-red-100 border-red-500 text-red-700',
        default => 'bg-blue-100 border-blue-500 text-blue-700',
    };
    $message_html = "<div class='border-l-4 {$message_class} p-4 mb-6 rounded-md fade-in' role='alert'><span class='font-medium'>".htmlspecialchars($message_content)."</span></div>";
    unset($_SESSION['message'], $_SESSION['message_type']);
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Admin</title>
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
        .shadow-custom { box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .fade-in { animation: fadeIn 0.5s ease-out forwards; }
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
                padding-bottom: 120px; /* Increased padding-bottom significantly to ensure button clearance */
            }
            .grid.grid-cols-1.md\:grid-cols-2.gap-6 {
                grid-template-columns: 1fr; /* Stack columns */
            }
            /* Adjust spacing for profile picture elements */
            .mt-2.flex.items-center.space-x-4 {
                flex-direction: column; /* Stack image and file input vertically */
                align-items: flex-start; /* Align text to left */
                space-x: 0;
                space-y: 1rem; /* Add vertical space */
            }
            .flex.items-center > label { /* For "Choose File" label */
                margin-left: 0 !important; /* Override ml-3 on mobile if present */
            }
            .text-right {
                text-align: left; /* Align submit button to left on mobile */
            }
            button[type="submit"] {
                width: 100%; /* Make submit button full width */
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
        
        <main class="flex-1 p-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-6"><?= $is_superadmin_editing_other ? "Edit User Profile" : "My Profile" ?></h2>
            
            <?= $message_html ?>

            <div class="bg-white rounded-xl shadow-lg p-6 sm:p-8 max-w-4xl mx-auto fade-in">
                <form action="edit_profile.php<?= $is_superadmin_editing_other ? '?id=' . $profile_user_id : '' ?>" method="POST" enctype="multipart/form-data" class="space-y-8">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Profile Picture</label>
                        <div class="mt-2 flex items-center space-x-4">
                            <?php
                                $current_image = (!empty($user_data['profile_image_path']) && file_exists('../' . $user_data['profile_image_path'])) 
                                    ? '../' . $user_data['profile_image_path'] 
                                    : 'assets/images/default_avatar.png';
                            ?>
                            <img id="imagePreview" src="<?= htmlspecialchars($current_image) . '?t=' . time(); ?>" alt="Profile Picture" class="w-20 h-20 rounded-full object-cover bg-gray-200">
                            
                            <div class="flex items-center">
                                <label for="profile_image" class="cursor-pointer bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    <span>Choose File</span>
                                </label>
                                <input type="file" id="profile_image" name="profile_image" accept="image/png, image/jpeg, image/gif" class="sr-only">
                                <span id="file-chosen" class="ml-3 text-sm text-gray-500">No file chosen</span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                            <input type="text" name="username" id="username" value="<?= htmlspecialchars($user_data['username']) ?>" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                        </div>
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                            <input type="email" name="email" id="email" value="<?= htmlspecialchars($user_data['email']) ?>" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                        </div>
                    </div>

                    <div class="border-t pt-8">
                        <h3 class="text-lg font-semibold text-dark">Update Password</h3>
                        <p class="text-sm text-gray-500 mb-4">Leave fields blank to keep the current password.</p>
                        <?php if (!$is_superadmin_editing_other): ?>
                        <div>
                            <label for="current_password" class="block text-sm font-medium text-gray-700">Current Password</label>
                            <input type="password" name="current_password" id="current_password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                        </div>
                        <?php endif; ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                                <input type="password" name="new_password" id="new_password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                            </div>
                            <div>
                                <label for="confirm_new_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                <input type="password" name="confirm_new_password" id="confirm_new_password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                            </div>
                        </div>
                    </div>

                    <div class="text-right pt-4">
                        <button type="submit" class="inline-flex items-center justify-center bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-6 rounded-lg transition">
                            <i class="fas fa-save mr-2"></i>Update Profile
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Image Upload Preview Script ---
        const imageInput = document.getElementById('profile_image');
        const imagePreview = document.getElementById('imagePreview');
        const fileChosenSpan = document.getElementById('file-chosen');
        
        if (imageInput) {
            imageInput.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    fileChosenSpan.textContent = file.name;
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        imagePreview.src = e.target.result;
                    }
                    reader.readAsDataURL(file);
                } else {
                    fileChosenSpan.textContent = 'No file chosen';
                }
            });
        }

        // --- Dropdown Menu Script (for header.php) ---
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
        // --- Mobile Menu Toggle Script (Crucial for sidebar visibility on mobile) ---
        const mobileMoreBtn = document.getElementById('mobile-more-btn');
        const mobileMoreMenu = document.getElementById('mobile-more-menu');

        if (mobileMoreBtn && mobileMoreMenu) {
            mobileMoreBtn.addEventListener('click', (e) => {
                e.preventDefault(); // Prevent default link behavior
                mobileMoreMenu.classList.toggle('hidden');
            });

            // Close the mobile menu if clicked outside
            document.addEventListener('click', (e) => {
                // Check if the click was outside the button and outside the menu itself
                if (!mobileMoreBtn.contains(e.target) && !mobileMoreMenu.contains(e.target)) {
                    mobileMoreMenu.classList.add('hidden');
                }
            });
        }
    });
    </script>
</body>
</html>