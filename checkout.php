<?php
session_start();
require_once 'config.php'; // Ensure this path is correct for your database connection

// Check if the user is logged in AND if their role is 'customer'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "customer") {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION['user_id']; // This is the PHP variable holding the user's ID
$current_cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
$subtotal = 0;
foreach ($current_cart as $item) {
    $subtotal += ($item['quantity'] * $item['price']);
}
$delivery_fee = 20.00; // Fixed delivery fee
$total_amount = $subtotal + $delivery_fee;

$virtual_balance = 0;
$checkout_message = '';
$error_message = '';
$fund_accepted_message = ''; // New variable for fund accepted message

$latitude = ""; // Initialize latitude variable
$longitude = ""; // Initialize longitude variable

// Error variables for latitude and longitude
$latitude_err = "";
$longitude_err = "";
$amount_requested_err = ""; // New error variable for fund request amount
$proof_image_err = ""; // New error variable for fund request image

// Fetch user's current virtual balance
$sql_balance = "SELECT virtual_balance FROM users WHERE user_id = ?";
if ($stmt_balance = $conn->prepare($sql_balance)) {
    $stmt_balance->bind_param("i", $user_id);
    if ($stmt_balance->execute()) {
        $result_balance = $stmt_balance->get_result();
        if ($result_balance->num_rows == 1) {
            $virtual_balance = $result_balance->fetch_assoc()['virtual_balance'];
        }
    } else {
        $error_message .= " Error fetching balance: " . $stmt_balance->error;
    }
    $stmt_balance->close();
} else {
    $error_message .= " Error preparing balance query: " . $conn->error;
}

// --- Check for Approved Fund Requests ---
// This query will fetch any 'approved' fund requests for the current user that haven't been 'used' or acknowledged
$sql_check_approved_funds = "SELECT amount FROM fund_requests WHERE user_id = ? AND status = 'approved' ORDER BY request_date DESC LIMIT 1";
if ($stmt_approved_funds = $conn->prepare($sql_check_approved_funds)) {
    $stmt_approved_funds->bind_param("i", $user_id);
    if ($stmt_approved_funds->execute()) {
        $result_approved_funds = $stmt_approved_funds->get_result();
        if ($result_approved_funds->num_rows > 0) {
            $approved_fund_data = $result_approved_funds->fetch_assoc();
            $fund_accepted_amount = $approved_fund_data['amount'];
            $fund_accepted_message = "🎉 Great News! Your fund request for ETB " . number_format($fund_accepted_amount, 2) . " has been approved and added to your wallet!";
            // OPTIONAL: You might want to update the status of this fund request to 'acknowledged'
            // or delete it here so it doesn't show up repeatedly. This would require an additional SQL UPDATE query.
        }
    } else {
        $error_message .= " Error checking approved funds: " . $stmt_approved_funds->error;
    }
    $stmt_approved_funds->close();
} else {
    $error_message .= " Error preparing approved funds query: " . $conn->error;
}


