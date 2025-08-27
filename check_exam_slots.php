<?php
// check_exam_slots.php
header('Content-Type: application/json');
require_once 'db_config.php';

// Check if the date parameter is set
if (!isset($_GET['date'])) {
    echo json_encode(['error' => 'Date not provided.']);
    exit;
}

$exam_date = $_GET['date'];

// Validate date format (YYYY-MM-DD)
$date_parts = explode('-', $exam_date);
if (count($date_parts) !== 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
    echo json_encode(['error' => 'Invalid date format.']);
    exit;
}

// SQL to count exams for each slot on the given date
$sql = "SELECT time_slot, COUNT(id) as count 
        FROM exams 
        WHERE exam_date = ? 
        GROUP BY time_slot";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $exam_date);
$stmt->execute();
$result = $stmt->get_result();

$slots = [
    '11:00-13:00' => 0,
    '14:00-16:00' => 0,
    '16:00-18:00' => 0,
];

while ($row = $result->fetch_assoc()) {
    if (isset($slots[$row['time_slot']])) {
        $slots[$row['time_slot']] = (int)$row['count'];
    }
}

$stmt->close();
$conn->close();

echo json_encode($slots);