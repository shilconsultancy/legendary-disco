<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';
// ... (rest of the PHP logic remains the same)
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    die("Error: Database connection could not be established.");
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}
$active_page = 'students';
$student_record = null;
$error_message = '';
$success_message = '';
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($student_id > 0) {
    $stmt = $conn->prepare("SELECT id, username, email, acca_id, profile_image_path FROM users WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $student_record = $result->fetch_assoc();
    } else {
        $error_message = "Student record not found.";
    }
    $stmt->close();
} else {
    header('Location: student_dashboard.php?error=' . urlencode('No Student ID provided.'));
    exit;
}
if ($_SERVER["REQUEST_METHOD"] == "POST" && $student_record) {
    $updated_username = trim($_POST['username']);
    $updated_email = trim($_POST['email']);
    $updated_acca_id = trim($_POST['acca_id']);
    $updated_password = $_POST['password'];
    $profile_image_path = $student_record['profile_image_path'];
    $upload_error = false;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'assets/images/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024;
        if (!in_array($_FILES['profile_image']['type'], $allowed_types)) {
            $error_message = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
            $upload_error = true;
        } elseif ($_FILES['profile_image']['size'] > $max_size) {
            $error_message = "File is too large. Maximum size is 5 MB.";
            $upload_error = true;
        } else {
            $file_ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $new_filename = 'user_' . $student_id . '_' . uniqid() . '.' . $file_ext;
            $destination = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                $default_avatar = 'admin/assets/images/default_avatar.png';
                if (!empty($profile_image_path) && $profile_image_path !== $default_avatar && file_exists('../' . $profile_image_path)) {
                    unlink($profile_image_path);
                }
                $profile_image_path = 'admin/' . $destination;
            } else {
                $error_message = "Failed to move uploaded file.";
                $upload_error = true;
            }
        }
    }
    if (!$upload_error) {
        if (empty($updated_username) || empty($updated_email) || empty($updated_acca_id)) {
            $error_message = "Username, Email, and ACCA ID are required.";
        } elseif (!filter_var($updated_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Invalid email format.";
        }
    }
    if (empty($error_message)) {
        $params = [];
        $types = "";
        if (!empty($updated_password)) {
            $sql_update = "UPDATE users SET username = ?, email = ?, acca_id = ?, password_hash = ?, profile_image_path = ? WHERE id = ?";
            $password_hash = password_hash($updated_password, PASSWORD_BCRYPT);
            $params = [$updated_username, $updated_email, $updated_acca_id, $password_hash, $profile_image_path, $student_id];
            $types = "sssssi";
        } else {
            $sql_update = "UPDATE users SET username = ?, email = ?, acca_id = ?, profile_image_path = ? WHERE id = ?";
            $params = [$updated_username, $updated_email, $updated_acca_id, $profile_image_path, $student_id];
            $types = "ssssi";
        }
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param($types, ...$params);
        if ($stmt_update->execute()) {
            header('Location: view_student.php?id=' . $student_id . '&success=' . urlencode('Student profile updated successfully!'));
            exit;
        } else {
            $error_message = "Error updating profile: " . $stmt_update->error;
        }
        $stmt_update->close();
    }
    if (!empty($error_message)) {
        $student_record['username'] = $updated_username;
        $student_record['email'] = $updated_email;
        $student_record['acca_id'] = $updated_acca_id;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student - PSB Admin</title>
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
            .flex.justify-between.items-center.mb-8 > * {
                margin-top: 1rem;
            }
            .flex.justify-between.items-center.mb-8 > *:first-child {
                margin-top: 0; /* No top margin for the first element */
            }
            /* Adjust grid layouts to stack on small screens */
            .grid.grid-cols-1.md\:grid-cols-2.gap-6 {
                grid-template-columns: 1fr;
            }
            /* Adjust spacing for profile picture elements */
            .mt-2.flex.items-center.space-x-4 {
                flex-direction: column; /* Stack image and file input vertically */
                align-items: flex-start; /* Align text to left */
                space-x: 0;
                space-y: 1rem; /* Add vertical space */
            }
            .flex.items-center > label[for="profile_image"] { /* For "Choose File" label */
                margin-left: 0 !important; /* Override ml-3 on mobile if present */
            }
            .sr-only + span { /* For the file-chosen span */
                margin-left: 0 !important; /* Adjust if it has conflicting margin */
                display: block; /* Ensure it takes its own line */
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
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Edit Student Profile</h2>
                    <p class="text-gray-500 mt-1">Update details for Student ID: #<?= htmlspecialchars($student_id); ?></p>
                </div>
                <a href="view_student.php?id=<?= htmlspecialchars($student_id); ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Profile
                </a>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-6 sm:p-8 max-w-3xl mx-auto fade-in">
                <?php if ($student_record): ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-md relative mb-6" role="alert">
                            <strong class="font-bold">Error!</strong>
                            <span class="block sm:inline"><?= htmlspecialchars($error_message); ?></span>
                        </div>
                    <?php endif; ?>
                    <form action="edit_student.php?id=<?= htmlspecialchars($student_id); ?>" method="POST" enctype="multipart/form-data" class="space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Profile Picture</label>
                            <div class="mt-2 flex items-center space-x-4">
                                <?php
                                    $default_image = '../admin/assets/images/default_avatar.png';
                                    $current_image = (!empty($student_record['profile_image_path']) && file_exists('../' . $student_record['profile_image_path'])) 
                                        ? '../' . $student_record['profile_image_path'] 
                                        : $default_image;
                                ?>
                                <img id="imagePreview" src="<?= htmlspecialchars($current_image); ?>" alt="Current Profile Picture" class="w-20 h-20 rounded-full object-cover bg-gray-200">
                                
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
                                <input type="text" id="username" name="username" required value="<?= htmlspecialchars($student_record['username']); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($student_record['email']); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                            </div>
                        </div>
                        <div>
                            <label for="acca_id" class="block text-sm font-medium text-gray-700">ACCA ID</label>
                            <input type="text" id="acca_id" name="acca_id" required value="<?= htmlspecialchars($student_record['acca_id']); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                        </div>
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">New Password</label>
                            <input type="password" id="password" name="password" placeholder="Leave blank to keep current password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                        </div>
                        <button type="submit" class="w-full flex justify-center items-center bg-primary hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-md">
                            <i class="fas fa-save mr-2"></i>Update Profile
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