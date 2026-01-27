<?php
session_start();
require_once 'config.php';

// Check if the user is logged in and is a restaurant manager
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "restaurant_manager") {
    header("location: login.php");
    exit;
}

$manager_user_id = $_SESSION['user_id'];
$restaurant_id = null;
$error_message = '';
$success_message = '';
$orders = [];
$delivery_personnel_list = []; // To store available delivery personnel

// Define the system's commission rate (e.g., 10%)
// NOTE: This value should ideally be fetched from a global settings table or defined in config.php
$COMMISSION_RATE = 0.10; 

// Function to safely output HTML special characters
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Fetch the restaurant_id associated with the logged-in manager
$sql_get_restaurant_id = "SELECT restaurant_id FROM restaurants WHERE manager_id = ?";
if ($stmt = $conn->prepare($sql_get_restaurant_id)) {
    $stmt->bind_param("i", $manager_user_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $restaurant_id = $result->fetch_assoc()['restaurant_id'];
        } else {
            $error_message = "No restaurant associated with your manager account, or invalid setup. Please contact support.";
        }
    } else {
        $error_message = "Error fetching restaurant ID: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error_message = "Error preparing query for restaurant ID: " . $conn->error;
}

// Fetch all active delivery personnel
$sql_get_delivery_personnel = "SELECT user_id, username FROM users WHERE role = 'delivery_personnel' AND is_active = 1 ORDER BY username";
if ($result_dp = $conn->query($sql_get_delivery_personnel)) {
    while ($row_dp = $result_dp->fetch_assoc()) {
        $delivery_personnel_list[] = $row_dp;
    }
    $result_dp->free();
} else {
    $error_message .= " Error fetching delivery personnel: " . $conn->error;
}

// Determine which delivery personnel are currently 'busy' with active orders (assigned or out_for_delivery)
$busy_delivery_personnel_ids = [];
$sql_busy_dp = "SELECT DISTINCT delivery_personnel_id FROM orders WHERE order_status IN ('assigned', 'out_for_delivery') AND delivery_personnel_id IS NOT NULL";
if ($result_busy = $conn->query($sql_busy_dp)) {
    while ($row_busy = $result_busy->fetch_assoc()) {
        $busy_delivery_personnel_ids[] = $row_busy['delivery_personnel_id'];
    }
    $result_busy->free();
} else {
    $error_message .= " Error fetching busy delivery personnel: " . $conn->error;
}


