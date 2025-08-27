<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    die("Database connection failed.");
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

// --- Define active page for sidebar ---
$active_page = 'exams';

$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($exam_id === 0) {
    header('Location: exam_scheduling.php?message_type=error&message=No exam ID provided.');
    exit;
}

$error_message = '';
$exam_record = null;
$acca_subjects = [
    'Foundation' => ['FA1', 'MA1', 'FA2', 'MA2'], // Added for consistency as per add_exam.php
    'Applied Knowledge' => ['BT', 'MA', 'FA'], // Added for consistency
    'Applied Skills' => ['LW', 'PM', 'TX', 'FR', 'AA', 'FM'],
    'Strategic Professional' => ['SBL', 'SBR', 'AFM', 'APM', 'ATX', 'AAA'],
];

// --- Fetch the existing exam record ---
$stmt_fetch = $conn->prepare("SELECT e.id, e.student_id, u.username AS student_username, e.title, e.exam_date, e.time_slot, e.status FROM exams e JOIN users u ON e.student_id = u.id WHERE e.id = ?");
$stmt_fetch->bind_param("i", $exam_id);
$stmt_fetch->execute();
$result = $stmt_fetch->get_result();
if ($result->num_rows === 1) {
    $exam_record = $result->fetch_assoc();
} else {
    header('Location: exam_scheduling.php?message_type=error&message=Exam record not found.');
    exit;
}
$stmt_fetch->close();


