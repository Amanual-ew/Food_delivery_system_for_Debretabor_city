<?php
session_start();
require_once 'config.php';

// Check if the user is logged in and is a delivery personnel
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "delivery_personnel") {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Delivery Personnel'; // Fallback username
$error_message = '';
$success_message = '';
$delivery_history = [];
$today_deliveries_count = 0;

// Function to safely output HTML special characters
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// --- Fetch Delivery History for the Delivery Personnel ---
// This query now fetches all orders assigned to the delivery personnel
// that have a status of 'completed' (instead of 'delivered').
$sql_delivery_history = "
    SELECT
        o.order_id,
        o.delivery_address,
        o.total_amount,
        o.order_status,
        o.order_date,
        u.username AS customer_username, /* Correctly aliased from users table */
        u.phone_number AS customer_phone
    FROM
        orders o
    JOIN
        users u ON o.customer_id = u.user_id
    WHERE
        o.delivery_personnel_id = ? AND o.order_status = 'completed'
    ORDER BY
        o.order_date DESC
";

if ($stmt_history = $conn->prepare($sql_delivery_history)) {
    $stmt_history->bind_param("i", $user_id);
    if ($stmt_history->execute()) {
        $result_history = $stmt_history->get_result();
        $today = date("Y-m-d"); // Get today's date in YYYY-MM-DD format
        while ($row = $result_history->fetch_assoc()) {
            $delivery_history[] = $row;
            // Check if the order date matches today's date
            if (date("Y-m-d", strtotime($row['order_date'])) === $today) {
                $today_deliveries_count++;
            }
        }
    } else {
        $error_message .= "Error fetching delivery history: " . $stmt_history->error;
    }
    $stmt_history->close();
} else {
    $error_message .= "Error preparing delivery history query: " . $conn->error;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery History - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Re-using styles from delivery_personnel_dashboard for consistency */
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f2f5;
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

        header {
            background: linear-gradient(to right, #333, #555);
            color: #fff;
            padding: 15px 0;
            border-bottom: 5px solid #4CAF50;
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
            color: #4CAF50;
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        main {
            flex-grow: 1;
            padding: 20px 0;
        }

        .delivery-dashboard-wrapper {
            display: flex;
            gap: 30px;
            padding: 30px;
            background-color: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            min-height: 600px;
        }

        .sidebar {
            flex: 0 0 250px;
            background-color: #f8f9fa;
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
            border: 3px solid #4CAF50;
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
            background-color: #e6ffe6;
            color: #4CAF50;
            font-weight: bold;
        }
        .sidebar nav ul li a i {
            font-size: 1.1em;
            width: 20px;
            text-align: center;
        }

        /* Main Content Area for Dashboard / History */
        .dashboard-content-panel {
            flex: 1;
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

        /* History specific styles */
        .history-summary {
            background-color: #e6f7ff; /* Light blue background */
            border: 1px solid #91d5ff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
            font-size: 1.1em;
            color: #0056b3;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .history-summary p {
            margin: 5px 0;
        }
        .history-summary strong {
            font-size: 1.3em;
            color: #003a70;
        }


        .history-table-container {
            overflow-x: auto; /* For responsive tables */
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden; /* Ensures rounded corners apply to table */
        }

        .history-table th, .history-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .history-table th {
            background-color: #4CAF50; /* Green header */
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9em;
        }

        .history-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .history-table tbody tr:hover {
            background-color: #f0f0f0;
            transform: scale(1.005);
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .history-table .status-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: capitalize;
            color: white;
            background-color: #6c757d; /* Default grey */
        }
        /* Keep status badge colors consistent with dashboard */
        .history-table .status-badge.delivered,
        .history-table .status-badge.completed { /* Added .completed */
            background-color: #28a745;
        } /* Green */
        .history-table .status-badge.assigned { background-color: #ffc107; color: #333; } /* Yellow */
        .history-table .status-badge.out_for_delivery { background-color: #007bff; } /* Blue */
        .history-table .status-badge.cancelled { background-color: #dc3545; } /* Red */

        .no-history-message {
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
            .history-table thead {
                display: none; /* Hide table headers on small screens */
            }
            .history-table, .history-table tbody, .history-table tr, .history-table td {
                display: block; /* Make table elements act as block elements */
                width: 97%;
            }
            .history-table tr {
                margin-bottom: 15px;
                border: 1px solid #eee;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .history-table td {
                text-align: right;
                padding-left: 10px;
                position: relative;
            }
            .history-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 30px);
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
                color: #555;
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
                        <li><a href="delivery_personnel_dashboard.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a></li>
                        <li><a href="delivery_history.php" class="sidebar-link active-sidebar-link"><i class="fas fa-history"></i> Delivery History</a></li>
                        <li><a href="earning.php" class="sidebar-link"><i class="fas fa-dollar-sign"></i> Earnings</a></li>
                        <li><a href="account.php" class="sidebar-link"><i class="fas fa-cog"></i> Account Settings</a></li>
                        <li><a href="account.php?section=help-center" class="sidebar-link"><i class="fas fa-question-circle"></i> Help</a></li>
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

                <h2>Delivery History</h2>

                <div class="history-summary">
                    <p>Total deliveries completed: <strong><?php echo count($delivery_history); ?></strong></p>
                    <p>Deliveries completed today (<?php echo date("M d, Y"); ?>): <strong><?php echo $today_deliveries_count; ?></strong></p>
                </div>

                <?php if (!empty($delivery_history)): ?>
                    <div class="history-table-container">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Delivery Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($delivery_history as $delivery): ?>
                                    <tr>
                                        <td data-label="Order ID">#<?php echo h($delivery['order_id']); ?></td>
                                        <td data-label="Customer"><?php echo h($delivery['customer_username']); ?></td>
                                        <td data-label="Amount">$<?php echo number_format(h($delivery['total_amount']), 2); ?></td>
                                        <td data-label="Status"><span class="status-badge <?php echo h($delivery['order_status']); ?>"><?php echo h(str_replace('_', ' ', $delivery['order_status'])); ?></span></td>
                                        <td data-label="Delivery Date"><?php echo date("M d, Y h:i A", strtotime(h($delivery['order_date']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-history-message">
                        <i class="fas fa-box fa-3x" style="color: #ccc; margin-bottom: 20px;"></i>
                        <p>No completed deliveries in your history yet.</p>
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
