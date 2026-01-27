<?php
session_start();
require_once 'config.php';

// Check login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "customer") {
    header("location: login.php");
    exit;
}

// --- LANGUAGE LOGIC ---
$translations = [
    'en' => [
        'app_name' => 'Debre Tabor Food Delivery',
        'home' => 'Home',
        'login' => 'Login',
        'logout' => 'Logout',
        'my_orders' => 'My Orders',
        'logout' => 'Logout',
        'active_orders' => 'Active Orders',
        'past_orders' => 'Order History',
        'call_driver' => 'Call Driver',
        'cancel_warning' => 'Note: Only 50% will be refunded.',
        'mark_received_btn' => 'Confirm Delivery',
        'cancel_order_btn' => 'Cancel Order', 
        'order_not_placed' => 'No active orders. Hungry?',
        'order_now' => 'Order Now',
        'status_pending' => 'Pending',
        'status_processing' => 'Confirmed',
        'status_assigned' => 'Assigned',
        'status_preparing' => 'Cooking',
        'status_ready_for_delivery' => 'Ready',
        'status_out_for_delivery' => 'On the way',
        'status_completed' => 'Delivered',
        'status_cancelled' => 'Cancelled',
        'restaurants' => 'Restaurants',
        'my_account' => 'My Account',
    ],
    'am' => [
        'app_name' => 'ደብረ ታቦር የምግብ አቅርቦት',
        'home' => 'መነሻ ገጽ',
        'my_orders' => 'የእኔ ትዕዛዞች',
        'active_orders' => 'ያሉ ትዕዛዞች',
        'past_orders' => 'የቀድሞ ትዕዛዞች',
        'logout' => 'ውጣ',
        'call_driver' => 'ለሹፌሩ ይደውሉ',
        'cancel_warning' => 'ማሳሰቢያ፡ 50% ብቻ ተመላሽ ይደረጋል።',
        'mark_received_btn' => 'ተረክቤያለሁ',
        'cancel_order_btn' => 'ትዕዛዝ ሰርዝ', 
        'order_not_placed' => 'ምንም ትዕዛዝ የለም',
        'order_now' => 'አሁን ይዘዙ',
        'status_pending' => 'በመጠባበቅ ላይ',
        'status_processing' => 'ተረጋግጧል',
        'status_preparing' => 'እየተዘጋጀ ነው',
        'status_assigned' => 'ተመደበ',
        'status_ready_for_delivery' => 'ዝግጁ',
        'status_out_for_delivery' => 'መንገድ ላይ',
        'status_completed' => 'ተረክበዋል',
        'status_cancelled' => 'ተሰርዟል',
        'restaurants' => 'ምግብ ቤቶች',
        'my_account' => 'መለያዬ',
    ]
];

$lang = $_SESSION['lang'] ?? 'en';
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $translations)) $_SESSION['lang'] = $_GET['lang'];
function t($key) { global $translations, $lang; return $translations[$lang][$key] ?? $key; }
function h($string) { return htmlspecialchars($string, ENT_QUOTES, 'UTF-8'); }

$user_id = $_SESSION["user_id"];
$error_message = '';
$success_message = '';