// --- Handle Form Submission for Update ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $updated_title = trim($_POST['title']);
    $updated_exam_date = trim($_POST['exam_date']);
    $updated_time_slot = trim($_POST['time_slot']);
    $updated_status = trim($_POST['status']);

    $allowed_slots = ['11:00-13:00', '14:00-16:00', '16:00-18:00'];

    // --- Validation ---
    $day_of_week = date('N', strtotime($updated_exam_date));
    if ($day_of_week != 1 && $day_of_week != 3) {
        $error_message = "Exams can only be scheduled on Mondays or Wednesdays.";
    }

    if (empty($error_message) && !in_array($updated_time_slot, $allowed_slots)) {
        $error_message = "Invalid time slot selected.";
    }

    if (empty($error_message)) {
        $sql_check = "SELECT COUNT(id) as count FROM exams WHERE exam_date = ? AND time_slot = ? AND id != ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ssi", $updated_exam_date, $updated_time_slot, $exam_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();

        if ($result_check['count'] >= 2) {
            $error_message = "This time slot is fully booked for the selected date.";
        }
    }
    
    if (empty($error_message)) {
        $stmt_update = $conn->prepare("UPDATE exams SET title = ?, exam_date = ?, time_slot = ?, status = ? WHERE id = ?");
        $stmt_update->bind_param("ssssi", $updated_title, $updated_exam_date, $updated_time_slot, $updated_status, $exam_id);

        if ($stmt_update->execute()) {
            header('Location: exam_scheduling.php?message_type=success&message=Exam schedule updated successfully!');
            exit;
        } else {
            $error_message = "Error updating exam: " . $stmt_update->error;
        }
        $stmt_update->close();
    }
    
    if (!empty($error_message)) {
        // Re-populate the record with the submitted (but failed) data to avoid data loss on the form
        $exam_record['title'] = $updated_title;
        $exam_record['exam_date'] = $updated_exam_date;
        $exam_record['time_slot'] = $updated_time_slot;
        $exam_record['status'] = $updated_status;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Exam Schedule - PSB Admin</title>
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
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #c5c5c5; border-radius: 4px; }
        .active-tab {
            background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%);
            border-left: 4px solid #4F46E5;
            color: #4F46E5;
        }
        .shadow-custom { box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .sidebar-link:hover { background: linear-gradient(90deg, rgba(79,70,229,0.1) 0%, rgba(14,165,233,0.05) 100%); }
        .slot-full { color: #ef4444; text-decoration: line-through; opacity: 0.6; }
        .date-error { border-color: #ef4444; }

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
            /* Adjust grid layouts to stack on small screens */
            .grid.grid-cols-1.md\:grid-cols-2.gap-6 {
                grid-template-columns: 1fr;
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
            <div class="max-w-3xl mx-auto">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Editing Exam #<?php echo $exam_record['id']; ?></h2>
                        <p class="text-gray-500 mt-1">Update the details for this scheduled exam.</p>
                    </div>
                    <a href="exam_scheduling.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Exam List
                    </a>
                </div>

                <div class="bg-white p-6 sm:p-8 rounded-xl shadow-custom">
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert">
                            <p class="font-bold">Could not update exam:</p>
                            <p><?php echo $error_message; ?></p>
                        </div>
                    <?php endif; ?>

                    <form action="edit_exam.php?id=<?php echo $exam_id; ?>" method="POST" class="space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Student</label>
                            <input type="text" disabled value="<?php echo htmlspecialchars($exam_record['student_username']); ?>" class="mt-1 block w-full p-2 bg-gray-100 border border-gray-300 rounded-md shadow-sm cursor-not-allowed">
                        </div>

                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">Subject</label>
                            <select id="title" name="title" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <?php foreach ($acca_subjects as $category => $subjects): ?>
                                    <optgroup label="<?php echo $category; ?>">
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?php echo $subject; ?>" <?php echo ($exam_record['title'] == $subject) ? 'selected' : ''; ?>><?php echo $subject; ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="exam_date" class="block text-sm font-medium text-gray-700">Exam Date</label>
                                <input type="date" id="exam_date" name="exam_date" required value="<?php echo htmlspecialchars($exam_record['exam_date']); ?>" min="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <p id="date_error_msg" class="text-red-500 text-xs mt-1 hidden">Exams are only on Mondays and Wednesdays.</p>
                            </div>
                            <div>
                                <label for="time_slot" class="block text-sm font-medium text-gray-700">Time Slot</label>
                                <select id="time_slot" name="time_slot" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                    <option value="11:00-13:00" <?php echo ($exam_record['time_slot'] == '11:00-13:00') ? 'selected' : ''; ?>>11:00 AM - 1:00 PM</option>
                                    <option value="14:00-16:00" <?php echo ($exam_record['time_slot'] == '14:00-16:00') ? 'selected' : ''; ?>>2:00 PM - 4:00 PM</option>
                                    <option value="16:00-18:00" <?php echo ($exam_record['time_slot'] == '16:00-18:00') ? 'selected' : ''; ?>>4:00 PM - 6:00 PM</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                            <select id="status" name="status" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <option value="Scheduled" <?php echo ($exam_record['status'] == 'Scheduled') ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="Completed" <?php echo ($exam_record['status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?php echo ($exam_record['status'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                            
                        <button type="submit" id="submit_button" class="w-full bg-primary hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-md flex items-center justify-center focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            <i class="fas fa-save mr-2"></i>Update Exam Schedule
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- User profile dropdown toggle ---
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenu = document.getElementById('user-menu');
        if (userMenuButton) {
            userMenuButton.addEventListener('click', (e) => { e.stopPropagation(); userMenu.classList.toggle('hidden'); });
        }
        document.addEventListener('click', (e) => {
            if (userMenu && !userMenu.classList.contains('hidden') && !userMenuButton.contains(e.target)) {
                userMenu.classList.add('hidden');
            }
        });

        // --- Mobile menu toggle (Crucial for sidebar visibility on mobile) ---
        const mobileMoreBtn = document.getElementById('mobile-more-btn');
        const mobileMoreMenu = document.getElementById('mobile-more-menu');
        if (mobileMoreBtn) {
            mobileMoreBtn.addEventListener('click', (e) => { e.preventDefault(); mobileMoreMenu.classList.toggle('hidden'); });
        }
        document.addEventListener('click', (e) => {
            if (mobileMoreMenu && !mobileMoreMenu.classList.contains('hidden') && !mobileMoreBtn.contains(e.target) && !mobileMoreMenu.contains(e.target)) {
                mobileMoreMenu.classList.add('hidden');
            }
        });

        // --- Page-Specific Script for Editing Exam ---
        const dateInput = document.getElementById('exam_date');
        const timeSlotSelect = document.getElementById('time_slot');
        const dateErrorMsg = document.getElementById('date_error_msg');
        const submitButton = document.getElementById('submit_button');
        const currentExamId = <?php echo $exam_id; ?>;

        async function updateSlots(date) {
            timeSlotSelect.disabled = true;

            try {
                // Pass the current exam ID to exclude it from the count
                const response = await fetch(`check_exam_slots.php?date=${date}&exclude_id=${currentExamId}`);
                if (!response.ok) throw new Error('Network response was not ok');
                
                const availableSlots = await response.json();

                timeSlotSelect.disabled = false;
                
                for (const option of timeSlotSelect.options) {
                    if (!option.value) continue;
                    const count = availableSlots[option.value] || 0;
                    const isFull = count >= 2;
                    const originalText = option.text.split(' (')[0];

                    option.disabled = isFull;
                    option.classList.toggle('slot-full', isFull);
                    // Ensure the text update is robust and handles original value correctly
                    if (option.value === "<?php echo htmlspecialchars($exam_record['time_slot']); ?>" && availableSlots[option.value] < 2) {
                         option.textContent = originalText + " (Selected)"; // Keep selected option even if it was full for other exams
                    } else if (isFull) {
                         option.textContent = `${originalText} (Fully Booked)`;
                    } else {
                         option.textContent = `${originalText} (${2 - count} slots left)`;
                    }
                }
            } catch (error) {
                console.error('Failed to fetch slots:', error);
                timeSlotSelect.disabled = false;
            }
        }
        
        function validateDate() {
            if(!dateInput.value) return;
            const selectedDate = new Date(dateInput.value);
            const day = selectedDate.getUTCDay(); // Sunday = 0, Monday = 1...

            const isInvalid = day !== 1 && day !== 3; // Not Monday or Wednesday
            dateErrorMsg.classList.toggle('hidden', !isInvalid);
            dateInput.classList.toggle('date-error', isInvalid);
            submitButton.disabled = isInvalid;
            timeSlotSelect.disabled = isInvalid;
            
            if (!isInvalid) {
                // Note: The JS slot checker is a UI guide. The final validation is on the backend.
                // You might enhance check_exam_slots.php to accept an ID to exclude for more accurate client-side feedback.
                updateSlots(dateInput.value); // Call updateSlots only if date is valid
            }
        }

        dateInput.addEventListener('change', validateDate);
        validateDate(); // Initial validation on page load
    });
    </script>
</body>
</html>