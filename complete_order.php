<?php
// Database connection
$host = 'localhost';
$db = 'food_delivery_db';
$user = 'root';
$pass = '';

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if order ID is set
if (isset($_GET['order_id'])) {
    $order_id = intval($_GET['order_id']);

    // Update order status to completed
    $sql = "UPDATE orders SET status = 'completed' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $order_id);

    if ($stmt->execute()) {
        echo "Order status updated to completed.";
    } else {
        echo "Error updating order status: " . $conn->error;
    }

    $stmt->close();
} else {
    echo "No order ID provided.";
}

$conn->close();
?>