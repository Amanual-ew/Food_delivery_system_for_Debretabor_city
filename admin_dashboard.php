<?php
session_start();
require_once 'config.php';

// Check if the user is logged in AND if their role is 'admin'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: login.php"); // Redirect to login if not an admin
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        /* ======================================================= */
        /* === ADMIN HEADER STYLING (Override global style.css) === */
        /* ======================================================= */
        header {
            position: relative; /* Ensure z-index works */
            z-index: 1000; /* Ensure header is on top of other content */
        }
        /* Hide the default navigation list for admin pages */
        header nav ul {
            display: none;
        }
        /* Center the main title for admin pages */
        header h1 {
            float: none; /* Remove float from global style.css */
            text-align: center; /* Center the title */
            width: 100%; /* Ensure it takes full width for centering */
            margin-bottom: 0; /* Adjust margin as needed */
        }
        /* Clearfix might still be needed if other elements float, but H1/NAV are now handled */
        header .container::after {
            content: "";
            display: table;
            clear: both;
        }

        /* ======================================================= */
        /* === QUICK ACTIONS BUTTONS STYLING === */
        /* ======================================================= */
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 30px;
            justify-content: center;
        }
        .quick-actions .btn {
            min-width: 180px; /* Give buttons a consistent minimum width */
            text-align: center;
            padding: 15px 20px;
            font-size: 1.1em;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none; /* Ensure links look like buttons */
            color: white; /* Default white text */
        }
        .quick-actions .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            background-color: rgba(22, 33, 88, 1);
            color: white;
        }

        /* Specific button colors for better visual distinction */
        .btn-users { background-color: #007bff; } /* Blue */
        .btn-restaurants { background-color: #28a745; } /* Green */
        .btn-add-restaurant { background-color: #17a2b8; } /* Cyan */
        .btn-payment-settings { background-color: #ffc107; color: #333; } /* Yellow */
        .btn-fund-requests { background-color: #6f42c1; } /* Purple */
        .btn-logout { background-color: #dc3545; } /* Red */
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Debre Tabor Food Delivery</h1>
            <nav>
                <!-- Admin navigation is custom for dashboard -->
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <h2>Admin Dashboard</h2>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION["username"]); ?>! Here you can manage various aspects of the food delivery system.</p>

            <div class="quick-actions">
                <a href="manage_users.php" class="btn btn-users">
                    <i class="fas fa-users"></i> Manage Users
                </a>
                <a href="manage_restaurants.php" class="btn btn-restaurants">
                    <i class="fas fa-utensils"></i> Manage Restaurants
                </a>
                <a href="staff_register.php" class="btn btn-staff-register">
                    <i class="fas fa-user-plus"></i> Register Staff
                </a>
                <a href="add_restaurant.php" class="btn btn-add-restaurant">
                    <i class="fas fa-plus-circle"></i> Add New Restaurant
                </a>
                <a href="manage_fund_requests.php" class="btn btn-fund-requests">
                    <i class="fas fa-money-check-alt"></i> Manage Fund Requests <!-- NEW LINK -->
                </a>
                <a href="configure_payment_settings.php" class="btn btn-payment-settings">
                    <i class="fas fa-cog"></i> Payment Settings
                </a>
                <a href="logout.php" class="btn btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>

            <!-- You can add more admin-specific content or statistics here -->
            <section style="margin-top: 50px; text-align: center;">
                <h3>System Overview (Placeholder)</h3>
                <p>This section can display key metrics like active orders, new registrations, etc.</p>
                <div style="background-color: #e9ecef; padding: 30px; border-radius: 8px; margin-top: 20px;">
                    
                    <?php
                    // Retrieve total users count
                    $total_users = 'N/A';
                    try {
                        if (isset($pdo) && $pdo instanceof PDO) {
                            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
                            $total_users = (int) $stmt->fetchColumn();
                        } elseif (isset($conn) && $conn instanceof mysqli) {
                            $res = $conn->query("SELECT COUNT(*) AS cnt FROM users");
                            $row = $res->fetch_assoc();
                            $total_users = (int) $row['cnt'];
                        } elseif (isset($mysqli) && $mysqli instanceof mysqli) {
                            $res = $mysqli->query("SELECT COUNT(*) AS cnt FROM users");
                            $row = $res->fetch_assoc();
                            $total_users = (int) $row['cnt'];
                        } elseif (isset($link)) {
                            $res = mysqli_query($link, "SELECT COUNT(*) AS cnt FROM users");
                            $row = mysqli_fetch_assoc($res);
                            $total_users = (int) $row['cnt'];
                        }
                    } catch (Exception $e) {
                        $total_users = 'N/A';
                    }

                    // Retrieve total active restaurants count
                    $active_restaurants = 'N/A';
                    try {
                        if (isset($pdo) && $pdo instanceof PDO) {
                            $stmt = $pdo->query("SELECT COUNT(*) FROM restaurants WHERE is_active = 1");
                            $active_restaurants = (int) $stmt->fetchColumn();
                        } elseif (isset($conn) && $conn instanceof mysqli) {
                            $res = $conn->query("SELECT COUNT(*) AS cnt FROM restaurants WHERE is_active = 1");
                            if ($res) {
                                $row = $res->fetch_assoc();
                                $active_restaurants = (int) $row['cnt'];
                            } else {
                                throw new Exception($conn->error); // Capture MySQL error
                            }
                        } elseif (isset($mysqli) && $mysqli instanceof mysqli) {
                            $res = $mysqli->query("SELECT COUNT(*) AS cnt FROM restaurants WHERE is_active = 1");
                            if ($res) {
                                $row = $res->fetch_assoc();
                                $active_restaurants = (int) $row['cnt'];
                            } else {
                                throw new Exception($mysqli->error); // Capture MySQL error
                            }
                        } elseif (isset($link)) {
                            $res = mysqli_query($link, "SELECT COUNT(*) AS cnt FROM restaurants WHERE is_active = 1");
                            if ($res) {
                                $row = mysqli_fetch_assoc($res);
                                $active_restaurants = (int) $row['cnt'];
                            } else {
                                throw new Exception(mysqli_error($link)); // Capture MySQL error
                            }
                        }
                    } catch (Exception $e) {
                        $active_restaurants = 'Error: ' . $e->getMessage(); // Display error message
                    }
                    ?>
                    <p>Total Registered Users: <strong><?php echo htmlspecialchars($total_users, ENT_QUOTES, 'UTF-8'); ?></strong></p>
                    <p>Active Restaurants: <strong><?php echo htmlspecialchars($active_restaurants, ENT_QUOTES, 'UTF-8'); ?></strong></p>
                </div>
            </section>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
