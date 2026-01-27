<?php
session_start();
require_once 'config.php'; // Ensure this path is correct for your database connection

// Check if the user is logged in and is a delivery personnel
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "delivery_personnel") {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Delivery Personnel'; // Fallback username
$error_message = '';
$success_message = '';
$current_deliveries = [];
$available_orders = []; // New array to store available orders

// Function to safely output HTML special characters
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// --- Handle Order Status Update Actions (Accept or Mark Out for Delivery) ---\
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $order_id_to_update = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

    if ($order_id_to_update === false) {
        $error_message = "Invalid order ID.";
    } else {
        $conn->begin_transaction(); // Start a transaction for atomicity
        try {
            if ($action === 'accept_order') {
                // Check if order is still available and ready for delivery and unassigned
                $sql_check_order = "SELECT order_status FROM orders WHERE order_id = ? AND order_status = 'ready_for_delivery' AND delivery_personnel_id IS NULL";
                if ($stmt_check = $conn->prepare($sql_check_order)) {
                    $stmt_check->bind_param("i", $order_id_to_update);
                    $stmt_check->execute();
                    $result_check = $stmt_check->get_result();
                    if ($result_check->num_rows === 0) {
                        throw new Exception("Order is no longer available, already assigned, or not ready for delivery.");
                    }
                    $stmt_check->close();
                } else {
                    throw new Exception("Error preparing order check query: " . $conn->error);
                }

                // Update the order status to 'assigned' and assign to the current delivery personnel
                $sql_update = "UPDATE orders SET order_status = 'assigned', delivery_personnel_id = ? WHERE order_id = ?";
                if ($stmt = $conn->prepare($sql_update)) {
                    $stmt->bind_param("ii", $user_id, $order_id_to_update);
                    if ($stmt->execute()) {
                        $success_message = "Order #" . $order_id_to_update . " accepted successfully! It's now assigned to you.";
                    } else {
                        throw new Exception("Failed to accept order: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    throw new Exception("Error preparing accept order query: " . $conn->error);
                }
            } elseif ($action === 'mark_out_for_delivery') {
                // Check if the order is currently assigned to this delivery personnel
                $sql_check_assigned = "SELECT order_status FROM orders WHERE order_id = ? AND delivery_personnel_id = ? AND order_status = 'assigned'";
                 if ($stmt_check_assigned = $conn->prepare($sql_check_assigned)) {
                    $stmt_check_assigned->bind_param("ii", $order_id_to_update, $user_id);
                    $stmt_check_assigned->execute();
                    $result_check_assigned = $stmt_check_assigned->get_result();
                    if ($result_check_assigned->num_rows === 0) {
                        throw new Exception("Order is not assigned to you or not in the 'assigned' status.");
                    }
                    $stmt_check_assigned->close();
                } else {
                    throw new Exception("Error preparing 'mark out for delivery' check query: " . $conn->error);
                }

                // Update the order status to 'out_for_delivery'
                $sql_update = "UPDATE orders SET order_status = 'out_for_delivery' WHERE order_id = ? AND delivery_personnel_id = ?";
                if ($stmt = $conn->prepare($sql_update)) {
                    $stmt->bind_param("ii", $order_id_to_update, $user_id);
                    if ($stmt->execute()) {
                        $success_message = "Order #" . $order_id_to_update . " is now out for delivery!";
                    } else {
                        throw new Exception("Failed to mark order as out for delivery: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    throw new Exception("Error preparing 'mark out for delivery' query: " . $conn->error);
                }
            }
            $conn->commit(); // Commit transaction on success
        } catch (Exception $e) {
            $conn->rollback(); // Rollback on error
            $error_message = $e->getMessage();
        }
    }
}

// --- Fetch Current Deliveries for the Delivery Personnel ---
// These are orders that are 'assigned' to this DP or 'out_for_delivery'
$sql_current_deliveries = "
    SELECT
        o.order_id,
        o.delivery_address,
        o.total_amount,
        o.order_status,
        o.order_date,
        u.username AS customer_username,
        u.phone_number AS customer_phone,
        o.latitude,
        o.longitude,
        r.name AS restaurant_name,
        r.address AS restaurant_address
    FROM
        orders o
    JOIN
        users u ON o.customer_id = u.user_id
    JOIN
        restaurants r ON o.restaurant_id = r.restaurant_id
    WHERE
        o.delivery_personnel_id = ? AND o.order_status IN ('assigned', 'out_for_delivery')
    ORDER BY o.order_date DESC;
";
if ($stmt = $conn->prepare($sql_current_deliveries)) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $current_deliveries[] = $row;
        }
        $result->free();
    } else {
        $error_message .= " Error fetching current deliveries: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error_message .= " Error preparing current deliveries query: " . $conn->error;
}


// --- Fetch Available Orders for Assignment ---
// These are orders that are 'ready_for_delivery' and NOT assigned to anyone
$sql_available_orders = "
    SELECT
        o.order_id,
        o.delivery_address,
        o.total_amount,
        o.order_date,
        u.username AS customer_username,
        u.phone_number AS customer_phone,
        o.latitude,
        o.longitude,
        r.name AS restaurant_name,
        r.address AS restaurant_address
    FROM
        orders o
    JOIN
        users u ON o.customer_id = u.user_id
    JOIN
        restaurants r ON o.restaurant_id = r.restaurant_id
    WHERE
        o.order_status = 'ready_for_delivery' AND o.delivery_personnel_id IS NULL
    ORDER BY o.order_date ASC;
";
if ($stmt_available = $conn->prepare($sql_available_orders)) {
    if ($stmt_available->execute()) {
        $result_available = $stmt_available->get_result();
        while ($row = $result_available->fetch_assoc()) {
            $available_orders[] = $row;
        }
        $result_available->free();
    } else {
        $error_message .= " Error fetching available orders: " . $stmt_available->error;
    }
    $stmt_available->close();
} else {
    $error_message .= " Error preparing available orders query: " . $conn->error;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Dashboard - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* ======================================================= */
        /* === GLOBAL BODY RESET === */
        /* ======================================================= */
        body {
            font-family: 'Inter', sans-serif; /* Using Inter for a modern look */
            margin: 0;
            padding: 0;
            background-color: #f0f2f5; /* Light grey background */
            color: #333;
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .container {
            width: 95%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 0;
        }

        /* Header Styles */
        header {
            background: linear-gradient(to right, #333, #555); /* Dark gradient */
            color: #fff;
            padding: 15px 0;
            border-bottom: 5px solid #4CAF50; /* Green accent color for delivery */
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }

        header .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            margin: 0;
            font-size: 2.2em;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.3);
        }

        header nav ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 25px;
        }

        header nav ul li a {
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.05em;
            padding: 8px 12px;
            border-radius: 5px;
            transition: background-color 0.3s ease, color 0.3s ease, transform 0.2s ease;
            display: inline-block;
        }

        header nav ul li a:hover,
        header nav ul li a.active-nav {
            color: #4CAF50; /* Green accent */
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        /* Main Content Area */
        main {
            flex-grow: 1;
            padding: 20px 0;
        }

        /* ======================================================= */
        /* === DELIVERY DASHBOARD LAYOUT === */
        /* ======================================================= */
        .delivery-dashboard-wrapper {
            display: flex;
            gap: 30px; /* Space between sidebar and main content */
            padding: 30px;
            background-color: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            min-height: 600px;
        }

        /* Sidebar Styling (similar to account.php, but themed for delivery) */
        .sidebar {
            flex: 0 0 250px; /* Fixed width sidebar */
            background-color: #f8f9fa; /* Light background for sidebar */
            border-radius: 10px;
            padding: 25px 0;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.05);
        }

        .sidebar .profile-summary {
            text-align: center;
            padding-bottom: 25px;
            margin-bottom: 25px;
            border-bottom: 1px solid #eee;
        }

        .sidebar .profile-summary img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #4CAF50; /* Green accent border */
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .sidebar .profile-summary h3 {
            margin: 0;
            color: #333;
            font-size: 1.5em;
            font-weight: bold;
        }

        .sidebar .profile-summary p {
            margin: 5px 0 0;
            color: #777;
            font-size: 0.9em;
        }

        .sidebar nav ul {
            display: flex;
            flex-direction: column;
            gap: 5px;
            padding: 0 15px;
            float: none;
        }

        .sidebar nav ul li {
            width: 100%;
            display: block;
            margin: 0;
        }

        .sidebar nav ul li a {
            padding: 12px 15px;
            color: #555;
            text-decoration: none;
            font-weight: 500;
            border-radius: 8px;
            transition: background-color 0.3s ease, color 0.3s ease;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar nav ul li a:hover,
        .sidebar nav ul li a.active-sidebar-link {
            background-color: #e6ffe6; /* Light green on hover/active */
            color: #4CAF50; /* Green accent for active */
            font-weight: bold;
        }
        .sidebar nav ul li a i {
            font-size: 1.1em;
            width: 20px;
            text-align: center;
        }


        /* Main Content Area for Dashboard */
        .dashboard-content-panel {
            flex: 1; /* Takes remaining space */
            background-color: #fdfdfd;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
        }

        .dashboard-content-panel h2 {
            color: #333;
            font-size: 2.2em;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }

        /* Order Grids */
        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); /* Responsive grid */
            gap: 25px;
        }

        .order-card {
            background-color: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between; /* Pushes button to bottom */
        }

        .order-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }

        .order-card h3 {
            color: #4CAF50; /* Green heading for current, adjusted for available */
            font-size: 1.6em;
            margin-top: 0;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .order-card.available h3 {
            color: #FFC107; /* Yellow heading for available */
        }

        .order-card p {
            margin-bottom: 10px;
            font-size: 0.95em;
            color: #555;
        }

        .order-card p strong {
            color: #333;
        }

        .order-card .address-link {
            display: block;
            margin-top: 5px; /* Adjust margin for spacing */
            color: #007bff;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
            display: inline-flex; /* To align text and icon */
            align-items: center;
            gap: 5px; /* Space between text and icon */
        }

        .order-card .address-link:hover {
            color: #0056b3;
            text-decoration: underline;
        }

        .order-card .action-buttons {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap; /* Allow buttons to wrap */
        }

        .order-card .action-buttons button {
            flex-grow: 1; /* Allow buttons to grow and fill space */
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .order-card .action-buttons .btn-map {
            background-color: #4CAF50; /* Green for map */
            color: white;
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
        }
        .order-card .action-buttons .btn-map:hover {
            background-color: #45a049;
            transform: translateY(-2px);
        }

        .order-card .action-buttons .btn-complete {
            background-color: #2196F3; /* Blue for complete */
            color: white;
            box-shadow: 0 4px 8px rgba(33, 150, 243, 0.3);
        }
        .order-card .action-buttons .btn-complete:hover {
            background-color: #1976D2;
            transform: translateY(-2px);
        }

        .order-card .action-buttons .btn-accept {
            background-color: #FFC107; /* Yellow for accept */
            color: #333;
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
        }
        .order-card .action-buttons .btn-accept:hover {
            background-color: #e0a800;
            transform: translateY(-2px);
        }

        .order-card .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 5px;
            font-size: 0.85em;
            font-weight: bold;
            text-transform: capitalize;
            margin-top: 10px;
            color: white;
            background-color: #6c757d; /* Default grey */
        }
        .status-badge.assigned { background-color: #ffc107; color: #333; } /* Yellow */
        .status-badge.out_for_delivery { background-color: #007bff; } /* Blue */
        .status-badge.completed { background-color: #28a745; } /* Green */
        .status-badge.cancelled { background-color: #dc3545; } /* Red */
        .status-badge.ready_for_delivery { background-color: #28a745; } /* Green for available */


        .no-orders-message {
            text-align: center;
            font-size: 1.2em;
            color: #666;
            padding: 50px;
            border: 2px dashed #ddd;
            border-radius: 10px;
            margin-top: 30px;
        }

        /* Messages */
        .message, .error-message {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
            box-sizing: border-box;
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6fb;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .delivery-dashboard-wrapper {
                flex-direction: column;
                padding: 20px;
            }
            .sidebar {
                flex: 0 0 auto;
                width: 100%;
                padding: 15px 0;
            }
            .sidebar nav ul {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                gap: 10px;
                padding: 0 10px;
            }
            .sidebar nav ul li {
                width: auto;
            }
            .sidebar nav ul li a {
                padding: 10px 15px;
            }
            .dashboard-content-panel {
                padding: 20px;
            }
        }
        @media (max-width: 768px) {
            header .container {
                flex-direction: column;
                text-align: center;
            }
            header h1 {
                margin-bottom: 10px;
                font-size: 1.8em;
            }
            header nav ul {
                flex-direction: column;
                gap: 10px;
            }
            header nav ul li a {
                padding: 5px 10px;
            }
            .orders-grid {
                grid-template-columns: 1fr; /* Single column on very small screens */
            }
            .order-card .action-buttons button {
                width: 100%; /* Full width buttons on very small screens */
            }
        }
    </style>
</head>
<body>
    <main class="container">
        <div class="delivery-dashboard-wrapper">
            <!-- Left Sidebar -->
            <div class="sidebar">
                <div class="profile-summary">
                    <img src="https://placehold.co/100x100/eeeeee/aaaaaa?text=<?php echo substr($username, 0, 1); ?>" alt="User Avatar">
                    <h3><?php echo h($username); ?></h3>
                    <p>Delivery Personnel</p>
                </div>
                <nav>
                    <ul>
                        <li><a href="delivery_personnel_dashboard.php" class="sidebar-link active-sidebar-link"><i class="fas fa-home"></i> Dashboard</a></li>
                        <li><a href="delivery_history.php" class="sidebar-link"><i class="fas fa-history"></i> Delivery History</a></li>
                        <li><a href="earning.php" class="sidebar-link"><i class="fas fa-dollar-sign"></i> Earnings</a></li>
                        <li><a href="account.php" class="sidebar-link"><i class="fas fa-cog"></i> Account Settings</a></li>
                        <li><a href="account.php?section=help-center" class="sidebar-link"><i class="fas fa-question-circle"></i> Help</a></li>
                        <li><a href="logout.php" class="sidebar-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li> <!-- Added Logout link here -->
                    </ul>
                </nav>
            </div>

            <!-- Right Main Content Panel -->
            <div class="dashboard-content-panel">
                <?php if (!empty($success_message)): ?>
                    <div class="message success-message"><?php echo h($success_message); ?></div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="message error-message"><?php echo h($error_message); ?></div>
                <?php endif; ?>

                <h2>Available Orders</h2>
                <?php if (!empty($available_orders)): ?>
                    <div class="orders-grid">
                        <?php foreach ($available_orders as $order): ?>
                            <div class="order-card available">
                                <h3>Order #<?php echo h($order['order_id']); ?></h3>
                                <p><strong>Restaurant:</strong> <?php echo h($order['restaurant_name']); ?> (<?php echo h($order['restaurant_address']); ?>)</p>
                                <p><strong>Customer:</strong> <?php echo h($order['customer_username']); ?></p>
                                <p><strong>Phone:</strong> <?php echo h($order['customer_phone']); ?></p>
                                <p>
                                    <strong>Delivery Address:</strong>
                                    <!-- Using latitude and longitude if available, fallback to address -->
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?php
                                        if (!empty($order['latitude']) && !empty($order['longitude'])) {
                                            echo urlencode(h($order['latitude']) . ',' . h($order['longitude']));
                                        } else {
                                            echo urlencode(h($order['delivery_address']));
                                        }
                                    ?>" target="_blank" class="address-link">
                                        <?php echo h($order['delivery_address']); ?> <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </p>
                                <p><strong>Amount:</strong> ETB <?php echo number_format(h($order['total_amount']), 2); ?></p>
                                <p><strong>Order Date:</strong> <?php echo date("M d, Y h:i A", strtotime(h($order['order_date']))); ?></p>
                                <span class="status-badge ready_for_delivery">Ready for Delivery</span>

                                <div class="action-buttons">
                                    <form action="delivery_personnel_dashboard.php" method="POST" style="display:contents;"
                                        onsubmit="return confirm('Are you sure you want to accept Order #<?php echo h($order['order_id']); ?>?');">
                                        <input type="hidden" name="action" value="accept_order">
                                        <input type="hidden" name="order_id" value="<?php echo h($order['order_id']); ?>">
                                        <button type="submit" class="button btn-accept">
                                            <i class="fas fa-hand-paper"></i> Accept Order
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-orders-message">
                        <i class="fas fa-concierge-bell fa-3x" style="color: #ccc; margin-bottom: 20px;"></i>
                        <p>No new orders ready for delivery at the moment. Check back soon!</p>
                    </div>
                <?php endif; ?>

                <h2 style="margin-top: 40px;">Current Deliveries</h2>
                <?php if (!empty($current_deliveries)): ?>
                    <div class="orders-grid">
                        <?php foreach ($current_deliveries as $delivery): ?>
                            <div class="order-card">
                                <h3>Order #<?php echo h($delivery['order_id']); ?></h3>
                                <p><strong>Restaurant:</strong> <?php echo h($delivery['restaurant_name']); ?> (<?php echo h($delivery['restaurant_address']); ?>)</p>
                                <p><strong>Customer:</strong> <?php echo h($delivery['customer_username']); ?></p>
                                <p><strong>Phone:</strong> <?php echo h($delivery['customer_phone']); ?></p>
                                <p>
                                    <strong>Delivery Address:</strong>
                                    <!-- Using latitude and longitude if available, fallback to address -->
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?php
                                        if (!empty($delivery['latitude']) && !empty($delivery['longitude'])) {
                                            echo urlencode(h($delivery['latitude']) . ',' . h($delivery['longitude']));
                                        } else {
                                            echo urlencode(h($delivery['delivery_address']));
                                        }
                                    ?>" target="_blank" class="address-link">
                                        <?php echo h($delivery['delivery_address']); ?> <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </p>
                                <p><strong>Amount:</strong> ETB <?php echo number_format(h($delivery['total_amount']), 2); ?></p>
                                <p><strong>Order Date:</strong> <?php echo date("M d, Y h:i A", strtotime(h($delivery['order_date']))); ?></p>
                                <span class="status-badge <?php echo h($delivery['order_status']); ?>"><?php echo h(str_replace('_', ' ', $delivery['order_status'])); ?></span>

                                <div class="action-buttons">
                                    <?php if ($delivery['order_status'] == 'assigned'): ?>
                                        <form action="delivery_personnel_dashboard.php" method="POST" style="display:contents;"
                                            onsubmit="return confirm('Are you sure you want to mark Order #<?php echo h($delivery['order_id']); ?> as out for delivery?');">
                                            <input type="hidden" name="action" value="mark_out_for_delivery">
                                            <input type="hidden" name="order_id" value="<?php echo h($delivery['order_id']); ?>">
                                            <button type="submit" class="button btn-complete">
                                                <i class="fas fa-truck"></i> Mark Out for Delivery
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-orders-message">
                        <i class="fas fa-box-open fa-3x" style="color: #ccc; margin-bottom: 20px;"></i>
                        <p>No current deliveries assigned. Take a break!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
