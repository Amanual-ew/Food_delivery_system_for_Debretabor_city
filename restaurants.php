<?php
session_start(); 
require_once 'config.php'; 

// --- Language and Localization Logic ---
$translations = [
    'en' => [
        'restaurants_title' => 'Restaurants - Debre Tabor Food Delivery',
        'browse_restaurants' => 'Browse Restaurants',
        'restaurant' => 'Restaurant',
        'address' => 'Address',
        'phone' => 'Phone',
        'hours' => 'Hours',
        'view_menu' => 'View Menu',
        'no_restaurants' => 'No restaurants found matching your search.',
        'all_rights_reserved' => 'All rights reserved.',
        'home' => 'Home',
        'login' => 'Login',
        'register' => 'Register',
        'logout' => 'Logout',
        'my_account' => 'My Account',
        'checkout' => 'Checkout',
        'my_orders' => 'My Orders',
        'hi_user' => 'Hi, ',
        'app_name' => 'Debre Tabor Food Delivery',
        'search_placeholder' => 'Search by name ', 
        'search_btn' => 'Search', 
        'contact'=>'Contact',
        'phone'=>'Phone',
        'cart'=> 'Cart',
        'order_now'=>'Order Now',
        'quick_links'=>'Quick Links',
        'Have_u_registered_before?,please_login'=>'Have you registered before? Please login',
        'If_u_want_to_be_our_customer_please_register'=>'If you want to be our customer please register',
        'head_office_address'=>'Head Office Address',
        'head_office_address'=>'Head Office Address',
        'footer_tagline'=>'Delivering delicious meals from your favorite local restaurants right to your doorstep in Debre Tabor.',
    ],
    'am' => [
        'restaurants_title' => 'ምግብ ቤቶች - ደብረ ታቦር የምግብ አቅርቦት',
        'browse_restaurants' => 'ምግብ ቤቶችን ያስሱ',
        'restaurant' => 'ምግብ ቤት',
        'address' => 'አድራሻ',
        'phone' => 'ስልክ',
        'hours' => 'የስራ ሰዓት',
        'view_menu' => 'ዝርዝር ይመልከቱ',
        'no_restaurants' => 'ከፍለጋዎ ጋር የሚዛመዱ ምግብ ቤቶች የሉም።',
        'all_rights_reserved' => 'መብቱ በህግ የተጠበቀ ነው',
        'home' => 'መነሻ ገጽ',
        'login' => 'ይግቡ',
        'order_now'=>'አሁን ይዘዙ',
        'register' => 'ይመዝገቡ',
        'logout' => 'ይውጡ',
        'my_account' => 'የእኔ መለያ',
        'checkout' => 'ክፍያ ይፈጽሙ',
        'my_orders' => 'የእኔ ትዕዛዞች',
        'hi_user' => 'ሰላም, ',
        'app_name' => 'ደብረ ታቦር የምግብ አቅርቦት',
        'search_placeholder' => 'ምግብ ቤቶችን በስም ይፈልጉ...', // Added
        'search_btn' => 'ፈልግ', // Added
        'head_office_address'=>'የራስ ቢሮ አድራሻ',
        'contact'=>'ያግኙን',
        'phone'=>'ስልክ',
        'cart'=> 'ጋሪ',
        'head_office_address'=>'የራስ ቢሮ አድራሻ',
        'Have_u_registered_before?,please_login'=>'ከዚህ በፊት  ተመዝግበዎል? እባክዎ ይግቡ።',
        'If_u_want_to_be_our_customer_please_register'=>'እኛ ደንበኛ ለማድረግ ከፈለጉ እባክዎ ይመዝገቡ።',
        'footer_tagline'=>'ከእርስዎ ወደ ደጃፍዎ ከሚወዱት አገር ውስጥ ምግብ ቤቶች ጣፋጭ ምግቦችን በፍጥነት እንዲደርስዎታል።',
    ]
];

$lang = 'en';
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $translations)) {
    $_SESSION['lang'] = $_GET['lang'];
}
if (isset($_SESSION['lang'])) {
    $lang = $_SESSION['lang'];
}

