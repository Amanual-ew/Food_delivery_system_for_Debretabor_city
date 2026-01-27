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

$delivery_fee_per_order = 20.00; // Define the fixed delivery fee per order

$total_earnings = 0;
$today_earnings = 0;
$this_week_earnings = 0;
$this_month_earnings = 0;

// Function to safely output HTML special characters
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// --- Fetch Earnings Summary ---

// Calculate Total Earnings
// Query to count total completed orders
$sql_total_deliveries = "
    SELECT
        COUNT(o.order_id) AS total_count
    FROM
        orders o
    WHERE
        o.delivery_personnel_id = ? AND o.order_status = 'completed'
";
if ($stmt_total = $conn->prepare($sql_total_deliveries)) {
    $stmt_total->bind_param("i", $user_id);
    if ($stmt_total->execute()) {
        $result_total = $stmt_total->get_result();
        if ($row = $result_total->fetch_assoc()) {
            $total_completed_orders = $row['total_count'] ?? 0;
            $total_earnings = $total_completed_orders * $delivery_fee_per_order;
        }
    } else {
        $error_message .= "Error fetching total completed orders: " . $stmt_total->error;
    }
    $stmt_total->close();
} else {
    $error_message .= "Error preparing total completed orders query: " . $conn->error;
}


// Calculate Today's Earnings
// Query to count today's completed orders
$today_date = date("Y-m-d");
$sql_today_deliveries = "
    SELECT
        COUNT(o.order_id) AS today_count
    FROM
        orders o
    WHERE
        o.delivery_personnel_id = ? AND o.order_status = 'completed' AND DATE(o.order_date) = ?
";
if ($stmt_today = $conn->prepare($sql_today_deliveries)) {
    $stmt_today->bind_param("is", $user_id, $today_date);
    if ($stmt_today->execute()) {
        $result_today = $stmt_today->get_result();
        if ($row = $result_today->fetch_assoc()) {
            $today_completed_orders = $row['today_count'] ?? 0;
            $today_earnings = $today_completed_orders * $delivery_fee_per_order;
        }
    } else {
        $error_message .= "Error fetching today's completed orders: " . $stmt_today->error;
    }
    $stmt_today->close();
} else {
    $error_message .= "Error preparing today's completed orders query: " . $conn->error;
}


// Calculate This Week's Earnings (Sunday to Saturday)
// Query to count this week's completed orders
$start_of_week = date('Y-m-d', strtotime('last sunday'));
$end_of_week = date('Y-m-d', strtotime('this saturday'));
$sql_week_deliveries = "
    SELECT
        COUNT(o.order_id) AS week_count
    FROM
        orders o
    WHERE
        o.delivery_personnel_id = ? AND o.order_status = 'completed' AND DATE(o.order_date) BETWEEN ? AND ?
";
if ($stmt_week = $conn->prepare($sql_week_deliveries)) {
    $stmt_week->bind_param("iss", $user_id, $start_of_week, $end_of_week);
    if ($stmt_week->execute()) {
        $result_week = $stmt_week->get_result();
        if ($row = $result_week->fetch_assoc()) {
            $week_completed_orders = $row['week_count'] ?? 0;
            $this_week_earnings = $week_completed_orders * $delivery_fee_per_order;
        }
    } else {
        $error_message .= "Error fetching this week's completed orders: " . $stmt_week->error;
    }
    $stmt_week->close();
} else {
    $error_message .= "Error preparing this week's completed orders query: " . $conn->error;
}


// Calculate This Month's Earnings
// Query to count this month's completed orders
$start_of_month = date('Y-m-01');
$end_of_month = date('Y-m-t'); // 't' gives the number of days in the given month
$sql_month_deliveries = "
    SELECT
        COUNT(o.order_id) AS month_count
    FROM
        orders o
    WHERE
        o.delivery_personnel_id = ? AND o.order_status = 'completed' AND DATE(o.order_date) BETWEEN ? AND ?
";
if ($stmt_month = $conn->prepare($sql_month_deliveries)) {
    $stmt_month->bind_param("iss", $user_id, $start_of_month, $end_of_month);
    if ($stmt_month->execute()) {
        $result_month = $stmt_month->get_result();
        if ($row = $result_month->fetch_assoc()) {
            $month_completed_orders = $row['month_count'] ?? 0;
            $this_month_earnings = $month_completed_orders * $delivery_fee_per_order;
        }
    } else {
        $error_message .= "Error fetching this month's completed orders: " . $stmt_month->error;
    }
    $stmt_month->close();
} else {
    $error_message .= "Error preparing this month's completed orders query: " . $conn->error;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earnings Summary - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Re-using styles from delivery_personnel_dashboard and delivery_history for consistency */
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

        /* Main Content Area for Dashboard / Earnings */
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

        /* Earnings specific styles */
        .earnings-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .earnings-card {
            background-color: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden; /* For pseudo-elements */
        }

        .earnings-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(to right, #4CAF50, #8bc34a); /* Green gradient top border */
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
        }

        .earnings-card.total-earnings::before {
            background: linear-gradient(to right, #007bff, #66b3ff); /* Blue gradient for total */
        }

        .earnings-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .earnings-card .icon {
            font-size: 2.5em;
            color: #4CAF50;
            margin-bottom: 15px;
        }

        .earnings-card.total-earnings .icon {
            color: #007bff;
        }

        .earnings-card h3 {
            font-size: 1.2em;
            color: #555;
            margin: 0 0 10px;
        }

        .earnings-card .amount {
            font-size: 2.5em;
            font-weight: bold;
            color: #333;
            margin: 0;
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
            .earnings-summary-grid {
                grid-template-columns: 1fr; /* Stack cards on smaller screens */
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
                        <li><a href="delivery_history.php" class="sidebar-link"><i class="fas fa-history"></i> Delivery History</a></li>
                        <li><a href="earnings.php" class="sidebar-link active-sidebar-link"><i class="fas fa-dollar-sign"></i> Earnings</a></li>
                        <li><a href="account.php?section=account-settings" class="sidebar-link"><i class="fas fa-cog"></i> Account Settings</a></li>
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

                <h2>Earnings Summary</h2>

                <div class="earnings-summary-grid">
                    <div class="earnings-card total-earnings">
                        <i class="fas fa-sack-dollar icon"></i>
                        <h3>Total Earnings</h3>
                        <p class="amount">$<?php echo number_format($total_earnings, 2); ?></p>
                    </div>
                    <div class="earnings-card">
                        <i class="fas fa-sun icon"></i>
                        <h3>Today's Earnings</h3>
                        <p class="amount">$<?php echo number_format($today_earnings, 2); ?></p>
                    </div>
                    <div class="earnings-card">
                        <i class="fas fa-calendar-week icon"></i>
                        <h3>This Week's Earnings</h3>
                        <p class="amount">$<?php echo number_format($this_week_earnings, 2); ?></p>
                    </div>
                    <div class="earnings-card">
                        <i class="fas fa-calendar-alt icon"></i>
                        <h3>This Month's Earnings</h3>
                        <p class="amount">$<?php echo number_format($this_month_earnings, 2); ?></p>
                    </div>
                </div>

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