// --- Handle Form Submissions ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action == 'pay_with_virtual_balance') {
            // Capture latitude and longitude for order submissions
            $latitude = trim($_POST['latitude'] ?? '');
            $longitude = trim($_POST['longitude'] ?? '');

            // Validate latitude and longitude (now mandatory or derived)
            if (empty($latitude)) {
                $latitude_err = "Latitude is required.";
            } elseif (!is_numeric($latitude) || floatval($latitude) < -90 || floatval($latitude) > 90) {
                $latitude_err = "Invalid latitude value (-90 to 90).";
            }

            if (empty($longitude)) {
                $longitude_err = "Longitude is required.";
            } elseif (!is_numeric($longitude) || floatval($longitude) < -180 || floatval($longitude) > 180) {
                $longitude_err = "Invalid longitude value (-180 to 180).";
            }

            // Convert to float for database insertion if validation passes
            $param_latitude = !empty($latitude_err) ? null : floatval($latitude);
            $param_longitude = !empty($longitude_err) ? null : floatval($longitude);


            // Proceed only if latitude and longitude are valid AND balance is sufficient
            if (empty($latitude_err) && empty($longitude_err)) { 
                if ($total_amount <= 0) {
                    $error_message = "Cannot process payment for a zero total amount.";
                } elseif ($total_amount > $virtual_balance) {
                    // This error should ideally be caught by the UI before submission,
                    // but it's a good server-side fallback.
                    $error_message = "Insufficient virtual balance. Please top up your wallet.";
                } else {
                    // Start a database transaction for atomicity
                    $conn->begin_transaction();

                    try {
                        // Deduct amount from virtual balance
                        $new_balance = $virtual_balance - $total_amount;
                        $sql_deduct = "UPDATE users SET virtual_balance = ? WHERE user_id = ?";
                        if ($stmt_deduct = $conn->prepare($sql_deduct)) {
                            $stmt_deduct->bind_param("di", $new_balance, $user_id);
                            if (!$stmt_deduct->execute()) {
                                throw new Exception("Error deducting balance: " . $stmt_deduct->error);
                            }
                            $stmt_deduct->close();
                        } else {
                            throw new Exception("Error preparing balance deduction query: " . $conn->error);
                        }

                        // Determine restaurant ID for the order
                        $restaurant_id_for_order = null;
                        if (!empty($current_cart)) {
                            $restaurant_id_for_order = $current_cart[0]['restaurant_id'] ?? null;
                        }
                        
                        // Insert the order into the orders table
                        // 'user_id' column name needs to be 'customer_id' to match your database
                        $order_status = 'pending'; 
                        $payment_method = 'virtual_wallet';
                        $delivery_address = ''; // Always empty now, as per instruction
                        $sql_insert_order = "INSERT INTO orders (customer_id, restaurant_id, total_amount, order_date, order_status, payment_method, delivery_address, latitude, longitude) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)";
                        if ($stmt_order = $conn->prepare($sql_insert_order)) {
                            // The PHP variable $user_id still holds the correct user ID
                            $stmt_order->bind_param("iidsisdd", $user_id, $restaurant_id_for_order, $total_amount, $order_status, $payment_method, $delivery_address, $param_latitude, $param_longitude);
                            if (!$stmt_order->execute()) {
                                throw new Exception("Error placing order: " . $stmt_order->error);
                            }
                            $order_id = $stmt_order->insert_id;
                            $stmt_order->close();
                        } else {
                            throw new Exception("Error preparing order insert query: " . $conn->error);
                        }

                        // Insert order items into order_items table
                        $sql_insert_order_item = "INSERT INTO order_items (order_id, item_id, quantity, price_at_order) VALUES (?, ?, ?, ?)";
                        if ($stmt_order_item = $conn->prepare($sql_insert_order_item)) {
                            foreach ($current_cart as $item) {
                                $stmt_order_item->bind_param("iiid", $order_id, $item['id'], $item['quantity'], $item['price']);
                                if (!$stmt_order_item->execute()) {
                                    throw new Exception("Error inserting order item: " . $stmt_order_item->error);
                                }
                            }
                            $stmt_order_item->close();
                        } else {
                            throw new Exception("Error preparing order item insert query: " . $conn->error);
                        }
                        
                        // Clear the cart after successful payment
                        $_SESSION['cart'] = [];
                        
                        $conn->commit(); // Commit the transaction
                        $checkout_message = "Payment successful! Your order has been placed virtually. New balance: ETB " . number_format($new_balance, 2);
                        $virtual_balance = $new_balance; // Update displayed balance immediately
                    } catch (Exception $e) {
                        $conn->rollback(); // Rollback on error
                        $error_message = "Payment failed: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action == 'request_fund') {
            // Handle fund request with image upload
            $amount_requested = filter_input(INPUT_POST, 'amount_requested', FILTER_VALIDATE_FLOAT);
            $screenshot_path = null;

            if ($amount_requested === false || $amount_requested <= 0) {
                $amount_requested_err = "Please enter a valid amount to request (must be a positive number).";
            } else {
                // Handle image upload
                if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] == UPLOAD_ERR_OK) {
                    $target_dir = "uploads/fund_proofs/";
                    // Create directory if it doesn't exist
                    if (!is_dir($target_dir)) {
                        mkdir($target_dir, 0777, true); // Permissions 0777 for simplicity, adjust for production
                    }

                    $file_name = uniqid('proof_') . '_' . basename($_FILES['proof_image']['name']);
                    $target_file = $target_dir . $file_name;
                    $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

                    // Basic validation for image type
                    $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
                    if (!in_array($imageFileType, $allowed_types)) {
                        $proof_image_err = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
                    } elseif ($_FILES['proof_image']['size'] > 5000000) { // 5MB limit
                        $proof_image_err = "Sorry, your file is too large (max 5MB).";
                    } else {
                        if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $target_file)) {
                            $screenshot_path = $target_file;
                        } else {
                            $proof_image_err = "Sorry, there was an error uploading your file.";
                        }
                    }
                } else {
                    $proof_image_err = "Proof image is required for fund requests.";
                }

                if (empty($amount_requested_err) && empty($proof_image_err)) {
                    // Insert a record into a 'fund_requests' table
                    $request_status = 'pending';
                    // Ensure your fund_requests table has a 'screenshot_path' column (VARCHAR/TEXT)
                    $sql_insert_request = "INSERT INTO fund_requests (user_id, amount, status, screenshot_path) VALUES (?, ?, ?, ?)";
                    if ($stmt_request = $conn->prepare($sql_insert_request)) {
                        $stmt_request->bind_param("idss", $user_id, $amount_requested, $request_status, $screenshot_path);
                        if ($stmt_request->execute()) {
                            $checkout_message = "Fund request for ETB " . number_format($amount_requested, 2) . " submitted successfully. Please wait for approval.";
                        } else {
                            $error_message = "Error submitting fund request: " . $stmt_request->error;
                        }
                        $stmt_request->close();
                    } else {
                        $error_message = "Error preparing fund request query: " . $conn->error;
                    }
                }
            }
        }
    }
}