// --- HANDLE POST REQUESTS (Refund & Status) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
    
    $order_id_to_update = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

    if ($order_id_to_update) {
        if ($_POST['action'] === 'mark_customer_completed') {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT order_status FROM orders WHERE order_id = ? AND customer_id = ? AND order_status = 'out_for_delivery'");
                $stmt->bind_param("ii", $order_id_to_update, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) throw new Exception("Action not allowed.");
                $stmt->close();

                $stmt = $conn->prepare("UPDATE orders SET order_status = 'completed', delivery_completion_date = NOW() WHERE order_id = ?");
                $stmt->bind_param("i", $order_id_to_update);
                $stmt->execute();
                $conn->commit();
                $success_message = "Order completed!";
            } catch (Exception $e) { $conn->rollback(); $error_message = $e->getMessage(); }
        } 
        elseif ($_POST['action'] === 'cancel_order') {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT order_status, total_amount FROM orders WHERE order_id = ? AND customer_id = ?");
                $stmt->bind_param("ii", $order_id_to_update, $user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows === 0) throw new Exception("Order not found.");
                $row = $res->fetch_assoc();
                
                if (in_array($row['order_status'], ['out_for_delivery', 'delivered', 'completed', 'cancelled'])) {
                    throw new Exception("Too late to cancel.");
                }
                
                $refund = $row['total_amount'] * 0.50;
                $conn->query("UPDATE users SET virtual_balance = virtual_balance + $refund WHERE user_id = $user_id");
                $conn->query("UPDATE orders SET order_status = 'cancelled' WHERE order_id = $order_id_to_update");
                $conn->commit();
                $success_message = "Order cancelled. Refunded: " . number_format($refund, 2);
            } catch (Exception $e) { $conn->rollback(); $error_message = $e->getMessage(); }
        }
    }
    $conn->close();
}

// --- FETCH DATA ---
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

$active_orders = [];
$past_orders = [];
$all_order_ids = [];

// 1. Fetch Orders
$sql = "
    SELECT
        o.order_id, o.delivery_address, o.total_amount, o.order_status, o.order_date,
        r.name AS restaurant_name,
        u_dp.username AS delivery_personnel_username, u_dp.phone_number AS delivery_personnel_phone
    FROM orders o
    JOIN restaurants r ON o.restaurant_id = r.restaurant_id
    LEFT JOIN users u_dp ON o.delivery_personnel_id = u_dp.user_id
    WHERE o.customer_id = ?
    ORDER BY o.order_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $all_order_ids[] = $row['order_id']; // Save ID for next step
    
    if (in_array($row['order_status'], ['pending', 'processing', 'preparing', 'assigned', 'ready_for_delivery', 'out_for_delivery'])) {
        $active_orders[] = $row;
    } else {
        $past_orders[] = $row;
    }
}
$stmt->close();

// 2. Fetch Items for these orders (Bulk Fetch)
$order_items_map = [];
if (!empty($all_order_ids)) {
    // Create a string like "1, 5, 8" for the SQL IN clause
    $ids_string = implode(',', array_map('intval', $all_order_ids));
    
    // JOIN order_items with menu_items to get the name
    $sql_items = "
        SELECT oi.order_id, oi.quantity, oi.price_at_order, m.name AS item_name
        FROM order_items oi
        JOIN menu_items m ON oi.item_id = m.item_id
        WHERE oi.order_id IN ($ids_string)
    ";
    
    $res_items = $conn->query($sql_items);
    if ($res_items) {
        while ($item = $res_items->fetch_assoc()) {
            // Group items by order_id: $order_items_map[123] = [item1, item2...]
            $order_items_map[$item['order_id']][] = $item;
        }
    }
}

$conn->close();

