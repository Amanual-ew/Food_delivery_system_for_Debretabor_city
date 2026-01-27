<?php
session_start(); // Always start the session at the very beginning
require_once 'config.php'; // Include the database connection

$restaurant_id = null;
$restaurant_details = null;
$menu_items = [];
$error_message = "";
$success_message = "";

// --- Handle Review Submission (POST request) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "customer") {
        $error_message = "You must be logged in as a customer to submit a review.";
    } else {
        $user_id = $_SESSION['user_id'];
        $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
        $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
        $comment = trim($_POST['comment']);
        $comment = empty($comment) ? NULL : $comment; // Allow empty comments

        if ($item_id === false || $rating === false || $rating < 1 || $rating > 5) {
            $error_message = "Invalid review data. Please provide a valid item and rating (1-5 stars).";
        } else {
            // Check if user has already reviewed this item
            $sql_check_review = "SELECT review_id FROM reviews WHERE item_id = ? AND user_id = ?";
            if ($stmt_check = $conn->prepare($sql_check_review)) {
                $stmt_check->bind_param("ii", $item_id, $user_id);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $error_message = "You have already reviewed this item.";
                }
                $stmt_check->close();
            } else {
                $error_message = "Database error checking existing review: " . $conn->error;
            }

            if (empty($error_message)) {
                $conn->begin_transaction(); // Start transaction

                try {
                    // 1. Insert the new review
                    $sql_insert_review = "INSERT INTO reviews (item_id, user_id, rating, comment) VALUES (?, ?, ?, ?)";
                    if ($stmt_insert = $conn->prepare($sql_insert_review)) {
                        $stmt_insert->bind_param("iiis", $item_id, $user_id, $rating, $comment);
                        if (!$stmt_insert->execute()) {
                            throw new Exception("Error inserting review: " . $stmt_insert->error);
                        }
                        $stmt_insert->close();
                    } else {
                        throw new Exception("Error preparing review insert query: " . $conn->error);
                    }

                    // 2. Update menu_items table with new total_rating_sum and num_reviews
                    // This query directly updates based on the new review
                    $sql_update_item_rating = "UPDATE menu_items
                                               SET total_rating_sum = total_rating_sum + ?,
                                                   num_reviews = num_reviews + 1,
                                                   average_rating = (total_rating_sum + ?) / (num_reviews + 1)
                                               WHERE item_id = ?";
                    if ($stmt_update_item = $conn->prepare($sql_update_item_rating)) {
                        $stmt_update_item->bind_param("idi", $rating, $rating, $item_id);
                        if (!$stmt_update_item->execute()) {
                            throw new Exception("Error updating item average rating: " . $stmt_update_item->error);
                        }
                        $stmt_update_item->close();
                    } else {
                        throw new Exception("Error preparing item rating update query: " . $conn->error);
                    }

                    $conn->commit(); // Commit the transaction
                    $success_message = "Your review has been submitted successfully!";
                    // Redirect to clear POST data and prevent re-submission on refresh
                    // Ensure restaurant_id is passed back in the URL
                    header("Location: menu.php?restaurant_id=" . $_GET['restaurant_id'] . "&success=" . urlencode($success_message));
                    exit();

                } catch (Exception $e) {
                    $conn->rollback(); // Rollback on error
                    $error_message = "Failed to submit review: " . $e->getMessage();
                }
            }
        }
    }
}


