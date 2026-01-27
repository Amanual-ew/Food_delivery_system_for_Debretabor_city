<?php
session_start();
require_once 'config.php'; // Requires your database connection file

// 1. Security check: Must be a logged-in customer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "customer") {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];

// 2. Validate input
if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
    // Redirect with error message if order_id is missing or invalid
    header("location: customer_dashboard.php?status=error&message=" . urlencode("Invalid order ID provided."));
    exit;
}

$order_id = (int)$_GET['order_id'];
$initiator = isset($_GET['initiator']) ? $_GET['initiator'] : 'customer'; // Changed default to 'customer' as this is the customer's page

// Define statuses where cancellation is allowed for the customer
$cancellable_statuses = ['pending', 'preparing', 'out_for_delivery'];
$conn->autocommit(FALSE); // Disable autocommit to start the transaction

try {
    // --- Step 3: Lock and retrieve order details ---
    // Select the order and lock the row FOR UPDATE to prevent other processes from changing it
    // Note: It's crucial that 'customer_id' matches the session 'user_id' for security.
    $sql_check_order = "SELECT customer_id, total_amount, order_status FROM orders WHERE order_id = ? AND customer_id = ? FOR UPDATE";
    if ($stmt_check = $conn->prepare($sql_check_order)) {
        $stmt_check->bind_param("ii", $order_id, $user_id);
        $stmt_check->execute();
        $result = $stmt_check->get_result();
        
        if ($result->num_rows === 0) {
            // User ID doesn't match Order's Customer ID, or Order ID doesn't exist
            throw new Exception("Order not found or you do not have permission to cancel it.");
        }
        
        $order_data = $result->fetch_assoc();
        $current_status = $order_data['order_status'];
        $total_amount = $order_data['total_amount'];
        $stmt_check->close();

        // --- Step 4: Check if cancellable ---
        if (!in_array($current_status, $cancellable_statuses)) {
            // Specific redirect for already cancelled/delivered orders
            $conn->rollback(); 
            header("location: customer_dashboard.php?message=already_cancelled");
            exit;
        }

        // --- Step 5: Calculate Refund ---
        // Customer receives 50% refund for canceling an active order
        $refund_percentage = 0.50;
        $refund_amount = $total_amount * $refund_percentage;

        // --- Step 6: Update Order Status ---
        $new_status = 'cancelled';
        // This query requires the 'cancelled_by' and 'cancellation_date' columns to exist!
        $sql_update_order = "UPDATE orders SET order_status = ?, cancelled_by = ?, cancellation_date = NOW() WHERE order_id = ?";
        if ($stmt_update_order = $conn->prepare($sql_update_order)) {
            $stmt_update_order->bind_param("ssi", $new_status, $initiator, $order_id);
            if (!$stmt_update_order->execute()) {
                throw new Exception("Failed to update order status: " . $stmt_update_order->error);
            }
            $stmt_update_order->close();
        } else {
             throw new Exception("Failed to prepare order update query: " . $conn->error);
        }

        // --- Step 7: Update User Balance (The Critical Part) ---
        // Atomically update the user's virtual_balance by adding the refund amount
        $sql_update_balance = "UPDATE users SET virtual_balance = virtual_balance + ? WHERE user_id = ?";
        if ($stmt_update_balance = $conn->prepare($sql_update_balance)) {
            // Note: 'd' for double/float type binding
            $stmt_update_balance->bind_param("di", $refund_amount, $user_id); 
            if (!$stmt_update_balance->execute()) {
                throw new Exception("Failed to update user balance: " . $stmt_update_balance->error);
            }
            $stmt_update_balance->close();
        } else {
             throw new Exception("Failed to prepare balance update query: " . $conn->error);
        }

        // --- Step 8: Commit Transaction ---
        $conn->commit();
        
        // Success: Redirect back to the dashboard with the refund amount
        header("location: customer_dashboard.php?status=success&refund=" . $refund_amount);
        exit;

    } else {
        throw new Exception("Failed to prepare order check query: " . $conn->error);
    }
} catch (Exception $e) {
    // If any error occurred, rollback the transaction
    $conn->rollback();

    // =========================================================================
    // === TEMPORARY DEBUGGING OUTPUT: THIS WILL SHOW THE ERROR ===
    // =========================================================================
    // We stop the script here and display the detailed error text.
    echo "<h1>Critical Cancellation Error Occurred (DEBUG MODE)</h1>";
    echo "<p>Please copy this error message and report it:</p>";
    echo "<b>Detailed Error:</b> " . htmlspecialchars($e->getMessage());
    echo "<br><b>Order ID:</b> " . $order_id;
    echo "<br><b>User ID (Attempting to Cancel):</b> " . $user_id;
    // =========================================================================
    
    // WARNING: Do NOT uncomment the header redirect or close the connection 
    // when debugging like this, or you will lose the error message!
    exit;
} finally {
    // We only re-enable autocommit and close the connection if the script was successful (no 'exit' reached)
    // If 'exit' was reached in the catch block, the connection remains open until script ends.
    if ($conn->autocommit(TRUE) && $conn->close()) {
        // Closed successfully
    }
}
?>