<?php
session_start(); 
require_once 'config.php'; 

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
        $comment = empty($comment) ? NULL : $comment; 

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
                $conn->begin_transaction(); 

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

                    // 2. Update menu_items table
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

                    $conn->commit(); 
                    $success_message = "Your review has been submitted successfully!";
                    header("Location: menu.php?restaurant_id=" . $_GET['restaurant_id'] . "&success=" . urlencode($success_message));
                    exit();

                } catch (Exception $e) {
                    $conn->rollback(); 
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

    // 2. Fetch Menu Items (With Search Logic)
    if ($restaurant_details) {
        
        // --- SEARCH LOGIC START ---
        $search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
        
        $sql_menu = "SELECT mi.item_id, mi.name, mi.description, mi.price, mi.is_available, mi.image_path, mi.average_rating, mi.total_rating_sum, mi.num_reviews,
                            COALESCE(SUM(oi.quantity), 0) AS order_count
                     FROM menu_items mi
                     LEFT JOIN order_items oi ON mi.item_id = oi.item_id
                     WHERE mi.restaurant_id = ? AND mi.is_available = 1";
        
        // Append search condition if user typed something
        if (!empty($search_term)) {
            $sql_menu .= " AND (mi.name LIKE ? OR mi.description LIKE ?)";
        }

        $sql_menu .= " GROUP BY mi.item_id, mi.name, mi.description, mi.price, mi.is_available, mi.image_path, mi.average_rating, mi.total_rating_sum, mi.num_reviews
                       ORDER BY mi.name";
        
        if ($stmt_menu = $conn->prepare($sql_menu)) {
            
            // Dynamic binding based on search
            if (!empty($search_term)) {
                $like_term = "%" . $search_term . "%";
                // "iss" = integer (id), string (name), string (description)
                $stmt_menu->bind_param("iss", $restaurant_id, $like_term, $like_term);
            } else {
                // "i" = integer (id)
                $stmt_menu->bind_param("i", $restaurant_id);
            }

            if ($stmt_menu->execute()) {
                $result_menu = $stmt_menu->get_result();
                while ($row_menu = $result_menu->fetch_assoc()) {
                    $item_id = $row_menu['item_id'];
                    $row_menu['reviews'] = []; 
                    $row_menu['has_reviewed'] = false; 

                    // Fetch reviews
                    $sql_fetch_reviews = "SELECT r.rating, r.comment, r.review_date, u.username, r.user_id 
                                          FROM reviews r
                                          JOIN users u ON r.user_id = u.user_id
                                          WHERE r.item_id = ? ORDER BY r.review_date DESC";
                    if ($stmt_reviews = $conn->prepare($sql_fetch_reviews)) {
                        $stmt_reviews->bind_param("i", $item_id);
                        $stmt_reviews->execute();
                        $result_reviews = $stmt_reviews->get_result();
                        while ($review_row = $result_reviews->fetch_assoc()) {
                            $row_menu['reviews'][] = $review_row;
                            if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && $review_row['user_id'] === $_SESSION['user_id']) {
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

// --- Sort menu items into two featured lists (Only if NO search term is active) ---
// If searching, we don't show featured items, just the results.
$top_rated_items = [];
$most_ordered_items = [];

if (empty($search_term)) {
    $top_rated_items = $menu_items;
    $most_ordered_items = $menu_items;

    // Sort by Average Rating
    usort($top_rated_items, function($a, $b) {
        if ($a['average_rating'] == $b['average_rating']) {
            return strcmp($a['name'], $b['name']);
        }
        return ($a['average_rating'] > $b['average_rating']) ? -1 : 1;
    });

    // Sort by Order Count
    usort($most_ordered_items, function($a, $b) {
        if ($a['order_count'] == $b['order_count']) {
            return strcmp($a['name'], $b['name']);
        }
        return ($a['order_count'] > $b['order_count']) ? -1 : 1;
    });

    $top_rated_items = array_slice($top_rated_items, 0, 5);
    $most_ordered_items = array_slice($most_ordered_items, 0, 5);
}

$conn->close(); 

if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}

// Helper functions (generateStars, renderMenuItem)
function generateStars($rating, $max_stars = 5) {
    $html = '<div class="star-rating">';
    for ($i = 1; $i <= $max_stars; $i++) {
        if ($rating >= $i) {
            $html .= '<i class="fas fa-star full-star"></i>';
        } elseif ($rating > ($i - 1) && $rating < $i) { 
            $html .= '<i class="fas fa-star-half-alt half-star"></i>'; 
        } else {
            $html .= '<i class="far fa-star empty-star"></i>'; 
        }
    }
    $html .= '</div>';
    return $html;
}

function renderMenuItem($item, $restaurant_id) {
    $cleaned_image_path_from_db = str_replace('\\', '/', $item['image_path']);
    $absolute_file_path = __DIR__ . '/' . $cleaned_image_path_from_db;
    $item_image_src = 'https://placehold.co/120x120/cccccc/333333?text=No+Img';
    if (!empty($item['image_path']) && file_exists($absolute_file_path)) {
        $item_image_src = htmlspecialchars($cleaned_image_path_from_db);
    }
    
    $rating_html = '';
    if ($item['average_rating'] > 0) {
        $rating_html = generateStars(floatval($item['average_rating']));
        $rating_text = ' <span style="font-size:0.9em; color:#777;">(' . number_format($item['average_rating'], 2) . ' / ' . htmlspecialchars($item['num_reviews']) . ' reviews)</span>';
    } else {
        $rating_text = ' <span style="font-size:0.9em; color:#777;">'. h(__('no_reviews_yet')) .'</span>';
    }

    $output = '
        <div class="menu-item">
            <img src="' . $item_image_src . '"
                 alt="' . htmlspecialchars($item['name']) . '" class="menu-item-image">
            <div class="menu-item-details">
                <h4>' . htmlspecialchars($item['name']) . $rating_html . $rating_text . '</h4>
                <p>' . htmlspecialchars($item['description']) . '</p>';

    if (isset($item['order_count'])) {
        $output .= '<p class="featured-info">' . htmlspecialchars($item['order_count']) . ' Orders Placed</p>';
    }

    $output .= '
            </div>
            <div class="menu-item-price-add">
                <div class="menu-item-price">ETB ' . number_format($item['price'], 2) . '</div>
                <a href="#" class="add-to-cart-btn"
                   onclick="return checkLoginAndAddToCart(
                       ' . htmlspecialchars($item['item_id']) . ',
                       ' . htmlspecialchars($restaurant_id) . ',
                       \'' . urlencode($item['name']) . '\',
                       \'' . urlencode($item['price']) . '\',
                       event
                   );">
                    ' . h(__("add_to_cart")) . '
                </a>
            </div>
        </div>';

    return $output;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $restaurant_details ? htmlspecialchars($restaurant_details['name']) . ' Menu' : 'Menu'; ?> - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        /* Copied existing styles */
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4; color: #333; line-height: 1.6; }
        header { position: relative; z-index: 1000; }
        .user-menu-container { position: relative; display: inline-block; }
        .user-icon { font-size: 1.5em; color: #fff; cursor: pointer; padding: 10px; transition: color 0.3s ease; }
        .user-icon:hover { color: #ff6f61; }
        .sidebar { position: absolute; top: 100%; right: 0; width: 150px; background-color: #fff; box-shadow: 0 4px 8px rgba(0,0,0,0.1); border-radius: 5px; padding: 10px; z-index: 999; visibility: hidden; opacity: 0; transform: translateY(-10px); transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease; }
        .user-menu-container:hover .sidebar { visibility: visible; opacity: 1; transform: translateY(0); }
        .sidebar a { display: block; padding: 8px 12px; text-decoration: none; color: #333; transition: background-color 0.3s ease; }
        .sidebar a:hover { background-color: #f1f1f1; }
        
        /* Restaurant Header */
        .restaurant-header-info { background-color: #fff; padding: 30px; margin-top: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center; }
        .restaurant-header-info h2 { color: #ff6f61; font-size: 2.5em; margin-bottom: 10px; }
        .restaurant-header-info p { font-size: 1.1em; color: #555; margin-bottom: 5px; }
        .restaurant-header-info .cuisine { font-style: italic; font-weight: bold; margin-top: 10px; }
        
        /* Featured Items */
        .featured-items-container { display: grid; grid-template-columns: 1fr; gap: 30px; margin-top: 40px; padding: 20px 0; border-top: 1px solid #ddd; }
        @media (min-width: 768px) { .featured-items-container { grid-template-columns: 1fr 1fr; } }
        .featured-box { background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); padding: 20px; }
        .featured-box h3 { font-size: 1.5em; color: #4a90e2; border-bottom: 2px solid #4a90e2; padding-bottom: 10px; margin-bottom: 15px; text-align: center; }
        .featured-info { font-weight: bold; color: #007bff; margin-top: 5px; font-size: 0.9em; }
        .featured-list .menu-item { display: flex; align-items: center; text-align: left; padding: 10px; margin-bottom: 10px; box-shadow: none; border: 1px solid #eee; }
        .featured-list .menu-item-image { width: 1000px; height: 170px; flex-shrink: 0; margin-right: 15px; margin-bottom: 0; }
        .featured-list .menu-item-details { flex-grow: 1; text-align: left; }
        .featured-list .menu-item-details h4 { font-size: 1.2em; margin-bottom: 0; display: flex; flex-direction: column; align-items: flex-start; }
        .featured-list .star-rating { margin: 0; text-align: left; }
        .featured-list .menu-item-price-add { flex-direction: column; align-items: flex-end; margin-left: 10px; width: auto; }
        .featured-list .add-to-cart-btn { width: auto; padding: 8px 15px; font-size: 0.9em; }

        /* --- SEARCH BAR & HEADER STYLES --- */
        .page-header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 40px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ff6f61;
            flex-wrap: wrap; 
            gap: 15px;
        }
        
        .page-header-container h3 {
            font-size: 2em;
            color: #333;
            margin: 0;
            padding: 0;
            border-bottom: none; /* remove original border */
        }

        .search-form {
            display: flex;
            gap: 10px;
            max-width: 400px;
            width: 100%;
        }

        .search-form input[type="text"] {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 25px;
            flex-grow: 1;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.3s;
        }

        .search-form input[type="text"]:focus {
            border-color: #ff6b6b;
        }

        .search-form button {
            background-color: #ff6b6b;
            color: white;
            border: none;
            border-radius: 25px;
            padding: 10px 20px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .search-form button:hover {
            background-color: #e55a5a;
        }
        
        .clear-search {
            display: inline-block;
            margin-top: 10px;
            color: #ff6b6b;
            text-decoration: none;
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .page-header-container {
                flex-direction: column;
                align-items: flex-start;
            }
            .search-form {
                width: 100%;
            }
        }

        /* Grid */
        .menu-items-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-top: 30px; }
        .menu-item { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; flex-direction: column; align-items: center; text-align: center; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .menu-item:hover { transform: translateY(-5px); box-shadow: 0 6px 12px rgba(0,0,0,0.1); }
        .menu-item-image { width: 100%; max-width: 200px; height: 150px; object-fit: cover; border-radius: 8px; border: 1px solid #eee; margin-bottom: 15px; }
        .menu-item-details { flex-grow: 1; width: 100%; text-align: center; }
        .menu-item-details h4 { margin-top: 0; margin-bottom: 5px; font-size: 1.5em; color: #333; }
        .menu-item-details p { margin-bottom: 10px; color: #666; font-size: 0.95em; }
        .menu-item-price-add { width: 100%; margin-top: 15px; display: flex; flex-direction: column; align-items: center; }
        .menu-item-price { font-size: 1.4em; font-weight: bold; color: #ff6f61; margin-bottom: 10px; }
        .add-to-cart-btn { background-color: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 1em; text-decoration: none; transition: background-color 0.3s ease; width: 80%; max-width: 200px; }
        .add-to-cart-btn:hover { background-color: #218838; }
        .no-items-message { text-align: center; margin-top: 30px; font-size: 1.2em; color: #666; }
        .star-rating { display: block; margin: 5px auto 10px auto; font-size: 1.1em; text-align: center; }
        .star-rating i { color: #FFD700; margin: 0 1px; }
        .star-rating .empty-star { color: #ccc; }
        .reviews-section { margin-top: 20px; padding-top: 20px; border-top: 1px dashed #eee; text-align: left; width: 100%; }
        .reviews-section h5 { font-size: 1.2em; color: #ff6f61; margin-bottom: 15px; text-align: center; }
        .review-item { background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; padding: 15px; margin-bottom: 10px; }
        .review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; font-size: 0.95em; }
        .review-header .reviewer-info { font-weight: bold; color: #333; }
        .review-header .review-date { color: #888; font-size: 0.85em; }
        .review-comment { color: #555; font-size: 0.9em; margin-top: 5px; }
        .no-reviews-message { text-align: center; color: #777; font-style: italic; }
        .review-form { background-color: #f0f8ff; border: 1px solid #cceeff; border-radius: 8px; padding: 20px; margin-top: 20px; text-align: center; }
        .review-form h6 { font-size: 1.1em; color: #333; margin-bottom: 15px; }
        .review-form .star-rating-input { margin-bottom: 15px; direction: rtl; display: inline-block; }
        .review-form .star-rating-input input[type="radio"] { display: none; }
        .review-form .star-rating-input label { font-size: 1.8em; color: #ccc; cursor: pointer; margin: 0 2px; display: inline-block; transition: color 0.2s ease; }
        .review-form .star-rating-input label:hover, .review-form .star-rating-input label:hover ~ label, .review-form .star-rating-input input[type="radio"]:checked ~ label { color: #FFD700; }
        .review-form textarea { width: calc(100% - 20px); padding: 10px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px; resize: vertical; min-height: 60px; font-size: 1em; }
        .review-form button[type="submit"] { background-color: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 1em; transition: background-color 0.3s ease; }
        .review-form button[type="submit"]:hover { background-color: #0056b3; }
        .review-already-submitted { background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 15px; border-radius: 8px; margin-top: 20px; text-align: center; }
        .message, .error-message { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 10px; margin-bottom: 20px; border-radius: 5px; text-align: center; width: 100%; box-sizing: border-box; }
        .error-message { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
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
    
        @media (max-width: 768px) {
            .menu-item { align-items: center; text-align: center; }
            .menu-item-details { text-align: center; }
            .menu-item-price-add { align-items: center; }
            .footer-inner{flex-direction:column;align-items:stretch;}
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1><?php echo h(__('app_name')) ?></h1>
            <nav>
                <ul>
                    <li><a href="index.php"><?php echo h(__('home')) ?></a></li>
                    <li><a href="restaurants.php"><?php echo h(__('restaurants')) ?></a></li>
                    <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                        <?php if (isset($_SESSION["role"])): ?>
                            <?php if ($_SESSION["role"] === "customer"): ?>
                                <li><a href="cart.php"><?php echo h(__('cart')) ?></a></li>
                                <li><a href="customer_dashboard.php"><?php echo h(__('account')) ?></a></li>
                            <?php elseif ($_SESSION["role"] === "restaurant_manager"): ?>
                                <li><a href="manage_menu.php"><?php echo h(__('manage_menu')) ?></a></li>
                                <li><a href="manage_orders.php"><?php echo h(__('orders')) ?></a></li>
                            <?php elseif ($_SESSION["role"] === "delivery_personnel"): ?>
                            <?php elseif ($_SESSION["role"] === "admin"): ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <li><a href="logout.php"><?php echo h(__('logout')) ?></a></li>
                    <?php else: ?>
                        <li class="user-menu-container">
                            <i class="fa-solid fa-user user-icon"></i>
                            <div class="sidebar">
                                <a href="login.php"><?php echo h(__('login')) ?></a>
                                <a href="register.php"><?php echo h(__('register')) ?></a>
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
                    <p><?php echo h(__('adress')) ?> <?php echo htmlspecialchars($restaurant_details['address']); ?></p>
                    <p><?php echo h(__('phone:')) ?> <?php echo htmlspecialchars($restaurant_details['phone_number']); ?></p>
                    <p><?php echo h(__('hours')) ?> <?php echo htmlspecialchars(substr($restaurant_details['opening_time'], 0, 5)) . ' - ' . htmlspecialchars(substr($restaurant_details['closing_time'], 0, 5)); ?></p>
                </div>

                <?php if (empty($search_term) && (!empty($top_rated_items) || !empty($most_ordered_items))): ?>
                <div class="featured-items-container">
                    
                    <div class="featured-box">
                        <h3><i class="fas fa-star" style="color: gold;"></i> <?php echo h(__('highest_rated')) ?></h3>
                        <div class="featured-list">
                            <?php if (!empty($top_rated_items)): ?>
                                <?php $featured_top = array_slice($top_rated_items, 0, 3); ?>
                                <?php foreach ($featured_top as $item): ?>
                                    <?php echo renderMenuItem($item, $restaurant_id); ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="no-reviews-message"><?php echo h(__('no_rated_items')) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="featured-box">
                        <h3><i class="fas fa-chart-line" style="color: #4a90e2;"></i> <?php echo h(__('most_ordered')) ?></h3>
                        <div class="featured-list">
                            <?php if (!empty($most_ordered_items)): ?>
                                <?php $featured_most = array_slice($most_ordered_items, 0, 3); ?>
                                <?php foreach ($featured_most as $item): ?>
                                    <?php echo renderMenuItem($item, $restaurant_id); ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="no-reviews-message"><?php echo h(__('no_orderd_items')) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>


                <div class="menu-category">
                    
                    <div class="page-header-container">
                        <h3><?php echo h(__('all_menu_items')) ?></h3>
                        
                        <form method="GET" action="menu.php" class="search-form">
                            <input type="hidden" name="restaurant_id" value="<?php echo htmlspecialchars($restaurant_id); ?>">
                            <input type="text" name="search" placeholder="<?php echo h(__('search_placeholder')) ?>" value="<?php echo htmlspecialchars($search_term); ?>">
                            <button type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>

                    <?php if(!empty($search_term)): ?>
                        <p><?php echo h(__('showing_result')) ?><strong><?php echo htmlspecialchars($search_term); ?></strong> <a href="menu.php?restaurant_id=<?php echo $restaurant_id; ?>" class="clear-search">(Clear)</a></p>
                    <?php endif; ?>

                    <div class="menu-items-grid"> 
                        <?php if (!empty($menu_items)): ?>
                            <?php foreach ($menu_items as $item): ?>
                                <div class="menu-item">
                                    <?php
                                    $cleaned_image_path_from_db = str_replace('\\', '/', $item['image_path']);
                                    $absolute_file_path = __DIR__ . '/' . $cleaned_image_path_from_db;
                                    $item_image_src = 'https://placehold.co/120x120/cccccc/333333?text=No+Img';

                                    if (!empty($item['image_path']) && file_exists($absolute_file_path)) {
                                        $item_image_src = htmlspecialchars($cleaned_image_path_from_db);
                                    }
                                    ?>
                                    <img src="<?php echo $item_image_src; ?>"
                                         alt="<?php echo htmlspecialchars($item['name']); ?>" class="menu-item-image">
                                    <div class="menu-item-details">
                                        <h4>
                                            <?php echo htmlspecialchars($item['name']); ?>
                                            <?php
                                            if ($item['average_rating'] > 0) {
                                                echo generateStars(floatval($item['average_rating']));
                                                echo ' <span style="font-size:0.9em; color:#777;">(' . number_format($item['average_rating'], 2) . ' / ' . htmlspecialchars($item['num_reviews']) . ' reviews)</span>';
                                            } else {
                                                echo ' <span style="font-size:0.9em; color:#777;">('. h(__("no_reviews_yet")) .' )</span>';
                                            }
                                            ?>
                                        </h4>
                                        <p><?php echo htmlspecialchars($item['description']); ?></p>
                                    </div>
                                    <div class="menu-item-price-add">
                                        <div class="menu-item-price">ETB <?php echo number_format($item['price'], 2); ?></div>
                                        <a href="#" class="add-to-cart-btn"
                                           onclick="return checkLoginAndAddToCart(
                                               <?php echo htmlspecialchars($item['item_id']); ?>,
                                               <?php echo htmlspecialchars($restaurant_id); ?>,
                                               '<?php echo urlencode($item['name']); ?>',
                                               '<?php echo urlencode($item['price']); ?>',
                                               event
                                           );">
                                            <?php echo h(__('add_to_cart')) ?>
                                        </a>
                                    </div>

                                    <div class="reviews-section">
                                        <h5><?php echo h(__('customer_review')) ?></h5>
                                        <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && $_SESSION["role"] === "customer"): ?>
                                            <?php if ($item['has_reviewed']): ?>
                                                <div class="review-already-submitted">
                                                    <?php echo h(__('u_have_already_review')) ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="review-form">
                                                    <h6><?php echo h(__('submit_review')) ?></h6>
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
                                                        <button type="submit"><?php echo h(__('submit_review')) ?></button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && $_SESSION["role"] !== "customer"): ?>
                                            <p class="no-reviews-message"><?php echo h(__('login_as_a_customer_to_submit_reviews')) ?></p>
                                        <?php else: ?>
                                            <p class="no-reviews-message"><?php echo h(__('please_login_to_submit_a_review')) ?></p>
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
                                            <p class="no-reviews-message"><?php echo h(__('be_the_first')) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-items-message">
                                <?php echo !empty($search_term) ? h(__("no_items_found")) : h(__('no_menu_items')); ?>
                            </p>
                        <?php endif; ?>
                    </div> 
                </div>
            <?php endif; ?>
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
        function checkLoginAndAddToCart(itemId, restaurantId, itemName, itemPrice, event) {
            const isLoggedIn = <?php echo json_encode(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true); ?>;
            const userRole = <?php echo json_encode($_SESSION["role"] ?? null); ?>; 

            if (!isLoggedIn) {
                event.preventDefault(); 
                alert("Please log in to add items to your cart.");
                window.location.href = 'login.php'; 
                return false; 
            } else if (userRole !== 'customer') {
                event.preventDefault(); 
                alert("Only customers can add items to the cart. Your role is " + userRole + ".");
                return false; 
            } else {
                window.location.href = 'cart.php?action=add&item_id=' + itemId + '&restaurant_id=' + restaurantId + '&item_name=' + itemName + '&item_price=' + itemPrice;
                return true; 
            }
        }
    </script>
</body>
</html>