// Check if restaurant_id is provided in the URL
if (isset($_GET['restaurant_id']) && !empty($_GET['restaurant_id'])) {
    $restaurant_id = (int)$_GET['restaurant_id'];

    // 1. Fetch Restaurant Details
    $sql_restaurant = "SELECT name, address, phone_number, email, cuisine_type, opening_time, closing_time
                       FROM restaurants
                       WHERE restaurant_id = ? AND is_active = 1";
    if ($stmt_rest = $conn->prepare($sql_restaurant)) {
        $stmt_rest->bind_param("i", $restaurant_id);
        if ($stmt_rest->execute()) {
            $result_rest = $stmt_rest->get_result();
            if ($result_rest->num_rows == 1) {
                $restaurant_details = $result_rest->fetch_assoc();
            } else {
                $error_message = "Restaurant not found or is inactive.";
            }
        } else {
            $error_message = "Error fetching restaurant details: " . $stmt_rest->error;
        }
        $stmt_rest->close();
    } else {
        $error_message = "Error preparing restaurant details query: " . $conn->error;
    }

    // 2. Fetch Menu Items if restaurant details were found
    if ($restaurant_details) {
        // Fetch menu items INCLUDING existing reviews for each item
        $sql_menu = "SELECT item_id, name, description, price, is_available, image_path, average_rating, total_rating_sum, num_reviews
                     FROM menu_items
                     WHERE restaurant_id = ? AND is_available = 1
                     ORDER BY name";
        if ($stmt_menu = $conn->prepare($sql_menu)) {
            $stmt_menu->bind_param("i", $restaurant_id);
            if ($stmt_menu->execute()) {
                $result_menu = $stmt_menu->get_result();
                while ($row_menu = $result_menu->fetch_assoc()) {
                    $item_id = $row_menu['item_id'];
                    $row_menu['reviews'] = []; // Initialize array for reviews of this item
                    $row_menu['has_reviewed'] = false; // Flag to check if current user reviewed this item

                    // Fetch reviews for this specific menu item
                    $sql_fetch_reviews = "SELECT r.rating, r.comment, r.review_date, u.username
                                          FROM reviews r
                                          JOIN users u ON r.user_id = u.user_id
                                          WHERE r.item_id = ? ORDER BY r.review_date DESC";
                    if ($stmt_reviews = $conn->prepare($sql_fetch_reviews)) {
                        $stmt_reviews->bind_param("i", $item_id);
                        $stmt_reviews->execute();
                        $result_reviews = $stmt_reviews->get_result();
                        while ($review_row = $result_reviews->fetch_assoc()) {
                            $row_menu['reviews'][] = $review_row;
                            // Check if the current logged-in user has reviewed this item
                            if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && $review_row['username'] === $_SESSION['username']) {
                                $row_menu['has_reviewed'] = true;
                            }
                        }
                        $result_reviews->free();
                        $stmt_reviews->close();
                    } else {
                        $error_message .= " Error fetching reviews for item " . $item_id . ": " . $conn->error;
                    }

                    $menu_items[] = $row_menu;
                }
                $result_menu->free();
            } else {
                $error_message .= " Error fetching menu items: " . $stmt_menu->error;
            }
            $stmt_menu->close();
        } else {
            $error_message .= " Error preparing menu items query: " . $conn->error;
        }
    }

} else {
    $error_message = "No restaurant ID provided. Please select a restaurant from the <a href='restaurants.php'>Restaurants page</a>.";
}

$conn->close(); // Close the database connection

// Check for messages passed via GET after a redirect
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}

/**
 * Helper function to generate star rating HTML.
 * @param float $rating The average rating (e.g., 4.5)
 * @param int $max_stars The maximum number of stars (e.g., 5)
 * @return string HTML for star icons
 */
