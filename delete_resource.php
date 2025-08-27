<?php
session_start();
require_once 'db_config.php';

// --- Admin Authentication Check ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

// --- Main Deletion Logic ---
if (isset($_GET['id'])) {
    $resource_id = intval($_GET['id']);

    // 1. Get the file path from the database BEFORE deleting the record
    $stmt_select = $conn->prepare("SELECT file_path FROM resources WHERE id = ?");
    $stmt_select->bind_param("i", $resource_id);
    $stmt_select->execute();
    $result = $stmt_select->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $file_path_from_db = $row['file_path'];

        // 2. Delete the physical file from the server
        // The path is stored as 'admin/uploads/file.ext', which is relative to the project root.
        // We go up one directory from /admin to get to the root, then follow the path.
        $physical_file_path = '../' . $file_path_from_db; 

        if (file_exists($physical_file_path)) {
            unlink($physical_file_path); // Deletes the file
        }

        // 3. Delete the record from the database
        $stmt_delete = $conn->prepare("DELETE FROM resources WHERE id = ?");
        $stmt_delete->bind_param("i", $resource_id);

        if ($stmt_delete->execute()) {
            $_SESSION['message'] = "Resource deleted successfully.";
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = "Error: Could not delete the resource from the database.";
            $_SESSION['message_type'] = 'error';
        }
        $stmt_delete->close();

    } else {
        $_SESSION['message'] = "Error: Resource not found.";
        $_SESSION['message_type'] = 'error';
    }
    
    $stmt_select->close();
    $conn->close();

} else {
    $_SESSION['message'] = "Error: Invalid request.";
    $_SESSION['message_type'] = 'error';
}

// 4. Redirect back to the learning hub
header("Location: learning_hub.php");
exit();
?>