// --- Handle Order Status Update AND Delivery Personnel Assignment (POST request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_order') {
    if ($restaurant_id) {
        $order_id_to_update = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
        $new_status = trim($_POST['new_status']);
        $assigned_dp_id = filter_input(INPUT_POST, 'assign_delivery_personnel', FILTER_VALIDATE_INT);

        // Fetch the current status of the order before attempting to update
        $current_order_status = '';
        $sql_get_current_status = "SELECT order_status FROM orders WHERE order_id = ? AND restaurant_id = ?";
        if ($stmt_get_current_status = $conn->prepare($sql_get_current_status)) {
            $stmt_get_current_status->bind_param("ii", $order_id_to_update, $restaurant_id);
            $stmt_get_current_status->execute();
            $result_current_status = $stmt_get_current_status->get_result();
            if ($row = $result_current_status->fetch_assoc()) {
                $current_order_status = $row['order_status'];
            }
            $stmt_get_current_status->close();
        } else {
            $error_message = "Error preparing current status query: " . $conn->error;
        }

        // The list of statuses that prevent any further updates by the manager.
        // This now includes 'cancelled' as requested.
        $restricted_statuses_to_prevent_update = ['assigned', 'out_for_delivery', 'completed', 'cancelled'];
        
        $allowed_statuses_for_update = ['pending', 'preparing', 'ready_for_delivery','cancelled']; 
        
        // This check prevents managers from updating orders that are already in a final or in-transit state.
        if (in_array($current_order_status, $restricted_statuses_to_prevent_update)) {
            $error_message = "Order #" . $order_id_to_update . " cannot be updated. Its current status ('" . htmlspecialchars(ucwords(str_replace('_', ' ', $current_order_status))) . "') prevents changes.";
        }
        // Check if the manager is trying to change to an invalid status
        elseif (!in_array($new_status, $allowed_statuses_for_update)) {
            $error_message = "Invalid status provided. Managers can only set status to Pending, Preparing, Cancelled or Ready for Delivery.";
        } 
        // Check if ID is valid
        elseif ($order_id_to_update === false) {
            $error_message = "Invalid order ID.";
        } else {
            // Start a transaction for atomicity
            $conn->begin_transaction();
            try {
                
                // --- NEW: Fetch Order Details for Financial Rollback ---
                $original_total_amount = 0;
                $customer_id = 0;
                $sql_get_financial_data = "SELECT total_amount, customer_id, restaurant_id FROM orders WHERE order_id = ?";
                if ($stmt_fd = $conn->prepare($sql_get_financial_data)) {
                    $stmt_fd->bind_param("i", $order_id_to_update);
                    $stmt_fd->execute();
                    $result_fd = $stmt_fd->get_result();
                    if ($row_fd = $result_fd->fetch_assoc()) {
                        $original_total_amount = $row_fd['total_amount'];
                        $customer_id = $row_fd['customer_id'];
                    }
                    $stmt_fd->close();
                } else {
                     throw new Exception("Error preparing financial data fetch query: " . $conn->error);
                }
                
                // Update order status
                $sql_update_status = "UPDATE orders SET order_status = ? WHERE order_id = ? AND restaurant_id = ?";
                if ($stmt_update_status = $conn->prepare($sql_update_status)) {
                    $stmt_update_status->bind_param("sii", $new_status, $order_id_to_update, $restaurant_id);
                    if (!$stmt_update_status->execute()) {
                        throw new Exception("Error updating order status: " . $stmt_update_status->error);
                    }
                    $stmt_update_status->close();
                } else {
                    throw new Exception("Error preparing status update query: " . $conn->error);
                }

                // --- NEW: Implement Financial Rollback if status is 'cancelled' and it wasn't already cancelled ---
                if ($new_status === 'cancelled' && $current_order_status !== 'cancelled') {
                    
                    // Calculate the restaurant's net share (Full Amount * (1 - Commission Rate))
                    $restaurant_share = $original_total_amount * (1 - $COMMISSION_RATE);

                    // 1. Rollback money to Customer's virtual_balance in the users table
                    $sql_rollback_customer = "UPDATE users SET virtual_balance = virtual_balance + ? WHERE user_id = ?";
                    if ($stmt_rc = $conn->prepare($sql_rollback_customer)) {
                        // Use original_total_amount for the customer refund
                        $stmt_rc->bind_param("di", $original_total_amount, $customer_id);
                        if (!$stmt_rc->execute()) {
                            throw new Exception("Error rolling back customer balance: " . $stmt_rc->error);
                        }
                        $stmt_rc->close();
                        $success_message .= " Customer balance refunded: ETB " . number_format($original_total_amount, 2) . ".";
                    } else {
                        throw new Exception("Error preparing customer rollback query: " . $conn->error);
                    }
                    
                    // 2. Deduct the Restaurant's Share from the restaurant_balance in the restaurants table 
                    $sql_deduct_restaurant = "UPDATE restaurants SET restaurant_balance = restaurant_balance - ? WHERE restaurant_id = ?";
                    if ($stmt_dr = $conn->prepare($sql_deduct_restaurant)) {
                        // Deduct the restaurant's net share 
                        $stmt_dr->bind_param("di", $restaurant_share, $restaurant_id); 
                        if (!$stmt_dr->execute()) {
                            throw new Exception("Error deducting restaurant balance: " . $stmt_dr->error);
                        }
                        $stmt_dr->close();
                        $success_message .= " Restaurant balance deducted: ETB " . number_format($restaurant_share, 2) . ".";
                    } else {
                         throw new Exception("Error preparing restaurant deduction query: " . $conn->error);
                    }
                }

                // Determine the correct value for delivery_personnel_id (NULL for unassigned, or actual ID)
                $param_assigned_dp_id = ($assigned_dp_id === false || $assigned_dp_id === 0) ? NULL : $assigned_dp_id;
                
                // Fetch current delivery_personnel_id to prevent unnecessary updates
                $current_delivery_personnel_id = NULL;
                $sql_get_current_dp = "SELECT delivery_personnel_id FROM orders WHERE order_id = ?";
                if ($stmt_get_current_dp = $conn->prepare($sql_get_current_dp)) {
                    $stmt_get_current_dp->bind_param("i", $order_id_to_update);
                    $stmt_get_current_dp->execute();
                    $result_current_dp = $stmt_get_current_dp->get_result();
                    if ($row_current_dp = $result_current_dp->fetch_assoc()) {
                        $current_delivery_personnel_id = $row_current_dp['delivery_personnel_id'];
                    }
                    $stmt_get_current_dp->close();
                } else {
                     throw new Exception("Error preparing current DP fetch query: " . $conn->error);
                }

                // Only update if the selected DP is different from current, or if current is NULL and a DP is selected
                if ($param_assigned_dp_id != $current_delivery_personnel_id) {
                    $sql_assign_dp = "UPDATE orders SET delivery_personnel_id = ? WHERE order_id = ? AND restaurant_id = ?";
                    if ($stmt_assign_dp = $conn->prepare($sql_assign_dp)) {
                        // Use a custom type parameter string if $param_assigned_dp_id can be NULL
                        if ($param_assigned_dp_id === NULL) {
                             // Use 'i' for integer, but PHP will pass NULL correctly if param is NULL
                            $stmt_assign_dp->bind_param("iii", $param_assigned_dp_id, $order_id_to_update, $restaurant_id);
                        } else {
                            $stmt_assign_dp->bind_param("iii", $param_assigned_dp_id, $order_id_to_update, $restaurant_id);
                        }
                       
                        if (!$stmt_assign_dp->execute()) {
                            throw new Exception("Error assigning delivery personnel: " . $stmt_assign_dp->error);
                        }
                        $stmt_assign_dp->close();
                    } else {
                        throw new Exception("Error preparing delivery personnel assignment query: " . $conn->error);
                    }
                }

                $conn->commit();
                
                // Consolidate success messages
                $base_success_message = "Order #" . $order_id_to_update . " updated (status: '" . htmlspecialchars(ucwords(str_replace('_', ' ', $new_status))) . "')";
                
                if ($param_assigned_dp_id !== NULL) {
                     $assigned_personnel_name = "None"; // Default
                     foreach($delivery_personnel_list as $dp) {
                         if ($dp['user_id'] == $param_assigned_dp_id) {
                             $assigned_personnel_name = $dp['username'];
                             break;
                         }
                     }
                     $base_success_message .= " and assigned to " . htmlspecialchars($assigned_personnel_name) . ".";
                } else if ($param_assigned_dp_id === NULL && $current_delivery_personnel_id !== NULL) {
                     $base_success_message .= " and unassigned from delivery personnel.";
                } else {
                    $base_success_message .= ".";
                }
                
                $success_message = $base_success_message . (empty($success_message) ? "" : $success_message);

            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Failed to update order #" . $order_id_to_update . ": " . $e->getMessage();
            }
        }
    }
}


