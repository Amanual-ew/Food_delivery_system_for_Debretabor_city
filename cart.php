<?php
session_start(); // Always start the session at the very beginning
require_once 'config.php'; // Include the database connection

// Initialize cart in session if it doesn't exist
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$message = ''; // For success/error messages

// --- Handle Add Item to Cart ---
// Now accepts item_id, restaurant_id, item_name, item_price
if (isset($_GET['action']) && $_GET['action'] == 'add' && 
    isset($_GET['item_id']) && isset($_GET['restaurant_id']) && 
    isset($_GET['item_name']) && isset($_GET['item_price'])) {
    
    // Check if user is logged in as a customer before adding to cart
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "customer") {
        header("location: login.php"); // Redirect to login if not authenticated as customer
        exit();
    }

    $item_id = intval($_GET['item_id']);
    $restaurant_id = intval($_GET['restaurant_id']);
    $item_name = htmlspecialchars($_GET['item_name']);
    $item_price = floatval($_GET['item_price']);

    $item_found = false;
    foreach ($_SESSION['cart'] as &$item) { // Use & for reference to modify the original array
        // Check if the item already exists in the cart (by item_id AND restaurant_id)
        if ($item['id'] === $item_id && $item['restaurant_id'] === $restaurant_id) {
            $item['quantity']++;
            $item_found = true;
            break;
        }
    }
    unset($item); // Break the reference

    if (!$item_found) {
        // Add the new item with its ID and restaurant ID
        $_SESSION['cart'][] = [
            'id' => $item_id,
            'restaurant_id' => $restaurant_id,
            'name' => $item_name,
            'price' => $item_price,
            'quantity' => 1
        ];
    }
    $message = "Added '" . $item_name . "' to cart.";
    // Redirect to clear query parameters after adding
    header("Location: cart.php");
    exit();
}

// --- Handle Remove Item from Cart ---
if (isset($_GET['action']) && $_GET['action'] == 'remove' && isset($_GET['index'])) {
    $index = intval($_GET['index']);
    if (isset($_SESSION['cart'][$index])) {
        array_splice($_SESSION['cart'], $index, 1); // Remove item at specific index
        $message = "Item removed from cart.";
    }
    header("Location: cart.php");
    exit();
}

// --- Handle Clear Cart ---
if (isset($_GET['action']) && $_GET['action'] == 'clear') {
    $_SESSION['cart'] = []; // Empty the cart array
    $message = "Cart cleared.";
    header("Location: cart.php");
    exit();
}


// Close the database connection if no further DB operations are needed here
if ($conn && $conn->ping()) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart - Debre Tabor Food Delivery</title>
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
        /* Specific styling for cart content */
        .cart-content {
            padding: 40px 0;
            text-align: center;
        }
        .cart-items {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            margin-top: 30px;
            text-align: left;
        }
        .cart-items h3 {
            color: #ff6f61;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px dashed #eee;
            padding: 15px 0;
        }
        .cart-item:last-child {
            border-bottom: none;
        }
        .cart-item-details {
            flex-grow: 1;
        }
        .cart-item-details h4 {
            margin: 0 0 5px 0;
            color: #333;
        }
        .cart-item-details p {
            margin: 0;
            color: #666;
            font-size: 0.9em;
        }
        .cart-item-price {
            font-weight: bold;
            color: #333;
            font-size: 1.1em;
        }
        .cart-summary {
            margin-top: 30px;
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            text-align: right;
        }
        .cart-summary p {
            font-size: 1.1em;
            font-weight: bold;
            margin: 10px 0;
        }
        .cart-summary .btn {
            margin-top: 20px;
            width: auto; /* Allow button to size to content */
            padding: 10px 25px;
            margin-left: 10px; /* Space from other buttons */
        }
        .btn-remove {
            background-color: #dc3545; /* Red color for remove button */
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8em;
            text-decoration: none; /* Remove underline for links */
            transition: background-color 0.3s ease;
        }
        .btn-remove:hover {
            background-color: #c82333;
        }
        .cart-actions {
            text-align: right;
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
        @media (max-width:780px){ .footer-inner{flex-direction:column;align-items:stretch;} }
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
        <section class="container cart-content">
            <h2><?php echo h(__('ur_shoping_cart'))?></h2>
            <p><?php echo h(__('review_ur')) ?></p>

            <?php if (!empty($message)): ?>
                <div class="message"><?php echo $message; ?></div>
            <?php endif; ?>

            <div class="cart-items">
                <h3><?php echo h(__('items_inthecart'))?></h3>
                <?php
                if (!empty($_SESSION['cart'])):
                    $subtotal = 0;
                    foreach ($_SESSION['cart'] as $index => $item):
                        $item_total = $item['quantity'] * $item['price'];
                        $subtotal += $item_total;
                        ?>
                        <div class="cart-item">
                            <div class="cart-item-details">
                                <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                <p><?php echo h(__('quantity')) ?><?php echo htmlspecialchars($item['quantity']); ?></p>
                                <p><?php echo h(__('price_per_item')) ?><?php echo number_format($item['price'], 2); ?></p>
                                <!-- Display restaurant_id and item_id for debugging (optional, remove in production) -->
                                <!-- <p>Restaurant ID: <?php echo htmlspecialchars($item['restaurant_id'] ?? 'N/A'); ?>, Item ID: <?php echo htmlspecialchars($item['id'] ?? 'N/A'); ?></p> -->
                            </div>
                            <div class="cart-item-price">
                                ETB <?php echo number_format($item_total, 2); ?>
                                <!-- Add a remove button for each item -->
                                <a href="cart.php?action=remove&index=<?php echo $index; ?>" class="btn-remove"><?php echo h(__('remove')) ?></a>
                            </div>
                        </div>
                    <?php endforeach;
                else: ?>
                    <p><?php echo h(__('your_cart_is_empty')) ?><a href="restaurants.php"><?php echo h(__('start_adding_some_food')) ?></a></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($_SESSION['cart'])): ?>
                <div class="cart-summary">
                    <p><?php echo h(__('subtotal')) ?><?php 
                        $subtotal = 0;
                        foreach ($_SESSION['cart'] as $item) {
                            $subtotal += ($item['quantity'] * $item['price']);
                        }
                        echo number_format($subtotal, 2);
                    ?></p>
                    <p><?php echo h(__('delivery_fee')) ?></p>
                    <p><?php echo h(__('total')) ?><?php echo number_format($subtotal + 20.00, 2); ?></p>
                    <div class="cart-actions">
                        <a href="cart.php?action=clear" class="btn btn-remove"><?php echo h(__('clear_cart')) ?></a>
                        <a href="checkout.php" class="btn" style="background-color: #ff6f61;"><?php echo h(__('proceed_to_checkout')) ?></a>
                    </div>
                </div>
            <?php endif; ?>
        </section>
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

    <script src="js/script.js"></script>
</body>
</html>
