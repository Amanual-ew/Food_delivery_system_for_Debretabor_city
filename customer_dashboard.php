<?php
session_start(); // Always start the session at the very beginning
require_once 'config.php'; // Include the database connection

// Check if the user is logged in AND if their role is 'customer'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "customer") {
    header("location: login.php"); // Line 7: Redirect to login if not a logged-in customer
    exit;
}


$user_id = $_SESSION["user_id"];
$customer_username = htmlspecialchars($_SESSION["username"]); // Already have username from session
$customer_email = ""; // To be fetched from DB
$virtual_balance = 0; // To be fetched from DB
$order_history = []; // To be fetched from DB

// 1. Fetch user's current email and virtual balance
$sql_user_info = "SELECT email, virtual_balance FROM users WHERE user_id = ?";
if ($stmt_user_info = $conn->prepare($sql_user_info)) {
    $stmt_user_info->bind_param("i", $user_id);
    if ($stmt_user_info->execute()) {
        $result_user_info = $stmt_user_info->get_result();
        if ($result_user_info->num_rows == 1) {
            $user_data = $result_user_info->fetch_assoc();
            $customer_email = htmlspecialchars($user_data['email']);
            $virtual_balance = $user_data['virtual_balance'];
        } else {
            // This case should ideally not happen if user_id is valid
            error_log("Error: User ID " . $user_id . " not found in DB or has no email/balance data.");
        }
    } else {
        error_log("Error fetching user info: " . $stmt_user_info->error);
    }
    $stmt_user_info->close();
} else {
    error_log("Error preparing user info query: " . $conn->error);
}

// 2. Fetch Order History for the customer
// Corrected: Using 'customer_id' and 'order_status' from your database schema
$sql_order_history = "SELECT order_id, order_date, total_amount, order_status FROM orders WHERE customer_id = ? ORDER BY order_date DESC LIMIT 10"; // Fetch last 10 orders
if ($stmt_orders = $conn->prepare($sql_order_history)) {
    $stmt_orders->bind_param("i", $user_id); // $user_id corresponds to customer_id here
    if ($stmt_orders->execute()) {
        $result_orders = $stmt_orders->get_result();
        while ($row_order = $result_orders->fetch_assoc()) {
            $order_history[] = $row_order;
        }
        $result_orders->free();
    } else {
        error_log("Error fetching order history: " . $stmt_orders->error);
    }
    $stmt_orders->close();
} else {
    error_log("Error preparing order history query: " . $conn->error);
}

