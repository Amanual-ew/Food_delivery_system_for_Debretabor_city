<?php
session_start();
require_once 'config.php'; // Ensure this path is correct for your database connection

// Check if the user is logged in and is a restaurant manager
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "restaurant_manager") {
    header("location: login.php");
    exit;
}

$manager_user_id = $_SESSION['user_id'];
$restaurant_id = null;
$error_message = '';
$success_message = '';

// Data variables for the overview
$restaurant_name = 'N/A';
$pending_orders_count = 0;
$total_gross_sales_today = 0.00; // This will store the 100% sales amount
$restaurant_net_earnings_today = 0.00; // This will store the calculated 96%

// Define the service fee percentage
$service_fee_percentage = 0.04; // 4%

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

// Only attempt to fetch data if a restaurant_id was successfully found
if ($restaurant_id) {
    // 1. Fetch Restaurant Name
    $sql_get_restaurant_name = "SELECT name FROM restaurants WHERE restaurant_id = ?";
    if ($stmt = $conn->prepare($sql_get_restaurant_name)) {
        $stmt->bind_param("i", $restaurant_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $restaurant_name = $row['name'];
            }
        } else {
            $error_message .= " Error fetching restaurant name: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message .= " Error preparing restaurant name query: " . $conn->error;
    }

    // 2. Fetch Pending Orders Count
    $sql_pending_orders = "SELECT COUNT(order_id) AS pending_count FROM orders WHERE restaurant_id = ? AND order_status = 'pending'";
    if ($stmt = $conn->prepare($sql_pending_orders)) {
        $stmt->bind_param("i", $restaurant_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $pending_orders_count = $row['pending_count'];
            }
        } else {
            $error_message .= " Error fetching pending orders count: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message .= " Error preparing pending orders query: " . $conn->error;
    }

    // 3. Fetch Total Gross Sales Today (100% of delivered orders)
    // Using CURDATE() for date comparison, based on server's date
    $sql_gross_sales_today = "SELECT SUM(total_amount) AS gross_sales_sum FROM orders WHERE restaurant_id = ? AND order_status = 'completed' AND DATE(order_date) = CURDATE()";
    if ($stmt = $conn->prepare($sql_gross_sales_today)) {
        $stmt->bind_param("i", $restaurant_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $total_gross_sales_today = $row['gross_sales_sum'] ?? 0.00; // Use null coalescing
            }
        } else {
            $error_message .= " Error fetching gross sales today: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message .= " Error preparing gross sales today query: " . $conn->error;
    }

    // Calculate Restaurant's Net Earnings
    $restaurant_net_earnings_today = $total_gross_sales_today * (1 - $service_fee_percentage);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Manager Dashboard - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Basic Styling (You can integrate your comprehensive style.css later) */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            color: #333;
        }
       
        
        header h1 {
            margin: 0;
        }
        nav ul {
            padding: 0;
            list-style: none;
            text-align: left;
            float: right;
            margin-left: 0px;
        }
        nav ul li {
            display: inline;
            margin-right: 20px;
        }
        nav a {
            color: #100a0aff;
            text-decoration: none;
            font-weight: bold;
        }
        nav a:hover {
            color: #ff6f61;
        }
        main {
            padding: 20px 0;
        }
        .dashboard-section {
            background-color: #fff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .dashboard-section h2 {
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .dashboard-actions {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        .dashboard-actions .btn {
            padding: 15px 30px;
            font-size: 1.1em;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s ease;
            color: white; /* Default text color for buttons */
            text-align: center;
            min-width: 150px; /* Ensure buttons have a minimum width */
        }
        .btn-green {
            background-color: #28a745;
        }
        .btn-green:hover {
            background-color: #218838;
        }
        .btn-blue {
            background-color: #007bff;
        }
        .btn-blue:hover {
            background-color: #0056b3;
        }
        .btn-red {
            background-color: #dc3545;
        }
        .btn-red:hover {
            background-color: #c82333;
        }
        .overview-item {
            margin-bottom: 10px;
            font-size: 1.1em;
        }
        .overview-item strong {
            color: #555;
        }
        .error-message {
            color: #dc3545;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }
        .success-message {
            color: #28a745;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }
        footer {
            text-align: center;
            padding: 20px;
            background-color: #333;
            color: #fff;
            margin-top: 20px;
        }
        a{color:white;}
        .dark-footer .footer-inner { max-width:1200px;margin:0 auto;display:flex;flex-wrap:wrap;gap:28px;justify-content:space-between;align-items:flex-start; }
        .footer-brand { flex:1 1 260px; min-width:230px; display:flex; gap:16px; align-items:flex-start; }
        .footer-brand img.logo { width:72px;height:72px;object-fit:contain;border-radius:10px;box-shadow:0 6px 20px rgba(212,175,55,0.08); }
        .brand-text h3 { margin:0 0 6px;font-size:1.15rem;color:#ffefcf; letter-spacing:0.2px; }
        .brand-text p { margin:0;color:#d9d2c6;line-height:1.45;font-size:.95rem;}
        .footer-section { flex:0 1 200px; min-width:180px; }
        .footer-section h4 { margin:0 0 8px;color:#f7e8c8;font-size:.98rem; }
        .contact-list, .locations-list { list-style:none;margin:0;padding:0;color:#d6cfc3;line-height:2;font-size:.92rem; }
        .contact-list a, .locations-list a { color:inherit;text-decoration:none;opacity:0.95; }
        .partner-cta { display:inline-block;margin-top:8px;padding:8px 12px;background:linear-gradient(135deg,#c84b31,#d4af37);color:#070707;font-weight:700;border-radius:8px;text-decoration:none;box-shadow:0 6px 18px rgba(208,137,54,0.12); }
        .newsletter { flex:1 1 300px; min-width:260px; display: none; } /* Hide newsletter section */
        .socials { display:flex; gap:7px; margin-top:10px; margin-top: 60px;}
        .socials a {margin-right: 10px; margin-left: 20px; font-size: 20px; display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;background:rgba(0, 0, 0, 0);color:#f3efe6;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,0.45); }
        .footer-bottom { border-top:1px solid rgba(255,255,255,0.03); margin-top:24px;padding-top:18px;text-align:center;color:#bfb6a8;font-size:.88rem; }
        .socials a:hover { background:rgba(77, 37, 209, 1);color:#fff; transition: 0.4s; transform: scale(1.2);}
        .partner-cta:hover {scale: 1.05; box-shadow:0 8px 24px rgba(208,137,54,0.2); }
        @media (max-width:780px){.dashboard-actions{flex-direction: column;} .footer-inner{align-items: center;} .footer-inner{flex-direction:column;align-items:stretch;} }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Restaurant Manager Dashboard</h1>
            <nav>
                <ul>
                    <li><a href="restaurant_manager_dashboard.php">Dashboard</a></li>
                    <li><a href="manage_menu.php">Manage Menu</a></li>
                    <li><a href="manage_orders.php">Orders</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <div class="container">
            <div class="dashboard-section">
                <?php if (!empty($error_message)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                <?php if (!empty($success_message)): ?>
                    <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>

                <h2>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Manager'); ?>!</h2>
                <p>Manage your restaurant's menu and orders here.</p>

                <div class="dashboard-actions">
                    <a href="manage_menu.php" class="btn btn-green">Manage Menu</a>
                    <a href="manage_orders.php" class="btn btn-blue">View Orders</a>
                    <a href="logout.php" class="btn btn-red">Logout</a>
                </div>
            </div>
            
            <hr> <!-- Separator -->

            <div class="dashboard-section">
                <h2>Restaurant Overview</h2>
                <?php if ($restaurant_id === null): ?>
                    <p class="error-message">Error: This manager account is not linked to a restaurant. Please contact the administrator.</p>
                <?php else: ?>
                    <div class="overview-item"><strong>Your Restaurant:</strong> <?php echo htmlspecialchars($restaurant_name); ?></div>
                    <div class="overview-item"><strong>Pending Orders:</strong> <?php echo (int)$pending_orders_count; ?></div>
                    <div class="overview-item"><strong>Gross Sales Today (100%):</strong> ETB <?php echo number_format($total_gross_sales_today, 2); ?></div>
                    <div class="overview-item"><strong>Your Net Earnings Today (96%):</strong> ETB <?php echo number_format($restaurant_net_earnings_today, 2); ?></div>
                    <!-- New Messages is not implemented in this version, as it requires a messaging system -->
                <?php endif; ?>
            </div>
        </div>
    </main>

  <footer class="site-footer dark-footer" role="contentinfo" aria-label="Footer" style="background:#070707;color:#f3efe6;padding:48px 16px 28px;border-top:1px solid rgba(255,255,255,0.04);box-shadow:0 6px 18px rgba(0,0,0,0.6);">
        

        <div class="footer-inner">
            <!-- Brand / Logo -->
            <div class="footer-brand" aria-hidden="false">
                <img src="image/logo.png" alt="<?php echo h(__('app_name')); ?> logo" class="logo" onerror="this.style.display='none'">
                <div class="brand-text">
                    <h3><?php echo h(__('app_name')) ?: 'FoodDelivery'; ?></h3>
                    <p><?php echo h(__('footer_tagline')) ?: 'Fast, premium meals from the best local restaurants.'; ?></p>
                    <a href="restaurants.php" class="partner-cta" aria-label="Order now"><?php echo h(__('order_now')) ?: 'Order Now'; ?></a>
                </div>
            </div>

            <!-- Contact & Partner Signup -->
            <div class="footer-section" aria-label="Contact">
                <h4><?php echo h(__('contact')) ?: 'Contact'; ?></h4>
                <ul class="contact-list">
                    <li><strong><?php echo h(__('phone')) ?: 'Phone'; ?>:</strong> <a href="tel:+251911000000">+251 911 000 000</a></li>
                    <li><strong><?php echo h(__('email')) ?: 'Email'; ?>:</strong> <a href="mailto:info@fooddelivery.local">debretaborfooddelivery@gmail.com</a></li>
                    <li><strong><?php echo h(__('address')) ?: 'Address'; ?>:</strong> <?php echo h(__('head_office_address')) ?: 'Debre Tabor, Ethiopia'; ?></li>
                </ul>

                
            </div>
            <div>
                    <h4><?php echo h(__('If_u_want_to_be_our_customer_please_register')) ?: 'If you want to be our customer please register'; ?></h4>
                    <h4><?php echo h(__('Have_u_registered_before?,please_login')) ?: 'Have you registered before? Please login'; ?></h4>
                    <a href="register.php" class="partner-cta" aria-label="register"><?php echo h(__('register')) ?: 'Register'; ?></a>
                    <a href="login.php" class="partner-cta" aria-label="login"><?php echo h(__('login')) ?: 'Login'; ?></a>
             </div>

            <!-- Delivery Locations (derived from restaurant data) -->
            <!-- Socials -->
            <div class="socials" aria-label="Social media">
                <a href="#" aria-label="Facebook" title="Facebook"><i class="fa-brands fa-facebook" aria-hidden="true"></i></a>
                <a href="#" aria-label="Twitter" title="Twitter"><i class="fa-brands fa-twitter" aria-hidden="true"></i></a>
                <a href="#" aria-label="Instagram" title="Instagram"><i class="fa-brands fa-instagram" aria-hidden="true"></i></a>
                <a href="#" aria-label="YouTube" title="YouTube"><i class="fa-brands fa-youtube" aria-hidden="true"></i></a>
            </div>
        </div>
        

        <div class="footer-bottom" role="note">
            <small>
                &copy; <?php echo date('Y'); ?> <?php echo h(__('app_name')) ?: 'FoodDelivery'; ?>. <?php echo h(__('all_rights_reserved')) ?: 'All rights reserved.'; ?>
                &nbsp;&middot;&nbsp;
                <a href="privacy.php" style="color:inherit;text-decoration:underline;"><?php echo h(__('privacy_policy')) ?: 'Privacy Policy'; ?></a>
                &nbsp;&middot;&nbsp;
                <a href="terms.php" style="color:inherit;text-decoration:underline;"><?php echo h(__('terms_of_service')) ?: 'Terms of Service'; ?></a>
            </small>
        </div>
        
    </footer>
</body>
</html>
