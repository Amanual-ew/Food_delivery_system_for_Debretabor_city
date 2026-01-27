<?php
session_start(); // Always start the session at the very beginning
require_once 'config.php'; // Include the database connection

// Check if the user is logged in AND if their role is 'customer'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "customer") {
    header("location: login.php"); // Redirect to login if not a logged-in customer
    exit;
}

// --- START: Added Language and Localization Logic from restaurants.php ---



function t($key) {
    global $translations, $lang;
    return $translations[$lang][$key] ?? $key; 
}
// --- END: Added Language and Localization Logic ---

$user_id = $_SESSION["user_id"];
$username = $_SESSION["username"] ?? 'Customer'; // Fallback username
$error_message = '';
$success_message = '';
$order_history = []; // To store all orders fetched from DB

// Function to safely output HTML special characters
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// --- Handle Order Completion Action (from Customer) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'mark_customer_completed') {
    $order_id_to_update = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

    if ($order_id_to_update === false) {
        $error_message = "Invalid order ID.";
    } else {
        // Re-establish connection if it was closed at the end of the script
        // This is important because the script runs from top to bottom.
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $conn->begin_transaction(); // Start a transaction for atomicity
        try {
            // Check if the order is actually for this customer and is out for delivery
            $sql_check_order = "SELECT order_status FROM orders WHERE order_id = ? AND customer_id = ? AND order_status = 'out_for_delivery'";
            if ($stmt_check = $conn->prepare($sql_check_order)) {
                $stmt_check->bind_param("ii", $order_id_to_update, $user_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                if ($result_check->num_rows === 0) {
                    throw new Exception("Order not found, not yours, or not yet out for delivery.");
                }
                $stmt_check->close();
            } else {
                throw new Exception("Error preparing order check query: " . $conn->error);
            }

            // Update the order status to 'completed'
            $sql_update = "UPDATE orders SET order_status = 'completed', delivery_completion_date = NOW() WHERE order_id = ? AND customer_id = ?";
            if ($stmt = $conn->prepare($sql_update)) {
                $stmt->bind_param("ii", $order_id_to_update, $user_id);
                if ($stmt->execute()) {
                    $success_message = "Order #" . h($order_id_to_update) . " marked as received and complete!";
                } else {
                    throw new Exception("Failed to mark order as complete: " . $stmt->error);
                }
                $stmt->close();
            } else {
                throw new Exception("Error preparing update query: " . $conn->error);
            }
            $conn->commit(); // Commit transaction on success
        } catch (Exception $e) {
            $conn->rollback(); // Rollback on error
            $error_message = $e->getMessage();
        }
        $conn->close(); // Close the connection after the transaction
    }
}

// --- Fetch Customer's Order History ---
// Create a new connection for fetching data
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    // We use die here because if the DB isn't available, the page is useless.
    die("Connection failed: " . $conn->connect_error);
}

$sql_order_history = "
    SELECT
        o.order_id,
        o.delivery_address,
        o.total_amount,
        o.order_status,
        o.order_date,
        r.name AS restaurant_name,
        r.address AS restaurant_address,
        u_dp.username AS delivery_personnel_username,
        u_dp.phone_number AS delivery_personnel_phone
    FROM
        orders o
    JOIN
        restaurants r ON o.restaurant_id = r.restaurant_id
    LEFT JOIN
        users u_dp ON o.delivery_personnel_id = u_dp.user_id
    WHERE
        o.customer_id = ?
    ORDER BY o.order_date DESC;
";
if ($stmt_order_history = $conn->prepare($sql_order_history)) {
    $stmt_order_history->bind_param("i", $user_id);
    if ($stmt_order_history->execute()) {
        $result_order_history = $stmt_order_history->get_result();
        while ($row = $result_order_history->fetch_assoc()) {
            $order_history[] = $row;
        }
        $result_order_history->free();
    } else {
        $error_message .= " Error fetching your orders: " . $stmt_order_history->error;
    }
    $stmt_order_history->close();
} else {
    $error_message .= " Error preparing customer orders query: " . $conn->error;
}

$conn->close();?>
 <!DOCTYPE html>
 <html lang="en">
 <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Successfully mark as received</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f9f9f9;
        }
        .message-box {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        a {
            text-decoration: none;
            color: #007bff;
        }
     </style>

 </head>

 <body>
    
 </body>
 <script>
    alert("Order status updated to completed.");
    window.location.href = "customer_dashboard.php";
 </script>
 </html>