function generateStars($rating, $max_stars = 5) {
    $html = '<div class="star-rating">';
    for ($i = 1; $i <= $max_stars; $i++) {
        if ($rating >= $i) {
            $html .= '<i class="fas fa-star full-star"></i>'; // Full star
        } elseif ($rating > ($i - 1) && $rating < $i) { // Check for half star
            $html .= '<i class="fas fa-star-half-alt half-star"></i>'; // Half star
        } else {
            $html .= '<i class="far fa-star empty-star"></i>'; // Empty star
        }
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $restaurant_details ? htmlspecialchars($restaurant_details['name']) . ' Menu' : 'Menu'; ?> - Debre Tabor Food Delivery</title>
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
        /* === MENU PAGE SPECIFIC STYLING === */
        /* ======================================================= */
        .restaurant-header-info {
            background-color: #fff;
            padding: 30px;
            margin-top: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .restaurant-header-info h2 {
            color: #ff6f61;
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        .restaurant-header-info p {
            font-size: 1.1em;
            color: #555;
            margin-bottom: 5px;
        }
        .restaurant-header-info .cuisine {
            font-style: italic;
            font-weight: bold;
            margin-top: 10px;
        }

        .menu-category {
            margin-top: 40px;
        }
        .menu-category h3 {
            font-size: 2em;
            color: #333;
            border-bottom: 2px solid #ff6f61;
            padding-bottom: 10px;
            margin-bottom: 25px;
            text-align: center;
        }

        /* REINSTATED: CSS Grid for 3-column layout as per original intention */
        .menu-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); /* Responsive 3-column max */
            gap: 25px; /* Space between menu items */
            margin-top: 30px;
        }

        .menu-item {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            /* Keep flex for content inside the card, stacking vertically */
            display: flex;
            flex-direction: column;
            align-items: center; /* Center items horizontally */
            text-align: center; /* Center text within each card */
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .menu-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }

        .menu-item-image {
            width: 100%; /* Make image take full width of card */
            max-width: 200px; /* Limit max size */
            height: 150px; /* Fixed height for consistency */
            object-fit: cover;
            border-radius: 8px; /* Rounded corners for images */
            border: 1px solid #eee; /* Subtle border */
            margin-bottom: 15px; /* Space below image */
        }

        .menu-item-details {
            flex-grow: 1; /* Allow details section to grow */
            width: 100%; /* Ensure it takes full width within the card */
            text-align: center;
        }
        .menu-item-details h4 {
            margin-top: 0;
            margin-bottom: 5px;
            font-size: 1.5em;
            color: #333;
        }
        .menu-item-details p {
            margin-bottom: 10px;
            color: #666;
            font-size: 0.95em;
        }

        .menu-item-price-add {
            width: 100%; /* Ensure price and button take full width */
            margin-top: 15px;
            display: flex;
            flex-direction: column;
            align-items: center; /* Center price and button */
        }
        .menu-item-price {
            font-size: 1.4em;
            font-weight: bold;
            color: #ff6f61;
            margin-bottom: 10px;
        }
        .add-to-cart-btn {
            background-color: #28a745; /* Green color for Add to Cart */
            color: white;
            padding: 10px 20px; /* Slightly larger padding */
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            text-decoration: none; /* For link styling */
            transition: background-color 0.3s ease;
            width: 80%; /* Make button wider */
            max-width: 200px; /* Limit max width */
        }
        .add-to-cart-btn:hover {
            background-color: #218838;
        }

        .no-items-message {
            text-align: center;
            margin-top: 30px;
            font-size: 1.2em;
            color: #666;
        }

        /* --- Rating Stars Styling --- */
        .star-rating {
            display: block; /* Make stars take full width below title */
            margin: 5px auto 10px auto; /* Center stars and add margin */
            font-size: 1.1em; /* Adjust star size */
            text-align: center;
        }
        .star-rating i {
            color: #FFD700; /* Gold color for stars */
            margin: 0 1px; /* Closer spacing for stars */
        }
        .star-rating .empty-star {
            color: #ccc; /* Lighter color for empty stars */
        }

        /* --- Reviews Section Styling --- */
        .reviews-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px dashed #eee;
            text-align: left; /* Align review content left */
            width: 100%; /* Take full width of parent .menu-item */
        }
        .reviews-section h5 {
            font-size: 1.2em;
            color: #ff6f61;
            margin-bottom: 15px;
            text-align: center;
        }
        .review-item {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
            font-size: 0.95em;
        }
        .review-header .reviewer-info {
            font-weight: bold;
            color: #333;
        }
        .review-header .review-date {
            color: #888;
            font-size: 0.85em;
        }
        .review-comment {
            color: #555;
            font-size: 0.9em;
            margin-top: 5px;
        }
        .no-reviews-message {
            text-align: center;
            color: #777;
            font-style: italic;
        }

        /* --- Review Form Styling --- */
        .review-form {
            background-color: #f0f8ff; /* Light blue background for review form */
            border: 1px solid #cceeff;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            text-align: center;
        }
        .review-form h6 {
            font-size: 1.1em;
            color: #333;
            margin-bottom: 15px;
        }
        .review-form .star-rating-input {
            margin-bottom: 15px;
            direction: rtl; /* For right-to-left star selection */
            display: inline-block; /* Keep stars on one line */
        }
        .review-form .star-rating-input input[type="radio"] {
            display: none; /* Hide default radio buttons */
        }
        .review-form .star-rating-input label {
            font-size: 1.8em; /* Large stars for input */
            color: #ccc; /* Default color */
            cursor: pointer;
            margin: 0 2px;
            display: inline-block;
            transition: color 0.2s ease;
        }
        .review-form .star-rating-input label:hover,
        .review-form .star-rating-input label:hover ~ label,
        .review-form .star-rating-input input[type="radio"]:checked ~ label {
            color: #FFD700; /* Gold on hover and checked */
        }
        .review-form textarea {
            width: calc(100% - 20px);
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
            min-height: 60px;
            font-size: 1em;
        }
        .review-form button[type="submit"] {
            background-color: #007bff; /* Blue for submit review */
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s ease;
        }
        .review-form button[type="submit"]:hover {
            background-color: #0056b3;
        }
        .review-already-submitted {
            background-color: #fff3cd; /* Light yellow background */
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: center;
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
            width: 100%;
            box-sizing: border-box; /* Include padding in width */
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Responsive adjustments for menu items */
        @media (max-width: 768px) {
            .menu-item {
                /* When stacked, center image and text */
                align-items: center;
                text-align: center;
            }
            .menu-item-details {
                text-align: center; /* Ensure text is centered when stacked */
            }
            .menu-item-price-add {
                align-items: center; /* Center buttons/price */
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
                    <!-- These navigation links are NOW ALWAYS VISIBLE -->
                    <li><a href="index.php">Home</a></li>
                    <li><a href="restaurants.php">Restaurants</a></li>
                

                    <?php
                    // This block conditionally displays the user icon/login/register OR role-specific links/logout
                    if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true):
                    ?>
                        <?php
                        // Further conditional links based on the user's role
                        if (isset($_SESSION["role"])):
                        ?>
                            <?php if ($_SESSION["role"] === "customer"): ?>
                                <li><a href="cart.php">Cart</a></li>
                                <li><a href="customer_dashboard.php">Account</a></li> <!-- The new Account link -->
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
                    <?php else: // If the user is NOT logged in ?>
                        <!-- Only the user icon with dropdown for login/register is shown -->
                        <li class="user-menu-container">
                            <i class="fa-solid fa-user user-icon"></i>
                            <div class="sidebar">
                                <a href="login.php">Login</a>
                                <a href="register.php">Register</a>
                            </div>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <?php if (!empty($error_message)): ?>
                <div class="error-message" style="text-align: center; margin-top: 30px;"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if (!empty($success_message)): ?>
                <div class="message" style="text-align: center; margin-top: 30px;"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <?php if ($restaurant_details): ?>
                <div class="restaurant-header-info">
                    <h2><?php echo htmlspecialchars($restaurant_details['name']); ?></h2>
                    <p class="cuisine"><?php echo htmlspecialchars($restaurant_details['cuisine_type']); ?></p>
                    <p>Address: <?php echo htmlspecialchars($restaurant_details['address']); ?></p>
                    <p>Phone: <?php echo htmlspecialchars($restaurant_details['phone_number']); ?></p>
                    <p>Hours: <?php echo htmlspecialchars(substr($restaurant_details['opening_time'], 0, 5)) . ' - ' . htmlspecialchars(substr($restaurant_details['closing_time'], 0, 5)); ?></p>
                </div>

                <div class="menu-category">
                    <h3>Menu Items</h3>
                    <div class="menu-items-grid"> <!-- Changed to grid container -->
                        <?php if (!empty($menu_items)): ?>
                            <?php foreach ($menu_items as $item): ?>
                                <div class="menu-item">
                                    <?php
                                    // The 'image_path' from the database already contains 'uploads/menu_items/filename.webp'.
                                    // We just need to ensure forward slashes for both file_exists and HTML src.
                                    $cleaned_image_path_from_db = str_replace('\\', '/', $item['image_path']);

                                    // Build the absolute file system path for file_exists()
                                    // __DIR__ is the directory of the current script (menu.php)
                                    // So, __DIR__ . '/' . $cleaned_image_path_from_db is the correct path.
                                    $absolute_file_path = __DIR__ . '/' . $cleaned_image_path_from_db;

                                    // Default placeholder image
                                    $item_image_src = 'https://placehold.co/120x120/cccccc/333333?text=No+Img';

                                    // Check if the image_path is not empty and the file actually exists on the server
                                    if (!empty($item['image_path']) && file_exists($absolute_file_path)) {
                                        // If file exists, use its web-accessible URL directly from the cleaned path
                                        $item_image_src = htmlspecialchars($cleaned_image_path_from_db);
                                    }
                                    ?>
                                    <img src="<?php echo $item_image_src; ?>"
                                         alt="<?php echo htmlspecialchars($item['name']); ?>" class="menu-item-image">
                                    <div class="menu-item-details">
                                        <h4>
                                            <?php echo htmlspecialchars($item['name']); ?>
                                            <?php
                                            // Display stars only if rating is greater than 0
                                            if ($item['average_rating'] > 0) {
                                                echo generateStars(floatval($item['average_rating']));
                                                echo ' <span style="font-size:0.9em; color:#777;">(' . number_format($item['average_rating'], 2) . ' / ' . htmlspecialchars($item['num_reviews']) . ' reviews)</span>';
                                            } else {
                                                echo ' <span style="font-size:0.9em; color:#777;">(No reviews yet)</span>';
                                            }
                                            ?>
                                        </h4>
                                        <p><?php echo htmlspecialchars($item['description']); ?></p>
                                    </div>
                                    <div class="menu-item-price-add">
                                        <div class="menu-item-price">ETB <?php echo number_format($item['price'], 2); ?></div>
                                        <!-- Add to Cart button - Now with login check and passes item_id and restaurant_id -->
                                        <a href="#" class="add-to-cart-btn"
                                           onclick="return checkLoginAndAddToCart(
                                               <?php echo htmlspecialchars($item['item_id']); ?>,
                                               <?php echo htmlspecialchars($restaurant_id); ?>,
                                               '<?php echo urlencode($item['name']); ?>',
                                               '<?php echo urlencode($item['price']); ?>',
                                               event
                                           );">
                                            Add to Cart
                                        </a>
                                    </div>

                                    <!-- Reviews Section for each Menu Item -->
                                    <div class="reviews-section">
                                        <h5>Customer Reviews</h5>
                                        <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && $_SESSION["role"] === "customer"): ?>
                                            <?php if ($item['has_reviewed']): ?>
                                                <div class="review-already-submitted">
                                                    You have already reviewed this item.
                                                </div>
                                            <?php else: ?>
                                                <div class="review-form">
                                                    <h6>Submit Your Review</h6>
                                                    <form action="menu.php?restaurant_id=<?php echo htmlspecialchars($restaurant_id); ?>" method="POST">
                                                        <input type="hidden" name="action" value="submit_review">
                                                        <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($item['item_id']); ?>">

                                                        <div class="star-rating-input">
                                                            <input type="radio" id="star5-<?php echo htmlspecialchars($item['item_id']); ?>" name="rating" value="5" required><label for="star5-<?php echo htmlspecialchars($item['item_id']); ?>"><i class="fas fa-star"></i></label>
                                                            <input type="radio" id="star4-<?php echo htmlspecialchars($item['item_id']); ?>" name="rating" value="4"><label for="star4-<?php echo htmlspecialchars($item['item_id']); ?>"><i class="fas fa-star"></i></label>
                                                            <input type="radio" id="star3-<?php echo htmlspecialchars($item['item_id']); ?>" name="rating" value="3"><label for="star3-<?php echo htmlspecialchars($item['item_id']); ?>"><i class="fas fa-star"></i></label>
                                                            <input type="radio" id="star2-<?php echo htmlspecialchars($item['item_id']); ?>" name="rating" value="2"><label for="star2-<?php echo htmlspecialchars($item['item_id']); ?>"><i class="fas fa-star"></i></label>
                                                            <input type="radio" id="star1-<?php echo htmlspecialchars($item['item_id']); ?>" name="rating" value="1"><label for="star1-<?php echo htmlspecialchars($item['item_id']); ?>"><i class="fas fa-star"></i></label>
                                                        </div>
                                                        <textarea name="comment" rows="3" placeholder="Leave a comment (optional)"></textarea>
                                                        <button type="submit">Submit Review</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && $_SESSION["role"] !== "customer"): ?>
                                            <p class="no-reviews-message">Log in as a customer to submit reviews.</p>
                                        <?php else: ?>
                                            <p class="no-reviews-message">Please <a href="login.php">log in</a> to submit a review.</p>
                                        <?php endif; ?>

                                        <?php if (!empty($item['reviews'])): ?>
                                            <?php foreach ($item['reviews'] as $review): ?>
                                                <div class="review-item">
                                                    <div class="review-header">
                                                        <span class="reviewer-info">
                                                            <?php echo htmlspecialchars($review['username']); ?>
                                                            <?php echo generateStars(floatval($review['rating'])); ?>
                                                        </span>
                                                        <span class="review-date"><?php echo date('M d, Y', strtotime($review['review_date'])); ?></span>
                                                    </div>
                                                    <?php if (!empty($review['comment'])): ?>
                                                        <p class="review-comment"><?php echo htmlspecialchars($review['comment']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="no-reviews-message">Be the first to review this item!</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-items-message">No menu items available for this restaurant yet. Please check back later!</p>
                        <?php endif; ?>
                    </div> <!-- Close menu-items-grid -->
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // JavaScript function to check login status before adding to cart
        // Now accepts itemId and restaurantId as parameters
        function checkLoginAndAddToCart(itemId, restaurantId, itemName, itemPrice, event) {
            // Check if PHP session 'loggedin' is true
            const isLoggedIn = <?php echo json_encode(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true); ?>;
            const userRole = <?php echo json_encode($_SESSION["role"] ?? null); ?>; // Get user role

            if (!isLoggedIn) {
                event.preventDefault(); // Prevent the default link behavior
                alert("Please log in to add items to your cart.");
                window.location.href = 'login.php'; // Redirect to login page
                return false; // Indicate that the action was cancelled
            } else if (userRole !== 'customer') {
                event.preventDefault(); // Prevent the default link behavior
                alert("Only customers can add items to the cart. Your role is " + userRole + ".");
                return false; // Indicate that the action was cancelled
            } else {
                // If logged in as a customer, proceed to add to cart by navigating to the cart.php URL
                // IMPORTANT: Pass item_id and restaurant_id
                window.location.href = 'cart.php?action=add&item_id=' + itemId + '&restaurant_id=' + restaurantId + '&item_name=' + itemName + '&item_price=' + itemPrice;
                return true; // Indicate that the action should proceed
            }
        }
    </script>
</body>
</html>