// Ensure the connection is closed after all operations.
// This is typically done at the very end of the script.
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
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
        /* === CHECKOUT PAGE SPECIFIC STYLING === */
        /* ======================================================= */
        .checkout-content {
            padding: 40px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            justify-content: center;
        }

        .order-summary-card, .payment-options-card {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            flex: 1; /* Allow cards to grow */
            min-width: 300px; /* Minimum width for responsiveness */
        }

        .order-summary-card h2, .payment-options-card h2 {
            color: #ff6f61;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 25px;
            text-align: center;
            font-size: 2em;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 1.1em;
            color: #555;
        }
        .summary-total {
            border-top: 2px solid #ff6f61;
            padding-top: 15px;
            margin-top: 20px;
            font-size: 1.5em;
            font-weight: bold;
            color: #333;
            display: flex;
            justify-content: space-between;
        }

        .payment-option {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
            transition: box-shadow 0.3s ease;
        }
        .payment-option:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .payment-option h3 {
            color: #333;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.5em;
        }
        .payment-option p {
            font-size: 1.1em;
            color: #666;
            margin-bottom: 15px;
        }

        .payment-option .btn {
            background-color: #ff6f61;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
        }
        .payment-option .btn:hover {
            background-color: #e65c50;
        }

        .message {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
            width: 100%; /* Ensure messages span full width */
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
            width: 100%; /* Ensure messages span full width */
        }
        .fund-accepted-message {
            background-color: #e0ffe0; /* Lighter green */
            color: #28a745; /* Darker green for text */
            border: 1px solid #c3e6cb;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            text-align: center;
            width: 100%;
            font-size: 1.2em;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .fund-accepted-message i {
            font-size: 1.5em;
            color: #28a745;
        }
       
        /* Styling for the geolocation and file upload fields */
        .form-group {
            margin-bottom: 15px;
            text-align: left; /* Ensure labels and errors align left */
            width: 100%; /* Take full width of parent */
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        .form-group input[type="number"],
        .form-group input[type="file"] { /* Apply to file input as well */
            width: 100%; /* Make input fill the width */
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
            box-sizing: border-box; /* Include padding and border in the element's total width and height */
            background-color: #f8f8f8; /* Light background for inputs */
        }
        .form-group input[type="file"] {
            padding: 8px; /* Adjust padding for file input */
        }

        .form-group .btn-location {
            background-color: #6c757d; /* Grey for location button */
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            margin-top: 5px;
            transition: background-color 0.3s ease;
        }
        .form-group .btn-location:hover {
            background-color: #5a6268;
        }
        .error {
            color: red;
            font-size: 0.8em;
            margin-top: 5px;
            display: block;
            text-align: left;
        }

        /* New styles for fund request button */
        .fund-request-btn {
            background-color: #007bff; /* Blue for fund request */
            margin-top: 10px;
        }
        .fund-request-btn:hover {
            background-color: #0056b3;
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
        @media (max-width:780px){   }

        @media (max-width: 768px) {
            .checkout-content {
                flex-direction: column;
                gap: 20px;
            }
            .order-summary-card, .payment-options-card {
                width: 80%;
                min-width: unset;
            }
            .footer-inner{flex-direction:column;align-items:center; text-align: center;}
            .dark-footer .footer-inner{
                align-items: center;
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
                    <li><a href="index.php">Home</a></li>
                    <li><a href="restaurants.php">Restaurants</a></li>


                    <?php if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true): ?>
                        <li class="user-menu-container">
                            <i class="fa-solid fa-user user-icon"></i>
                            <div class="sidebar">
                                <a href="login.php">Login</a>
                                <a href="register.php">Register</a>
                            </div>
                        </li>
                    <?php else: ?>
                        <?php if (isset($_SESSION["role"])): ?>
                            <?php if ($_SESSION["role"] === "customer"): ?>
                                <li><a href="cart.php">Cart</a></li>
                                <li><a href="customer_dashboard.php">Account</a></li>
                                <li><a href="my_orders.php">Order History</a></li>
                            <?php elseif ($_SESSION["role"] === "restaurant_manager"): ?>
                                <li><a href="manage_menu.php">Manage Menu</a></li>
                                <li><a href="manage_orders.php">Orders</a></li>
                            <?php elseif ($_SESSION["role"] === "delivery_personnel"): ?>
                                <!-- Add specific links for delivery personnel here if any -->
                            <?php elseif ($_SESSION["role"] === "admin"): ?>
                                <li><a href="manage_users.php">Manage Users</a></li>
                                <li><a href="manage_restaurants.php">Manage Restaurants</a></li>
                                <li><a href="add_restaurant.php">Add Restaurant</a></li>
                                <li><a href="configure_payment_settings.php">Payment Settings</a></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <li><a href="logout.php">Logout</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <h1>Order Checkout</h1>

            <?php if (!empty($checkout_message)): ?>
                <div class="message"><?php echo $checkout_message; ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if (!empty($fund_accepted_message)): ?>
                <div class="fund-accepted-message">
                    <i class="fa-solid fa-circle-check"></i>
                    <?php echo $fund_accepted_message; ?>
                </div>
            <?php endif; ?>

            <div class="checkout-content">
                <div class="order-summary-card">
                    <h2>Order Summary</h2>
                    <?php if (empty($current_cart)): ?>
                        <p style="text-align: center;">Your cart is empty. Please add items before checking out.</p>
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="restaurants.php" class="btn">Go to Restaurants</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($current_cart as $item): ?>
                            <div class="summary-item">
                                <span><?php echo htmlspecialchars($item['name']); ?> (x<?php echo htmlspecialchars($item['quantity']); ?>)</span>
                                <span>ETB <?php echo number_format($item['quantity'] * $item['price'], 2); ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="summary-item">
                            <span>Subtotal:</span>
                            <span>ETB <?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        <div class="summary-item">
                            <span>Delivery Fee:</span>
                            <span>ETB <?php echo number_format(20.00, 2); ?></span>
                        </div>
                        <div class="summary-total">
                            <span>Total:</span>
                            <span>ETB <?php echo number_format($total_amount, 2); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="payment-options-card">
                    <h2>Payment Options</h2>
                    <div class="payment-option">
                        <h3>Virtual Wallet</h3>
                        <p>Your current virtual balance: <strong style="color: #28a745;">ETB <?php echo number_format($virtual_balance, 2); ?></strong></p>
                        <p>Amount to pay: <strong style="color: #ff6f61;">ETB <?php echo number_format($total_amount, 2); ?></strong></p>
                        
                        <?php if (!empty($current_cart)): // Only show payment forms if there are items in cart ?>
                            <!-- Form for Paying with Virtual Balance (includes location) -->
                            <form action="checkout.php" method="POST">
                                <div class="form-group">
                                    <label for="latitude">Latitude:</label>
                                    <input type="number" step="any" id="latitude" name="latitude" placeholder="e.g., 11.8518" value="<?php echo htmlspecialchars($latitude); ?>" required>
                                    <span class="error"><?php echo $latitude_err; ?></span>
                                </div>
                                <div class="form-group">
                                    <label for="longitude">Longitude:</label>
                                    <input type="number" step="any" id="longitude" name="longitude" placeholder="e.g., 38.0162" value="<?php echo htmlspecialchars($longitude); ?>" required>
                                    <span class="error"><?php echo $longitude_err; ?></span>
                                </div>
                                <button type="button" class="btn-location" onclick="getLocation()">Get My Current Location</button>
                                <p style="font-size: 0.9em; color: #777; margin-top: 10px;">(Please allow location access in your browser.)</p>

                                <?php if ($virtual_balance >= $total_amount): ?>
                                    <input type="hidden" name="action" value="pay_with_virtual_balance">
                                    <button type="submit" class="btn" style="margin-top: 20px;">Pay with Virtual Wallet</button>
                                <?php else: ?>
                                    <p style="color: red; font-weight: bold; margin-top: 20px;">Insufficient balance. Consider requesting funds.</p>
                                    <input type="hidden" name="action" value="pay_with_virtual_balance">
                                    <button type="submit" class="btn" style="margin-top: 20px;" disabled>Pay with Virtual Wallet</button>
                                <?php endif; ?>
                            </form>

                            <?php if ($virtual_balance < $total_amount): // Show fund request only if balance is insufficient for this order ?>
                                <!-- New Fund Request Form/Button (DOES NOT include location, DOES include image) -->
                                <form action="checkout.php" method="POST" enctype="multipart/form-data" style="margin-top: 25px;">
                                    <div class="form-group">
                                        <label for="amount_requested">Amount to Request (ETB):</label>
                                        <input type="number" step="0.01" id="amount_requested" name="amount_requested" placeholder="e.g., <?php echo number_format($total_amount, 2); ?>" value="<?php echo htmlspecialchars($total_amount > 0 ? $total_amount : ''); ?>" required min="0.01">
                                        <span class="error"><?php echo $amount_requested_err; ?></span>
                                    </div>
                                    <div class="form-group">
                                        <label for="proof_image">Upload Proof (e.g., Screenshot):</label>
                                        <input type="file" id="proof_image" name="proof_image" accept="image/*" required>
                                        <span class="error"><?php echo $proof_image_err; ?></span>
                                    </div>
                                    <input type="hidden" name="action" value="request_fund">
                                    <button type="submit" class="btn fund-request-btn">Request Fund</button>
                                </form>
                            <?php endif; ?>

                        <?php else: ?>
                            <p>Add items to your cart to proceed with payment.</p>
                        <?php endif; ?>
                    </div>
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

    <script>
        function getLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(showPosition, showError);
            } else {
                const errorMessageElement = document.querySelector('.error-message');
                if (errorMessageElement) {
                    errorMessageElement.textContent = "Geolocation is not supported by this browser. Please enter your coordinates manually.";
                    errorMessageElement.style.display = 'block';
                }
            }
        }

        function showPosition(position) {
            document.getElementById("latitude").value = position.coords.latitude;
            document.getElementById("longitude").value = position.coords.longitude;
            const checkoutMessageElement = document.querySelector('.message');
            if (checkoutMessageElement) {
                checkoutMessageElement.textContent = "Location captured: Latitude " + position.coords.latitude + ", Longitude " + position.coords.longitude;
                checkoutMessageElement.style.display = 'block';
                // Clear error message if location is successfully captured
                const errorMessageElement = document.querySelector('.error-message');
                if (errorMessageElement) {
                    errorMessageElement.textContent = '';
                    errorMessageElement.style.display = 'none';
                }
            }
        }

        function showError(error) {
            const errorMessageElement = document.querySelector('.error-message');
            let msg = "";
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    msg = "User denied the request for Geolocation. Please enter your location manually.";
                    break;
                case error.POSITION_UNAVAILABLE:
                    msg = "Location information is unavailable. Please enter your location manually.";
                    break;
                case error.TIMEOUT:
                    msg = "The request to get user location timed out. Please enter your location manually.";
                    break;
                case error.UNKNOWN_ERROR:
                    msg = "An unknown error occurred. Please enter your location manually.";
                    break;
                default:
                    msg = "An unexpected error occurred while getting your location. Please enter your coordinates manually.";
            }

            if (errorMessageElement) {
                errorMessageElement.textContent = msg;
                errorMessageElement.style.display = 'block';
            }
        }
    </script>
</body>
</html>