// --- Fetch Orders for the Manager's Restaurant ---
// Updated to fetch ALL relevant orders for manager tracking (including assigned, out_for_delivery, and completed).
$sql_fetch_orders = "
    SELECT
        o.order_id,
        o.total_amount,
        o.order_date,
        o.order_status,
        o.payment_method,
        o.delivery_address,
        o.latitude,
        o.longitude,
        o.delivery_personnel_id,
        u.username AS customer_username,
        u.phone_number AS customer_phone,
        dp_u.username AS delivery_personnel_username
    FROM orders o
    JOIN users u ON o.customer_id = u.user_id
    LEFT JOIN users dp_u ON o.delivery_personnel_id = dp_u.user_id
    WHERE o.restaurant_id = ?
    AND o.order_status IN ('pending', 'preparing', 'ready_for_delivery' ,'cancelled', 'assigned', 'out_for_delivery', 'completed')
    ORDER BY o.order_date DESC
";
if ($restaurant_id) {
    if ($stmt_orders = $conn->prepare($sql_fetch_orders)) {
        $stmt_orders->bind_param("i", $restaurant_id);
        if ($stmt_orders->execute()) {
            $result_orders = $stmt_orders->get_result();
            while ($order_row = $result_orders->fetch_assoc()) {
                $current_order_id = $order_row['order_id'];
                $order_row['items'] = [];

                // Fetch items for the current order
                $sql_fetch_items = "
                    SELECT
                        oi.quantity,
                        oi.price_at_order,
                        mi.name AS item_name
                    FROM order_items oi
                    JOIN menu_items mi ON oi.item_id = mi.item_id
                    WHERE oi.order_id = ?
                ";
                if ($stmt_items = $conn->prepare($sql_fetch_items)) {
                    $stmt_items->bind_param("i", $current_order_id);
                    if($stmt_items->execute()) {
                        $result_items = $stmt_items->get_result();
                        while ($item_row = $result_items->fetch_assoc()) {
                            $order_row['items'][] = $item_row;
                        }
                        $result_items->free();
                    } else {
                        $error_message .= " Error fetching items for order " . $current_order_id . ": " . $stmt_items->error;
                    }
                    $stmt_items->close();
                } else {
                    $error_message .= " Error preparing items query for order " . $current_order_id . ": " . $conn->error;
                }
                $orders[] = $order_row;
            }
            $result_orders->free();
        } else {
            $error_message .= " Error fetching orders: " . $stmt_orders->error;
        }
        $stmt_orders->close();
    } else {
        $error_message .= " Error preparing orders query: " . $conn->error;
    }
}