$conn->close(); // Close the database connection
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - Debre Tabor Food Delivery</title>
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
        /* === HOVER SIDEBAR STYLING (For header user icon) === */
        /* ======================================================= */
        header {
            position: relative;
            z-index: 1000;
        }
        .user-menu-container {
            position: relative;
            display: inline-block;
        }
        .user-icon {
            font-size: 1.5em;
            color: #fff; /* White icon color */
            cursor: pointer;
            padding: 10px;
            transition: color 0.3s ease;
        }
        .user-icon:hover {
            color: #ff6f61; /* Hover color for contrast */
        }
        .sidebar {
            position: absolute;
            top: 100%;
            right: 0;
            width: 150px;
            background-color: #fff;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            border-radius: 5px;
            padding: 10px;
            z-index: 999;
            visibility: hidden;
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease;
        }
        .user-menu-container:hover .sidebar {
            visibility: visible;
            opacity: 1;
            transform: translateY(0);
        }
        .sidebar a {
            display: block;
            padding: 8px 12px;
            text-decoration: none;
            color: #333;
            transition: background-color 0.3s ease;
        }
        .sidebar a:hover {
            background-color: #f1f1f1;
        }

        /* ======================================================= */
        /* === CUSTOMER ACCOUNT PAGE STYLING === */
        /* ======================================================= */
        .account-overview-section {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            margin-top: 30px;
            justify-content: center;
        }

        .info-card, .action-card, .history-card {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            flex: 1; /* Allows cards to take equal space */
            min-width: 300px; /* Minimum width before wrapping */
        }

        .info-card h3, .action-card h3, .history-card h3 {
            color: #ff6f61;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 20px;
            font-size: 1.8em;
            text-align: center;
        }

        .account-detail {
            margin-bottom: 10px;
            font-size: 1.1em;
            color: #555;
        }
        .account-detail strong {
            color: #333;
        }
        .account-detail .balance {
            color: #28a745; /* Green for balance */
            font-size: 1.2em;
            font-weight: bold;
        }
        .action-card{
            align-items:center; 
            display: flex; 
            flex-direction: column;
        }

        /* Reusable button styles (used in actions and modal) */
        .btn {
            padding: 12px 20px;
            font-size: 1.1em;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s ease, transform 0.1s;
            cursor: pointer;
            border: none;
        }

        .action-card .btn {
            display: block;
            width: 80%;
            margin-bottom: 15px;
        }

        .btn-primary { background-color: #007bff; color: white; }
        .btn-primary:hover { background-color: #0056b3; transform: translateY(-1px); }
        .btn-secondary { background-color: #6c757d; color: white; }
        .btn-secondary:hover { background-color: #5a6268; transform: translateY(-1px); }

        .order-history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .order-history-table th, .order-history-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .order-history-table th {
            background-color: #f2f2f2;
            font-weight: bold;
            color: #555;
            font-size: 0.9em;
            text-transform: uppercase; 
        }
        .order-history-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .order-history-table tbody tr:hover {
            background-color: #f1f1f1;
        }
        /* Updated status classes to match common order states */
        .status-pending, .status-preparing, .status-out-for-delivery { 
            color: orange; 
            font-weight: bold; 
        }
        .status-delivered { 
            color: green; 
            font-weight: bold; 
        }
        .status-cancelled { 
            color: red; 
            font-weight: bold; 
        }
        /* --- NEW STYLES FOR CANCELLATION FEATURE --- */
        .btn-cancel {
            background-color: #dc3545; /* Red */
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9em;
            transition: background-color 0.2s, transform 0.1s;
            display: inline-block;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            font-weight: bold;
            line-height: 1;
            border: none; /* Ensure it looks like a button */
            cursor: pointer;
        }
        .btn-accept{
            background-color: #2917cfff; /* Red */
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9em;
            transition: background-color 0.2s, transform 0.1s;
            display: inline-block;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            font-weight: bold;
            line-height: 1;
            border: none; /* Ensure it looks like a button */
            cursor: pointer;
        }
        .btn-accept:hover {
            transform: translateY(-1px);
        }
        .btn-cancel:hover {
            background-color: #c82333;
            transform: translateY(-1px);
        }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            border: 1px solid transparent;
            font-size: 1.1em;
        }
        .success-message {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        .warning-message {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
        }
        
        /* ======================================================= */
        /* === CUSTOM CONFIRMATION MODAL STYLING === */
        /* ======================================================= */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        .modal-content {
            background: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            max-width: 400px;
            width: 90%;
            text-align: center;
            animation: fadeIn 0.3s ease-out;
        }
        .modal-content h3 {
            color: #dc3545;
            margin-top: 0;
            border-bottom: 2px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .modal-content p {
            margin-bottom: 20px;
            line-height: 1.4;
            color: #333;
        }
        .modal-content strong {
            color: #007bff;
        }
        .modal-actions {
            display: flex;
            justify-content: space-around;
            gap: 10px;
        }
        .modal-actions button {
            flex: 1;
            cursor: pointer;
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
        @media (max-width:780px){ .footer-inner{flex-direction:column;align-items:stretch;} }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        /* ======================================================= */


        @media (max-width: 768px) {
            .account-overview-section {
                flex-direction: column;
                gap: 20px;
            }
            .info-card, .action-card, .history-card {
                min-width: unset;
                width: 100%;
                padding: 20px; /* Reduced padding for mobile */
            }
            .order-history-table, .order-history-table tbody, .order-history-table tr, .order-history-table td {
                display: block;
                width: 95%;
            }
            .order-history-table thead {
                display: none;
            }
            .order-history-table tr {
                margin-bottom: 20px; /* Increased margin for better spacing */
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
                padding: 10px; /* Added padding inside rows */
            }
            .order-history-table td {
                text-align: right;
                padding: 12px 15px; /* Increased padding for better touch targets */
                position: relative;
                border: none;
                border-bottom: 1px dashed #eee;
                font-size: 1em; /* Slightly larger font for readability */
            }
            .order-history-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                top: 12px; /* Align with padding */
                width: calc(50% - 30px);
                white-space: nowrap;
                font-weight: bold;
                color: #555;
                font-size: 0.9em; /* Smaller label font */
            }
            /* Make buttons more touch-friendly */
            .btn-cancel, .btn-accept {
                padding: 12px 18px; /* Larger padding for touch */
                font-size: 1.1em; /* Larger font */
                min-width: 120px; /* Minimum width for buttons */
                margin: 5px 0; /* Spacing between buttons */
                display: block; /* Stack buttons vertically */
                width: 100%; /* Full width on mobile */
                box-sizing: border-box;
            }
            .order-history-table td:last-child {
                text-align: center; /* Center the action buttons */
                padding: 10px;
            }
            /* Improve modal on mobile */
            .modal-content {
                width: 100%; /* More width on mobile */
                padding: 20px;
            }
            .modal-actions {
                flex-direction: column; /* Stack modal buttons */
                gap: 10px;
            }
            .modal-actions button {
                width: 100%; /* Full width buttons in modal */
            }
        }

        /* Additional media query for phones (smaller screens) */
        @media (max-width: 480px) {
            body {
                font-size: 16px; /* Prevent zoom on iOS */
            }
            .container {
                padding: 10px 10px; /* Reduce container padding */
            }
            .info-card, .action-card, .history-card {
                width: 90%;
                padding: 15px; /* Further reduce padding */
            }
            .account-detail {
                font-size: 1.1em; /* Larger font for details */
            }
            .order-history-table tr {
                padding: 15px; /* More padding for rows */
            }
            .order-history-table td {
                padding: 10px; /* More padding for cells */
                font-size: 0.7em; /* Larger font */
            }
            .order-history-table td::before {
                font-size: 1em; /* Larger label font */
                top: 15px;
            }
            .btn-cancel, .btn-accept {
                padding: 15px 20px; /* Even larger padding */
                font-size: 1.2em; /* Larger font */
                min-height: 50px; /* Minimum height for touch */
            }
            .modal-content {
                width: 98%; /* Almost full width */
                padding: 15px;
            }
            .modal-content h3 {
                font-size: 1.5em; /* Larger modal title */
            }
            .modal-content p {
                font-size: 1.1em; /* Larger modal text */
            }
            /* Ensure header is responsive */
            header h1 {
                font-size: 1.8em;
            }
            header nav ul li a {
                font-size: 1.1em;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
           <h1><?php echo h(__('app_name')); ?></h1>
             <nav>
                <ul>
                    <li><a href="index.php" class="active-nav"><?php echo h(__('home')); ?></a></li>
                    <li><a href="restaurants.php"><?php echo h(__('restaurants')); ?></a></li>
                    
                    <!-- Authentication and Role-based Navigation -->
                    <?php if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true): ?>
                        <li class="user-menu-container">
                            <i class="fa-solid fa-user user-icon"></i>
                            <div class="sidebar">
                                <a href="login.php"><?php echo h(__('login')); ?></a>
                                <a href="register.php"><?php echo h(__('register')); ?></a>
                            </div>
                        </li>
                    <?php else: ?>
                        <?php if (isset($_SESSION["role"])): ?>
                            <?php if ($_SESSION["role"] === "customer"): ?>
                                <li><a href="cart.php"><?php echo h(__('cart')); ?></a></li>
                                <li><a href="my_orders.php"><?php echo h(__('my_orders')); ?></a></li>
                                <li><a href="customer_dashboard.php"><?php echo h(__('account')); ?></a></li>
                            <?php elseif ($_SESSION["role"] === "restaurant_manager"): ?>
                                <li><a href="manage_menu.php"><?php echo h(__('manage_menu')); ?></a></li>
                                <li><a href="manage_orders.php"><?php echo h(__('orders')); ?></a></li>
                            <?php elseif ($_SESSION["role"] === "delivery_personnel"): ?>
                                <li><a href="delivery_personnel_dashboard.php"><?php echo h(__('dashboard')); ?></a></li>
                                <li><a href="delivery_history.php"><?php echo h(__('history')); ?></a></li>
                                <li><a href="earnings.php"><?php echo h(__('earnings')); ?></a></li>
                                <li><a href="account.php"><?php echo h(__('account')); ?></a></li>
                            <?php elseif ($_SESSION["role"] === "admin"): ?>
                                <li><a href="manage_users.php"><?php echo h(__('manage_users')); ?></a></li>
                                <li><a href="manage_restaurants.php"><?php echo h(__('manage_restaurants')); ?></a></li>
                                <li><a href="add_restaurant.php"><?php echo h(__('add_restaurant')); ?></a></li>
                                <li><a href="configure_payment_settings.php"><?php echo h(__('payment_settings')); ?></a></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <li><a href="logout.php"><?php echo h(__('logout')); ?></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <h1>Welcome to Your Account, <?php echo $customer_username; ?>!</h1>
            <p>Here you can manage your account details, view your cart, and see your order history.</p>

            <!-- Message Handling for Cancellation Status -->
            <?php
            $message = "";
            if (isset($_GET['status'])) {
                if ($_GET['status'] === 'success' && isset($_GET['refund'])) {
                    // Formatting the refund amount for display
                    $refund_amount = number_format((float)$_GET['refund'], 2);
                    $message = "<div class='message success-message'>Order cancelled successfully! A 50% refund of **ETB " . $refund_amount . "** has been processed to your virtual balance.</div>";
                } elseif ($_GET['status'] === 'error' && isset($_GET['message'])) {
                    $error_text = htmlspecialchars($_GET['message']);
                    $message = "<div class='message error-message'>Cancellation failed: " . $error_text . "</div>";
                }
            } elseif (isset($_GET['message']) && $_GET['message'] === 'already_cancelled') {
                   $message = "<div class='message warning-message'>This order was already marked as cancelled or delivered and cannot be cancelled again.</div>";
            }
            echo $message;
            ?>
            <!-- End Message Handling -->

            <div class="account-overview-section">
                <!-- My Account Details Card -->
                <div class="info-card">
                    <h3>My Account Details</h3>
                    <div class="account-detail"><strong>Username:</strong> <?php echo $customer_username; ?></div>
                    <div class="account-detail"><strong>Email:</strong> <?php echo $customer_email; ?></div>
                    <div class="account-detail"><strong>Virtual Balance:</strong> <span class="balance">ETB <?php echo number_format($virtual_balance, 2); ?></span></div>
                    <!-- You can add more details here like phone number, address if stored in session/DB -->
                </div>

                <!-- Quick Actions / Cart Card -->
                <div class="action-card">
                    <h3>Quick Actions</h3>
                    <a href="cart.php" class="btn btn-primary"><i class="fas fa-shopping-cart"></i> View My Cart</a>
                    <a href="checkout.php" class="btn btn-secondary"><i class="fas fa-wallet"></i> Top Up Virtual Wallet</a>
                    <!-- Add other customer-specific actions here, e.g., "Edit Profile" -->
                </div>
            </div>

        </section>
    </main>

    <footer class="site-footer dark-footer" role="contentinfo" aria-label="Footer" style="background:#070707;color:#f3efe6;padding:48px 16px 28px;border-top:1px solid rgba(255,255,255,0.04);box-shadow:0 6px 18px rgba(0,0,0,0.6);">
        <style>
            
        </style>

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
    
    <!-- Custom Confirmation Modal (Added for the cancellation feature) -->
    <div id="cancelModal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <h3>Confirm Order Cancellation</h3>
            <p>
                WARNING: Are you sure you want to cancel order ID **<span id="modalOrderId"></span>**?
            </p>
            <p style="font-weight: bold;">
                As the customer, only a <strong style="color: #dc3545;">50% refund</strong> of the total amount, which is 
                <strong style="color: #28a745;">ETB <span id="modalRefundAmount"></span></strong>, will be issued to your virtual balance.
            </p>
            <div class="modal-actions">
                <button id="confirmCancelBtn" class="btn btn-cancel" type="button">Yes, Cancel Order</button>
                <button id="closeModalBtn" class="btn btn-secondary" type="button">No, Keep Order</button>
            </div>
        </div>
    </div>

    <script>
        // JavaScript for handling the custom modal
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('cancelModal');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const confirmCancelBtn = document.getElementById('confirmCancelBtn');
            const modalOrderId = document.getElementById('modalOrderId');
            const modalRefundAmount = document.getElementById('modalRefundAmount');
            const cancelButtons = document.querySelectorAll('.btn-cancel-trigger');

            // Function to open the modal
            cancelButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault(); // Prevent default link action (redirect)
                    
                    const orderId = this.getAttribute('data-order-id');
                    const refundAmount = this.getAttribute('data-refund-amount');
                    const cancelUrl = this.href;

                    // Update modal content
                    modalOrderId.textContent = `#${orderId}`;
                    modalRefundAmount.textContent = refundAmount;

                    // Set the confirmation action dynamically
                    confirmCancelBtn.onclick = () => {
                        // Redirect on confirmation
                        window.location.href = cancelUrl;
                    };

                    // Display the modal
                    modal.style.display = 'flex';
                });
            });

            // Function to close the modal
            closeModalBtn.addEventListener('click', function() {
                modal.style.display = 'none';
            });

            // Close modal if user clicks outside of it
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>