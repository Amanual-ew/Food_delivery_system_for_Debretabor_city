<?php
// Test script for staff registration functionality
require_once 'config.php';

// Test database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
} else {
    echo "Database connection successful!\n";
}

// Test if users table exists and check its structure
$result = $conn->query("DESCRIBE users");
if ($result) {
    echo "Users table exists with columns:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    $result->free();
} else {
    echo "Users table does not exist or cannot be accessed.\n";
}

// Test if delivery_personnel_detail table exists
$result = $conn->query("DESCRIBE delivery_personnel_details");
if ($result) {
    echo "\nDelivery personnel detail table exists with columns:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    $result->free();
} else {
    echo "\nDelivery personnel detail table does not exist or cannot be accessed.\n";
}

$conn->close();
echo "\nTest completed.\n";
?>