function t($key) {
    global $translations, $lang;
    return $translations[$lang][$key] ?? $key; 
}

// --- Search Logic & Database Fetching ---

// Check if a search term exists
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$restaurants = [];

// Prepare the SQL query
$sql = "SELECT restaurant_id, name, address, phone_number, cuisine_type, opening_time, closing_time 
        FROM restaurants 
        WHERE is_active = 1";

// If searching, add the filter to the query
if (!empty($search_term)) {
    $sql .= " AND (name LIKE ? OR cuisine_type LIKE ?)";
}

$sql .= " ORDER BY name";

// Prepare statement to prevent SQL injection
if ($stmt = $conn->prepare($sql)) {
    
    if (!empty($search_term)) {
        $like_term = "%" . $search_term . "%";
        $stmt->bind_param("ss", $like_term, $like_term);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $restaurants[] = $row;
    }
    $stmt->close();
} else {
    error_log("Error fetching restaurants: " . $conn->error);
}

$conn->close(); 
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('restaurants_title'); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        /* Copied existing styles */
        .restaurant-card {
            border: 1px solid #ddd;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            background-color: #fff;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .restaurant-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
        }

        .restaurant-card-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .restaurant-card-content {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .restaurant-card-content h3 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.5em;
            color: #333;
        }

        .restaurant-card-content p {
            margin: 0 0 8px;
            color: #666;
            font-size: 0.95em;
        }

        .restaurant-card-content p.cuisine {
            font-style: italic;
            color: #888;
        }

        .restaurant-card-content .btn {
            margin-top: auto; 
            display: inline-block;
            background-color: #ff6b6b;
            color: white;
            padding: 12px 24px;
            text-align: center;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .restaurant-card-content .btn:hover {
            background-color: #e55a5a;
        }

        .restaurants-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
            padding: 20px 0;
        }

        /* --- NEW STYLES FOR SEARCH BAR --- */
        .page-header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-top: 20px;
            flex-wrap: wrap; /* Allows stacking on mobile */
            gap: 15px;
        }

        .search-form {
            display: flex;
            gap: 10px;
            max-width: 400px;
            width: 100%;
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

        @media (max-width: 768px) {
            .restaurants-grid {
                grid-template-columns: 1fr;
            }
            /* Stack title and search on mobile */
            .page-header-container {
                flex-direction: column;
                align-items: flex-start;
            }
            .search-form {
                width: 100%;
            }
        }
        .user-menu-container  {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .user-menu-container a{
            color: white;
            text-decoration: none;
        }
        
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="logo">
                <h1><?php echo t('app_name'); ?></h1>
            </div>
            <nav class="main-nav">
                <ul>
                    <li><a href="index.php"><?php echo t('home'); ?></a></li>
                    <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                            <li><a href="#" class="user-icon"><i class="fas fa-user-circle"></i> <?php echo t('hi') ?>, <?php echo htmlspecialchars($_SESSION["username"]); ?></a></li>
                            
                            <li><a href="customer_dashboard.php"><?php echo t('my_account'); ?></a></li>
                            <li><a href="cart.php"><?php echo t('cart'); ?></a></li>
                            <li><a href="my_orders.php"><?php echo t('my_orders'); ?></a></li>
                            <li><a href="logout.php"><?php echo t('logout'); ?></a></li>
                    <?php else: ?>
                        
                        <li><a href="login.php"><?php echo t('login'); ?></a></li>
                        <li><a href="register.php"><?php echo t('register'); ?></a></li>
                    <?php endif; ?>
                     </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="restaurants-list">
            <div class="container">
                
                <div class="page-header-container">
                    <h2><?php echo t('browse_restaurants'); ?></h2>
                    
                    <form method="GET" action="restaurants.php" class="search-form">
                        <input type="text" name="search" placeholder="<?php echo t('search_placeholder'); ?>" value="<?php echo htmlspecialchars($search_term); ?>">
                        <button type="submit">
                            <i class="fas fa-search"></i> <?php echo t('search_btn'); ?>
                        </button>
                    </form>
                </div>

                <div class="restaurants-grid">
                    <?php if (!empty($restaurants)): ?>
                        <?php foreach ($restaurants as $restaurant): ?>
                            <div class="restaurant-card">
                                <img src="https://placehold.co/400x200/cccccc/333333?text=<?php echo htmlspecialchars(urlencode($restaurant['name'])); ?>" alt="<?php echo htmlspecialchars($restaurant['name']); ?> <?php echo t('restaurant'); ?>" class="restaurant-card-image">
                                <div class="restaurant-card-content">
                                    <h3><?php echo htmlspecialchars($restaurant['name']); ?></h3>
                                    <p class="cuisine"><?php echo htmlspecialchars($restaurant['cuisine_type']); ?></p>
                                    <p><strong><?php echo t('address'); ?>:</strong> <?php echo htmlspecialchars($restaurant['address']); ?></p>
                                    <p><strong><?php echo t('phone'); ?>:</strong> <?php echo htmlspecialchars($restaurant['phone_number']); ?></p>
                                    <p><strong><?php echo t('hours'); ?>:</strong> <?php echo htmlspecialchars(substr($restaurant['opening_time'], 0, 5)) . ' - ' . htmlspecialchars(substr($restaurant['closing_time'], 0, 5)); ?></p>
                                    <a href="menu.php?restaurant_id=<?php echo htmlspecialchars($restaurant['restaurant_id']); ?>" class="btn"><?php echo t('view_menu'); ?></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p><?php echo t('no_restaurants'); ?></p>
                    <?php endif; ?>
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
                <img src="image/logo.png" alt="<?php echo t(__('app_name')); ?> logo" class="logo" onerror="this.style.display='none'">
                <div class="brand-text">
                    <h3><?php echo t(__('app_name')) ?: 'FoodDelivery'; ?></h3>
                    <p><?php echo t(__('footer_tagline')) ?: 'Fast, premium meals from the best local restaurants.'; ?></p>
                    <a href="restaurants.php" class="partner-cta" aria-label="Order now"><?php echo t(__('order_now')) ?: 'Order Now'; ?></a>
                </div>
            </div>

            <!-- Contact & Partner Signup -->
            <div class="footer-section" aria-label="Contact">
                <h4><?php echo t(__('contact')) ?: 'Contact'; ?></h4>
                <ul class="contact-list">
                    <li><strong><?php echo t(__('phone')) ?: 'Phone'; ?>:</strong> <a href="tel:+251911000000">+251 911 000 000</a></li>
                    <li><strong><?php echo t(__('email')) ?: 'Email'; ?>:</strong> <a href="mailto:info@fooddelivery.local">debretaborfooddelivery@gmail.com</a></li>
                    <li><strong><?php echo t(__('address')) ?: 'Address'; ?>:</strong> <?php echo t(__('head_office_address')) ?: 'Debre Tabor, Ethiopia'; ?></li>
                </ul>

                
            </div>
            <div>
                    <h4><?php echo t(__('If_u_want_to_be_our_customer_please_register')) ?: 'If you want to be our customer please register'; ?></h4>
                    <h4><?php echo t(__('Have_u_registered_before?,please_login')) ?: 'Have you registered before? Please login'; ?></h4>
                    <a href="register.php" class="partner-cta" aria-label="register"><?php echo t(__('register')) ?: 'Register'; ?></a>
                    <a href="login.php" class="partner-cta" aria-label="login"><?php echo t(__('login')) ?: 'Login'; ?></a>
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
                &copy; <?php echo date('Y'); ?> <?php echo t(__('app_name')) ?: 'FoodDelivery'; ?>. <?php echo t(__('all_rights_reserved')) ?: 'All rights reserved.'; ?>
                &nbsp;&middot;&nbsp;
                <a href="privacy.php" style="color:inherit;text-decoration:underline;"><?php echo t(__('privacy_policy')) ?: 'Privacy Policy'; ?></a>
                &nbsp;&middot;&nbsp;
                <a href="terms.php" style="color:inherit;text-decoration:underline;"><?php echo t(__('terms_of_service')) ?: 'Terms of Service'; ?></a>
            </small>
        </div>
        
    </footer>
    <script src="js/script.js"></script>
</body>
</html>