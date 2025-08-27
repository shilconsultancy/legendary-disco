<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db_config.php';

// --- Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../index.php');
    exit;
}

// --- Define active page for sidebar ---
$active_page = 'exams';

$error_message = '';
$students_list = [];
$acca_subjects = [
    'Foundation' => ['FA1', 'MA1', 'FA2', 'MA2'],
    'Applied Knowledge' => ['BT', 'MA', 'FA'],
    'Applied Skills' => ['LW', 'PM', 'TX', 'FR', 'AA', 'FM'],
    'Strategic Professional' => ['SBL', 'SBR', 'AFM', 'APM', 'ATX', 'AAA'],
];

// Fetch all students for the dropdown
$sql_students = "SELECT id, username, acca_id FROM users WHERE role = 'student' ORDER BY username ASC";
$result_students = $conn->query($sql_students);
if ($result_students) {
    while ($row = $result_students->fetch_assoc()) {
        $students_list[] = $row;
    }
} else {
    // Handle error if student list cannot be fetched
    $error_message = "Error fetching student list: " . $conn->error;
}


// --- Handle Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $student_id = (int) $_POST['student_id'];
    $title = trim($_POST['title']);
    $exam_date = trim($_POST['exam_date']);
    $time_slot = trim($_POST['time_slot']);
    $status = $_POST['status'];

    $allowed_slots = ['11:00-13:00', '14:00-16:00', '16:00-18:00'];
    $allowed_statuses = ['Scheduled', 'Completed', 'Cancelled'];

    // --- Validation ---
    if (empty($student_id) || empty($title) || empty($exam_date) || empty($time_slot) || empty($status)) {
        $error_message = "All fields are required.";
    } elseif (!in_array($status, $allowed_statuses)) {
        $error_message = "Invalid status selected.";
    }

    if (empty($error_message)) {
        // Validate date format and content (YYYY-MM-DD)
        $date_parts = explode('-', $exam_date);
        if (count($date_parts) !== 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
            $error_message = 'Invalid exam date format or date.';
        }
    }

    if (empty($error_message)) {
        $day_of_week = date('N', strtotime($exam_date));
        if ($day_of_week != 1 && $day_of_week != 3) {
            $error_message = "Exams can only be scheduled on Mondays or Wednesdays.";
        }
    }

    if (empty($error_message) && !in_array($time_slot, $allowed_slots)) {
        $error_message = "Invalid time slot selected.";
    }

    // Check if the selected subject is valid
    $valid_subject = false;
    foreach ($acca_subjects as $category => $subjects) {
        if (in_array($title, $subjects)) {
            $valid_subject = true;
            break;
        }
    }
    if (empty($error_message) && !$valid_subject) {
        $error_message = "Invalid subject selected.";
    }


    if (empty($error_message)) {
        $sql_check = "SELECT COUNT(id) as count FROM exams WHERE exam_date = ? AND time_slot = ?";
        $stmt_check = $conn->prepare($sql_check);
        if ($stmt_check) {
            $stmt_check->bind_param("ss", $exam_date, $time_slot);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($result_check['count'] >= 2) {
                $error_message = "This time slot is fully booked for the selected date. Please choose another slot.";
            }
        } else {
            $error_message = "Database error: Could not prepare slot check statement.";
        }
    }
    
    if (empty($error_message)) {
        $stmt = $conn->prepare("INSERT INTO exams (student_id, title, exam_date, time_slot, status) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("issss", $student_id, $title, $exam_date, $time_slot, $status);

            if ($stmt->execute()) {
                header('Location: exam_scheduling.php?message_type=success&message=' . urlencode('Exam scheduled successfully!'));
                exit;
            } else {
                $error_message = "Error scheduling exam: " . $stmt->error;
            }
            $stmt->close();
        } else {
             $error_message = "Database error: Could not prepare insert statement.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule New Exam - PSB Admin</title>
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
                        <h2 class="text-2xl font-bold text-gray-800">Schedule a New Exam</h2>
                        <p class="text-gray-500 mt-1">Book a new exam session for a student.</p>
                    </div>
                    <a href="exam_scheduling.php" class="w-full sm:w-auto mt-4 sm:mt-0 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center justify-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Exam List
                    </a>
                </div>

                <div class="bg-white p-6 sm:p-8 rounded-xl shadow-custom">
                    <?php if (!empty($error_message)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert">
                            <p class="font-bold">Could not schedule exam:</p>
                            <p><?php echo $error_message; ?></p>
                        </div>
                    <?php endif; ?>

                    <form action="add_exam.php" method="POST" class="space-y-6">
                        <div>
                            <label for="student_id" class="block text-sm font-medium text-gray-700">Student</label>
                            <select id="student_id" name="student_id" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <option value="">-- Select a Student --</option>
                                <?php foreach ($students_list as $student): ?>
                                    <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['username']) . ' (' . htmlspecialchars($student['acca_id'] ?? 'N/A') . ')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">Subject</label>
                            <select id="title" name="title" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <option value="">-- Select Subject --</option>
                                <?php foreach ($acca_subjects as $category => $subjects): ?>
                                    <optgroup label="<?php echo $category; ?>">
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?php echo $subject; ?>"><?php echo $subject; ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="exam_date" class="block text-sm font-medium text-gray-700">Exam Date</label>
                                <input type="date" id="exam_date" name="exam_date" required value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <p id="date_error_msg" class="text-red-500 text-xs mt-1 hidden">Exams are only on Mondays and Wednesdays.</p>
                            </div>
                            <div>
                                <label for="time_slot" class="block text-sm font-medium text-gray-700">Time Slot</label>
                                <select id="time_slot" name="time_slot" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                    <option value="">-- Select a date first --</option>
                                    <option value="11:00-13:00">11:00 AM - 1:00 PM</option>
                                    <option value="14:00-16:00">2:00 PM - 4:00 PM</option>
                                    <option value="16:00-18:00">4:00 PM - 6:00 PM</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                            <select id="status" name="status" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary focus:border-primary">
                                <option value="Scheduled">Scheduled</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                            
                        <button type="submit" id="submit_button" class="w-full bg-primary hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                            <i class="fas fa-calendar-check mr-2"></i>Schedule Exam
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
        if (userMenuButton && userMenu) {
            userMenuButton.addEventListener('click', (e) => {
                e.stopPropagation();
                userMenu.classList.toggle('hidden');
            });
            document.addEventListener('click', (e) => {
                if (!userMenu.classList.contains('hidden') && !userMenuButton.contains(e.target)) {
                    userMenu.classList.add('hidden');
                }
            });
        }

        // --- Mobile menu toggle (Crucial for sidebar visibility on mobile) ---
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

        // --- Exam Scheduling Specific Script ---
        const dateInput = document.getElementById('exam_date');
        const timeSlotSelect = document.getElementById('time_slot');
        const dateErrorMsg = document.getElementById('date_error_msg');
        const submitButton = document.getElementById('submit_button');

        async function updateSlots(date) {
            timeSlotSelect.disabled = true;
            timeSlotSelect.querySelector('option[value=""]').textContent = 'Loading slots...';

            try {
                // Ensure check_exam_slots.php exists and returns correct JSON
                const response = await fetch(`check_exam_slots.php?date=${date}`);
                if (!response.ok) throw new Error('Network response error');
                
                const availableSlots = await response.json();

                timeSlotSelect.disabled = false;
                timeSlotSelect.querySelector('option[value=""]').textContent = '-- Select a time slot --';

                for (const slotValue in availableSlots) {
                    const option = timeSlotSelect.querySelector(`option[value="${slotValue}"]`);
                    if (option) {
                        const count = availableSlots[slotValue];
                        const originalText = option.textContent.split(' (')[0];
                        option.disabled = count >= 2;
                        option.classList.toggle('slot-full', count >= 2);
                        option.textContent = count >= 2 ? `${originalText} (Fully Booked)` : `${originalText} (${2 - count} slots left)`;
                    }
                }
            } catch (error) {
                console.error('Failed to fetch slots:', error);
                timeSlotSelect.querySelector('option[value=""]').textContent = 'Error loading slots';
            }
        }
        
        function validateDate() {
            if (!dateInput.value) return;
            const selectedDate = new Date(dateInput.value);
            const day = selectedDate.getUTCDay();

            const isInvalid = day !== 1 && day !== 3;
            dateErrorMsg.classList.toggle('hidden', !isInvalid);
            dateInput.classList.toggle('date-error', isInvalid);
            submitButton.disabled = isInvalid;
            timeSlotSelect.disabled = isInvalid;

            if (!isInvalid) {
                updateSlots(dateInput.value);
            }
        }

        dateInput.addEventListener('change', validateDate);
        validateDate(); // Initial check on page load
    });
    </script>
</body>
</html>