function getProgressPercent($status) {
    switch ($status) {
        case 'pending': return 10;
        case 'processing': return 30;
        case 'preparing': return 50;
        case 'ready_for_delivery': return 65;
        case 'assigned': return 80;
        case 'out_for_delivery': return 93;
        default: return 0;
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo h($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo t('my_orders'); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root { --primary: #ff6f61; --dark: #333; --light: #f4f4f4; --white: #fff; --green: #28a745; --red: #dc3545; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--light); color: var(--dark); margin:0; padding:0; -webkit-tap-highlight-color: transparent; }
        .container { width: 92%; max-width: 1200px; margin: 0 auto; }
        
        /* HEADER */
        header {color: black; padding: 15px 0;  top: 0; z-index: 1000; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        header .container { display: flex; justify-content: space-between; align-items: center; }
        .containerNav { display: flex; justify-content: space-between; align-items: center; padding: 10px; margin: 10px; }
        .menu-toggle { display: none; font-size: 1.5rem; cursor: pointer; }
        .nav-links ul { list-style: none; padding: 0; margin: 0; display: flex; align-items: flex-end; }
        .nav-links ul li { margin-left: 20px; }
        .nav-links ul li a { color: var(--dark); text-decoration: none; font-weight:bold; }
        
        /* CARD STYLES */
        .section-header { margin-top: 30px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
        .section-header h2 { font-size: 1.4rem; margin: 0; }
        .count-badge { background:blue; color: white; border-radius: 50%; padding: 2px 8px; font-size: 0.8rem; }

        .order-card { background: var(--white); border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #eee; }
        
        .card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
        .rest-info h3 { margin: 0 0 5px 0; font-size: 1.1rem; }
        .rest-info .date { font-size: 0.8rem; color: #888; }
        .price-tag { font-weight: bold; font-size: 1.1rem; color: var(--green); }

        /* ITEM LIST STYLE */
        .item-list { background: #fafafa; border-radius: 8px; padding: 10px; margin: 15px 0; border: 1px dashed #ddd; }
        .item-row { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 5px; color: #555; }
        .item-row:last-child { margin-bottom: 0; }
        .item-qty { font-weight: bold; color: var(--primary); margin-right: 8px; }

        /* PROGRESS BAR */
        .progress-container { margin: 20px 0; }
        .progress-bar-bg { background: #eee; height: 8px; border-radius: 4px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: var(--primary); border-radius: 4px; transition: width 0.5s ease; position: relative; }
        .progress-bar-fill::after { content: ''; position: absolute; top: 0; left: 0; bottom: 0; right: 0; background-image: linear-gradient(-45deg,rgba(255,255,255,.2) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.2) 50%,rgba(255,255,255,.2) 75%,transparent 75%,transparent); background-size: 50px 50px; animation: move 2s linear infinite; }
        @keyframes move { 0% { background-position: 0 0; } 100% { background-position: 50px 50px; } }
        .status-text { margin-top: 8px; font-size: 0.9rem; font-weight: 600; color: var(--primary); display: flex; align-items: center; gap: 8px; }

        .btn { border: none; padding: 12px; border-radius: 8px; font-weight: 600; cursor: pointer; display: block; width: 100%; text-decoration: none; font-size: 0.95rem; display: flex; justify-content: center; align-items: center; gap: 8px;}
        .btn-primary { background: var(--primary); color: white; }
        .btn-success { background: var(--green); color: white; }
        .btn-outline-red { border: 1px solid var(--red); color: var(--red); background: white; }
        .btn-call {width: auto; background: #333; color: white; margin-bottom: 10px; }
        
        .past-order { opacity: 0.85; }
        .past-order .status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 0.8rem; background: #eee; color: #555; }
        
        .alert { padding: 15px; border-radius: 8px; margin: 20px 0; text-align: center; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }

        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .nav-links { position: absolute; top: 100%; left: 0; width: 100%; background-color: var(--dark); overflow: hidden; max-height: 0; transition: max-height 0.3s ease; }
            .nav-links.active { max-height: 400px; border-top: 1px solid #444; }
            .nav-links ul { flex-direction: column; align-items: flex-start; padding: 10px 0; }
            .nav-links ul li { width: 100%; margin: 0; }
            .nav-links ul li a { display: block; padding: 15px 20px; border-bottom: 1px solid #444; color: white; }
        }
    </style>
</head>
<body>

<header>
    <div class="containerNav">
        <div class="logo"><h1><?php echo t('app_name'); ?></h1></div>
        <div class="menu-toggle" onclick="toggleMenu()"><i class="fas fa-bars"></i></div>
        <nav class="nav-links" id="navLinks">
            <ul>
                <li><a href="index.php"> <?php echo t('home'); ?></a></li>
                <li><a href="restaurants.php"> <?php echo t('restaurants'); ?></a></li>
                <li><a href="account.php"><?php echo t('my_account'); ?></a></li>
                <li><a href="my_orders.php" style="color: var(--primary);"> <?php echo t('my_orders'); ?></a></li>
                <li><a href="logout.php"></i> <?php echo t('logout'); ?></a></li>
            </ul>
        </nav>
    </div>
</header>

<div class="container">

    <?php if ($success_message): ?><div class="alert alert-success"><?php echo h($success_message); ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-error"><?php echo h($error_message); ?></div><?php endif; ?>

    <div class="section-header">
        <h2><?php echo t('active_orders'); ?></h2>
        <?php if(count($active_orders) > 0): ?><span class="count-badge"><?php echo count($active_orders); ?></span><?php endif; ?>
    </div>

    <?php if (empty($active_orders)): ?>
        <div style="text-align:center; padding:40px; background:white; border-radius:12px; border:1px solid #eee;">
            <p><?php echo t('order_not_placed'); ?></p>
            <a href="restaurants.php" class="btn btn-primary" style="max-width:200px; margin:10px auto;"><?php echo t('order_now'); ?></a>
        </div>
    <?php else: ?>
        <?php foreach ($active_orders as $order): ?>
            <div class="order-card">
                <div class="card-top">
                    <div class="rest-info">
                        <h3><?php echo h($order['restaurant_name']); ?></h3>
                        <span class="date">Order #<?php echo h($order['order_id']); ?></span>
                    </div>
                    <div class="price-tag">ETB <?php echo number_format($order['total_amount'], 0); ?></div>
                </div>

                <?php if (isset($order_items_map[$order['order_id']])): ?>
                    <div class="item-list">
                        <?php foreach ($order_items_map[$order['order_id']] as $item): ?>
                            <div class="item-row">
                                <span>
                                    <span class="item-qty"><?php echo h($item['quantity']); ?>x</span> 
                                    <?php echo h($item['item_name']); ?>
                                </span>
                                <span><?php echo number_format($item['price_at_order'] * $item['quantity'], 0); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="progress-container">
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?php echo getProgressPercent($order['order_status']); ?>%;"></div>
                    </div>
                    <div class="status-text">
                        <?php echo t('status_' . $order['order_status']); ?>
                        <?php if($order['order_status'] == 'out_for_delivery') echo ' <i class="fas fa-motorcycle"></i>'; ?>
                    </div>
                </div>

                <div class="action-area">
                    <?php if ($order['delivery_personnel_phone']): ?>
                        <a href="tel:<?php echo h($order['delivery_personnel_phone']); ?>" class="btn btn-call"><i class="fas fa-phone"></i> <?php echo t('call_driver'); ?></a>
                    <?php endif; ?>

                    <?php if ($order['order_status'] == 'out_for_delivery'): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="mark_customer_completed">
                            <input type="hidden" name="order_id" value="<?php echo h($order['order_id']); ?>">
                            <button class="btn btn-success"><?php echo t('mark_received_btn'); ?></button>
                        </form>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Refund is 50%. Proceed?');">
                            <input type="hidden" name="action" value="cancel_order">
                            <input type="hidden" name="order_id" value="<?php echo h($order['order_id']); ?>">
                            <button class="btn btn-outline-red"><?php echo t('cancel_order_btn'); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($past_orders)): ?>
        <div class="section-header"><h2><?php echo t('past_orders'); ?></h2></div>
        <?php foreach ($past_orders as $order): ?>
            <div class="order-card past-order">
                <div class="card-top">
                    <div class="rest-info">
                        <h3><?php echo h($order['restaurant_name']); ?></h3>
                        <span class="date"><?php echo date("M d", strtotime($order['order_date'])); ?></span>
                    </div>
                    <div style="text-align:right;">
                        <div class="price-tag" style="font-size:1rem; color:#666;">ETB <?php echo number_format($order['total_amount'], 0); ?></div>
                        <span class="status-badge status-<?php echo h($order['order_status']); ?>"><?php echo t('status_' . $order['order_status']); ?></span>
                    </div>
                </div>
                
                <?php if (isset($order_items_map[$order['order_id']])): ?>
                    <div class="item-list" style="background:#f9f9f9; font-size:0.85rem; padding:8px;">
                        <?php foreach ($order_items_map[$order['order_id']] as $item): ?>
                            <div class="item-row">
                                <span><?php echo h($item['quantity']); ?>x <?php echo h($item['item_name']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
    function toggleMenu() { document.getElementById('navLinks').classList.toggle('active'); }
</script>

</body>
</html>