$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* ======================================================= */
        /* === GLOBAL BODY RESET (Fixes space above header) === */
        /* ======================================================= */
        body {
            margin: 0; /* Remove default body margin */
            padding: 0; /* Remove default body padding */
            font-family: Arial, sans-serif; /* Keep your existing font */
            background-color: #f4f4f4; /* Keep your existing background */
            color: #333;
            line-height: 1.6;
        }

        /* ======================================================= */
        /* === HEADER STYLING FOR MANAGER PAGES (Consistent with Dashboard) === */
        /* ======================================================= */
       

        header h1 {
            margin: 0;
            float: left;
            font-size: 2em;
        }

        header nav ul {
            margin: 0;
            padding: 0;
            list-style: none;
            float: right;
            display: block; /* Ensure it's visible */
        }

        header nav ul li {
            display: inline; /* Keep list items inline for horizontal nav */
            margin-left: 20px; /* Space between items */
        }

        header nav ul li a {
            color: #100c0cff;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s ease;
        }

        header nav ul li a:hover {
            color: #ff1803bb;
        }

        /* Clearfix for header */
        header .container::after {
            content: "";
            display: table;
            clear: both;
        }

        /* ======================================================= */
        /* === MANAGE ORDERS PAGE SPECIFIC STYLING === */
        /* ======================================================= */
        .order-management-content {
            padding: 40px 0;
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .order-card {
            background-color: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border: 1px solid #eee;
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 0; /* Changed to 0 since details are now separate */
            /* Make header clickable */
            cursor: pointer; 
            user-select: none;
        }
        .order-header:hover {
             background-color: #f9f9f9;
        }
        
        /* New container for ID and Date/Time */
        .order-title-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex-grow: 1; /* Allow it to take up available space */
        }
        
        .order-title-group h3 {
            color: #ff6f61;
            margin: 0;
            font-size: 1.8em;
        }
        
        /* Style for the date/time below the ID */
        .order-date-time {
            font-size: 0.85em;
            color: #888;
            font-weight: normal;
        }

        .order-header .status {
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 5px;
            color: white;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 15px; /* Add some space from the title group */
        }
        
        /* Collapse Icon Styling */
        .collapse-icon {
            transition: transform 0.3s ease;
            font-size: 1.2em;
        }
        /* Rotate the icon when the card has the 'open' class */
        .order-card.open .collapse-icon {
            transform: rotate(180deg);
        }

        /* Status specific colors */
        .status.pending { background-color: #ffc107; } /* Warning yellow */
        .status.preparing { background-color: #007bff; } /* Blue */
        .status.ready_for_delivery { background-color: #28a745; } /* Success green */
        .status.out_for_delivery { background-color: #17a2b8; } /* Info blue */
        .status.assigned { background-color: #9c27b0; } /* Purple for assigned - new */
        .status.completed { background-color: #6c757d; } /* Grey */
        .status.cancelled { background-color: #dc3545; } /* Danger red */

        /* ------------------------------------------------------------------- */
        /* COLLAPSE FUNCTIONALITY STYLING */
        /* ------------------------------------------------------------------- */
        .order-details-content {
            /* Hide by default, use max-height for CSS transition */
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s ease-out, padding 0.5s ease-out;
            padding-top: 0;
        }

        .order-card.open .order-details-content {
            /* Set a large enough max-height when open */
            max-height: 1000px; /* Adjust this value if content is very long */
            padding-top: 20px; /* Restore padding when open */
            border-top: 1px solid #eee; /* Add separator when open */
            margin-top: 15px;
        }
        
        .order-info p {
            margin: 5px 0;
            font-size: 1.05em;
            color: #555;
        }
        .order-info strong {
            color: #333;
        }
        .assigned-dp {
            font-size: 0.95em;
            color: #007bff; /* Blue to highlight assignment */
            font-weight: bold;
            margin-top: 5px;
        }

        .order-items-list {
            margin-top: 20px;
            border-top: 1px dashed #ddd;
            padding-top: 20px;
        }
        .order-items-list h4 {
            color: #333;
            font-size: 1.3em;
            margin-bottom: 15px;
        }
        .order-item-detail {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.95em;
            color: #666;
        }

        .order-action-form-group {
            margin-top: 20px;
            border-top: 1px solid #f0f0f0;
            padding-top: 20px;
            display: flex;
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
            gap: 15px; /* Space between form elements */
            align-items: center;
            justify-content: flex-end; /* Align to the right */
        }
        .order-action-form-group label {
            font-weight: bold;
            color: #555;
            flex-basis: 100%; /* Take full width on small screens */
            text-align: right; /* Align label to the right of itself */
        }
        .order-action-form-group select,
        .order-action-form-group button {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1em;
            background-color: #f8f8f8;
            cursor: pointer;
            flex-grow: 1; /* Allow select/button to grow */
            max-width: 200px; /* Limit max width for form elements */
        }
        .order-action-form-group button {
            background-color: #007bff;
            color: white;
            border: none;
            transition: background-color 0.3s ease;
        }
        .order-action-form-group button:hover {
            background-color: #0056b3;
        }
        /* Specific styling for the 'Assign Delivery Personnel' section */
        .assign-dp-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            width: 100%; /* Take full width of parent form group */
            justify-content: flex-end; /* Align to the right */
        }
        .assign-dp-group label {
            flex-basis: auto; /* Don't force label to take full width */
            margin-right: 5px;
        }
        .assign-dp-group select {
            flex-grow: 1;
            max-width: 250px; /* Adjust as needed */
        }
        /* Style for disabled select options */
        .assign-dp-group select option:disabled {
            background-color: #f0f0f0;
            color: #999;
            font-style: italic;
        }

        .no-orders-message {
            text-align: center;
            margin-top: 50px;
            font-size: 1.3em;
            color: #666;
        }

        .message, .error-message {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
            width: 100%; /* Ensure messages span full width */
            box-sizing: border-box; /* Include padding in width */
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6fb;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .order-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .order-title-group {
                /* On small screens, title group spans full width */
                width: 100%; 
            }
            .order-header .status {
                margin-left: 0;
            }
            .order-action-form-group {
                flex-direction: column;
                align-items: flex-start;
            }
            .order-action-form-group select,
            .order-action-form-group button {
                width: 100%;
                max-width: unset; /* Remove max-width on mobile */
            }
            .assign-dp-group {
                flex-direction: column;
                align-items: flex-start;
            }
            .assign-dp-group select {
                width: 100%;
                max-width: unset;
            }
            .order-action-form-group label {
                 text-align: left; /* Adjust label alignment for mobile */
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Debre Tabor Food Delivery</h1>
            <nav>
                <ul>
                    <!-- Manager-specific navigation, always visible for logged-in managers -->
                    <li><a href="restaurant_manager_dashboard.php">Dashboard</a></li>
                    <li><a href="manage_menu.php">Manage Menu</a></li>
                    <li><a href="manage_orders.php">Orders</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <h1>Manage Restaurant Orders</h1>

            <?php if (!empty($success_message)): ?>
                <div class="message"><?php echo h($success_message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="error-message"><?php echo h($error_message); ?></div>
            <?php endif; ?>

            <div class="order-management-content">
                <?php if ($restaurant_id === null): ?>
                    <p class="error-message">Error: This manager account is not linked to a restaurant. Please contact the administrator.</p>
                <?php elseif (empty($orders)): ?>
                    <!-- Updated message to reflect the wider search criteria -->
                    <p class="no-orders-message">No orders found for your restaurant across any status (Pending, Preparing, Ready, Assigned, Out for Delivery, Completed, Cancelled).</p>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <!-- Added onclick handler to the header to toggle the details -->
                        <div class="order-card" id="order-card-<?php echo htmlspecialchars($order['order_id']); ?>">
                            <div class="order-header" onclick="toggleOrderDetails(<?php echo htmlspecialchars($order['order_id']); ?>)">
                                <!-- NEW: Grouping the ID and Date/Time -->
                                <div class="order-title-group">
                                    <h3>Order #<?php echo htmlspecialchars($order['order_id']); ?></h3>
                                    <span class="order-date-time">
                                        <?php echo date('M d, Y H:i:s', strtotime($order['order_date'])); ?>
                                    </span>
                                </div>
                                
                                <span class="status <?php echo htmlspecialchars(str_replace(' ', '_', $order['order_status'])); ?>">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['order_status']))); ?>
                                    <!-- Collapse Icon -->
                                    <i class="fas fa-chevron-down collapse-icon"></i>
                                </span>
                            </div>
                            
                            <!-- New container for collapsible content -->
                            <div class="order-details-content">
                                <div class="order-info">
                                    <p><strong>Customer:</strong> <?php echo htmlspecialchars($order['customer_username']); ?> (Phone: <?php echo htmlspecialchars($order['customer_phone']); ?>)</p>
                                    <p><strong>Total Amount:</strong> ETB <?php echo number_format($order['total_amount'], 2); ?></p>
                                    <!-- Order Date/Time is now in the header, so we remove the duplicate from here -->
                                    <p><strong>Payment Method:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['payment_method']))); ?></p>
                                    <p><strong>Delivery Address:</strong> <?php echo !empty($order['delivery_address']) ? htmlspecialchars($order['delivery_address']) : 'Not provided (GPS used)'; ?></p>
                                    <p><strong>Location:</strong> Latitude: <?php echo htmlspecialchars($order['latitude']); ?>, Longitude: <?php echo htmlspecialchars($order['longitude']); ?></p>
                                    <?php if (!empty($order['delivery_personnel_username'])): ?>
                                        <p class="assigned-dp"><strong>Assigned to:</strong> <?php echo htmlspecialchars($order['delivery_personnel_username']); ?></p>
                                    <?php else: ?>
                                        <p class="assigned-dp" style="color: #dc3545;"><strong>Assigned to:</strong> Not Assigned</p>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($order['items'])): ?>
                                    <div class="order-items-list">
                                        <h4>Ordered Items:</h4>
                                        <?php foreach ($order['items'] as $item): ?>
                                            <div class="order-item-detail">
                                                <span><?php echo htmlspecialchars($item['item_name']); ?> (x<?php echo htmlspecialchars($item['quantity']); ?>)</span>
                                                <span>ETB <?php echo number_format($item['price_at_order'] * $item['quantity'], 2); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="order-items-list">No items found for this order.</p>
                                <?php endif; ?>

                                <!-- The action form remains hidden until details are expanded -->
                                <div class="order-action-form-group">
                                    <form action="manage_orders.php" method="POST" style="display:contents;">
                                        <input type="hidden" name="action" value="update_order">
                                        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order['order_id']); ?>">
                                        
                                        <label for="status-<?php echo htmlspecialchars($order['order_id']); ?>">Update Status:</label>
                                        <?php
                                            // Determine if the current order status restricts updates
                                            $is_restricted = in_array($order['order_status'], ['assigned', 'out_for_delivery', 'completed', 'cancelled']);
                                            // The select element will be disabled if the order is in a restricted status.
                                            $select_disabled = $is_restricted ? 'disabled' : '';
                                            $button_disabled = $is_restricted ? 'disabled' : '';
                                        ?>
                                        <select name="new_status" id="status-<?php echo htmlspecialchars($order['order_id']); ?>" <?php echo $select_disabled; ?>>
                                            <option value="pending" <?php echo ($order['order_status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                            <option value="preparing" <?php echo ($order['order_status'] == 'preparing') ? 'selected' : ''; ?>>Preparing</option>
                                            <option value="cancelled" <?php echo ($order['order_status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                            <option value="ready_for_delivery" <?php echo ($order['order_status'] == 'ready_for_delivery') ? 'selected' : ''; ?>>Ready for Delivery</option>
                                            <?php if ($is_restricted): ?>
                                                <!-- If restricted, show the current status as a disabled, selected option to prevent unintended change on form submission -->
                                                <option value="<?php echo htmlspecialchars($order['order_status']); ?>" selected>
                                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['order_status']))); ?> (Cannot Change)
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                        
                                        <div class="assign-dp-group">
                                            <label for="assign-dp-<?php echo htmlspecialchars($order['order_id']); ?>">Assign Delivery Personnel:</label>
                                            <select name="assign_delivery_personnel" id="assign-dp-<?php echo htmlspecialchars($order['order_id']); ?>" <?php echo $select_disabled; ?>>
                                                <option value="0">-- Unassigned --</option>
                                                <?php foreach ($delivery_personnel_list as $dp):
                                                    $is_currently_assigned = ($order['delivery_personnel_id'] == $dp['user_id']);
                                                    $is_busy_with_another_order = in_array($dp['user_id'], $busy_delivery_personnel_ids) && !$is_currently_assigned;
                                                    $disabled_attr = ($is_busy_with_another_order || $is_restricted) ? 'disabled' : ''; // Disable if restricted or busy
                                                    $selected_attr = $is_currently_assigned ? 'selected' : '';
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($dp['user_id']); ?>"
                                                        <?php echo $selected_attr; ?> <?php echo $disabled_attr; ?>>
                                                        <?php echo htmlspecialchars($dp['username']); ?>
                                                        <?php echo $is_busy_with_another_order ? ' (Busy)' : ''; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" <?php echo $button_disabled; ?>>Update Order</button>
                                    </form>
                                </div>
                            </div> <!-- End .order-details-content -->
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>
    
    <script>
        /**
         * Toggles the visibility of order details when the header is clicked.
         * @param {number} orderId - The ID of the order card to toggle.
         */
        function toggleOrderDetails(orderId) {
            const card = document.getElementById(`order-card-${orderId}`);
            if (card) {
                // Toggle the 'open' class on the parent card
                card.classList.toggle('open');
            }
        }

        // Optional: Open 'pending' and 'preparing' orders by default for immediate attention
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.order-card').forEach(card => {
                const statusSpan = card.querySelector('.order-header .status');
                if (statusSpan) {
                    const statusClass = statusSpan.className;
                    // Automatically open 'pending' and 'preparing' orders
                    if (statusClass.includes('pending') || statusClass.includes('preparing')) {
                        card.classList.add('open');
                    }
                }
            });
        });
    </script>
</body>
</html>