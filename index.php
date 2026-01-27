<?php
// session_start() and require_once 'config.php' are usually the first lines.
// All language logic and the __() function are now handled in config.php
// The global $current_lang and __() function are now available.
session_start(); // Explicitly start session here as well, though config.php also checks
require_once 'config.php'; 

// --- Language Switcher Handling (moved here for clean URL redirect) ---
if (isset($_GET['lang'])) {
    $selected_lang = $_GET['lang'];
    $supported_languages = ['en', 'am']; // Define supported languages here for the check

    if (in_array($selected_lang, $supported_languages)) {
        $_SESSION['lang'] = $selected_lang;
    }
    // Redirect to clear the URL from the lang parameter
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Re-evaluate current_lang in case it was just set via GET param
$current_lang = $_SESSION['lang'] ?? 'en';
if (!in_array($current_lang, $supported_languages)) {
    $current_lang = 'en';
}

// --- Fetch Restaurant Locations for Map ---
$restaurant_locations = [];
// NOTE: You must have 'latitude' and 'longitude' columns in your 'restaurants' table!
$sql_locations = "SELECT name, address, latitude, longitude FROM restaurants WHERE is_active = 1";

// Ensure the global $conn variable from config.php is available
global $conn;

if ($result_locations = $conn->query($sql_locations)) {
    while ($row = $result_locations->fetch_assoc()) {
        // Only include locations with valid coordinates
        if (!empty($row['latitude']) && !empty($row['longitude'])) {
            $restaurant_locations[] = $row;
        }
    }
    $result_locations->free();
} else {
    // Log error and use placeholder data for testing if DB query fails 
    error_log("Error fetching restaurant locations for map: " . $conn->error);
    $restaurant_locations = [
        ['name' => 'Tana Cafe', 'address' => 'Near Central Square', 'latitude' => 11.855, 'longitude' => 37.990],
        ['name' => 'Gonder Restaurant', 'address' => 'Main Road', 'latitude' => 11.848, 'longitude' => 37.995],
        ['name' => 'Walia Pizza', 'address' => 'University Area', 'latitude' => 11.858, 'longitude' => 38.005],
    ];
}

// Prepare data for JavaScript
$restaurants_json = json_encode($restaurant_locations);

// No other PHP logic needed here for language, as it's in config.php
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(__('app_title')); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- Google Fonts for Noto Sans Ethiopic for Amharic script support -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;700&display=swap" rel="stylesheet">
    
    <!-- Leaflet CSS for Map Display (CRUCIAL for the map) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />

    <style>
        /* ======================================================= */
        /* === HOVER SIDEBAR STYLING (From Original File) === */
        /* ======================================================= */
        
        /* Ensure the header has a high z-index to stay on top */
        header {
            position: relative; /* Crucial for z-index to work */
            z-index: 1000;
        }

        /* The main container for the icon and the sidebar */
        .user-menu-container {
            position: relative; /* This is crucial for positioning the sidebar */
            display: inline-block;
        }

        /* The icon itself, which triggers the hover effect */
        .user-icon {
            font-size: 1.5em;
            color: #000000ff;
            cursor: pointer;
            padding: 10px;
            transition: color 0.3s ease;
        }
        .containerN{
            display: flex;
            justify-content: space-between;
            align-items:center ;
            padding: 20px 20px;
            margin-left: 20px;
            flex-direction: row;

        }
        
        .user-icon:hover {
            color:rgba(231, 163, 16, 0.83);
        }

        /* The dropdown content (the sidebar) */
        .sidebar {
            position: absolute;
            top: 100%; /* Position below the icon */
            right: 0; /* Align to the right of the icon container */
            width: 150px;
            background-color: #ffffffff;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            border-radius: 5px;
            z-index: 999; /* Below the header, above everything else */
            /* Animation/Visibility control */
            visibility: hidden;
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease;
        }

        /* Show the dropdown on hover of the container */
        .user-menu-container:hover .sidebar {
            visibility: visible;
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Links inside the dropdown */
        .sidebar a {
            display: block;
            padding: 8px 12px;
            text-decoration: none;
            color: #333;
            transition: background-color 0.3s ease;
            font-weight: bold; /* Override header link boldness */
        }

        .sidebar a:hover {
            background-color: #1e19197a;
            color: #ffffffff;
        }
        
        /* Language Switcher Styling */
        .lang-switcher {
            display: flex;
            gap: 5px;
            margin-left: 20px;
        }
        .lang-switcher a {
            color: #000000ff;
            text-decoration: none;
            padding: 5px 8px;
            border-radius: 5px;
            font-weight: 500;
            border: 1px solid transparent;
            transition: background-color 0.3s ease;
        }
        .lang-switcher a:hover {
            background-color: rgba(69, 239, 97, 0.82);
        }
        .lang-switcher a.active-lang {
            background-color: #4CAF50; /* Highlight color for active language */
            border-color: #4CAF50;
            font-weight: bold;
        }

        /* ======================================================= */
        /* === AMHARIC FONT FIX (Recommended for legibility) === */
        /* ======================================================= */
        body {
            /* Add Noto Sans Ethiopic for Amharic script support */
            font-family: 'Noto Sans Ethiopic', 'Inter', sans-serif;
            background-image: url('image/pic9.jpeg');
        }


        /* ======================================================= */
        /* === MAP SECTION STYLING === */
        /* ======================================================= */
        .map-section {
            padding: 40px 0;
            text-align: center;
            background-color: #000000;
        }
        .map-section h2 {
            color: #ffffffff; /* Use primary color */
            margin-bottom: 20px;
        }
        #homeMap {
            height: 400px; 
            width: 80%;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin: 0 auto;
        }
        .map-callout {
            margin-top: 20px;
            font-size: 1.1rem;
            color: #ffffffff;
        }
        .map-callout a {
            color: #ffffffff;
            text-decoration: none;
            font-weight: bold;
        }
        .map-callout a:hover {
            text-decoration: underline;
        }

         .nav-links ul { list-style: none; padding: 0; margin: 0; display: flex; align-items: center; }
         .nav-links ul .lang-switcher { margin-left: 15px; background-color: #07070722; }
        .nav-links ul li { margin-left: 20px; }
        .nav-links ul li a { color: var(--white); text-decoration: none; font-weight: 600; font-size: 0.95rem; }
        .nav-links ul li a.active-nav { color: red; font-weight: bold; }
        .menu-toggle { display: none; font-size: 1.5rem; cursor: pointer; }
        
         .logo h1 { margin: 0; font-size: 2.5rem; color: var(--white); }
       
            a{color:white;}
        .dark-footer .footer-inner { max-width:1200px;margin:0 auto;display:flex;flex-wrap:wrap;gap:28px;justify-content:space-between;align-items:center; }
        .footer-brand { flex:1 1 260px; min-width:230px; display:flex; gap:16px; align-items:flex-start; }
        .footer-brand img.logo { width:72px;height:72px;object-fit:contain;border-radius:10px;box-shadow:0 6px 20px rgba(212,175,55,0.08); }
        .brand-text h3 { margin:0 0 6px;font-size:1.15rem;color:#ffefcf; letter-spacing:0.2px; }
        .brand-text p { margin:0;color:#d9d2c6;line-height:1.45;font-size:.95rem;}
        .footer-section { flex:0 1 200px; min-width:180px; }
        .footer-section h4 { margin:0 0 8px;color:#f7e8c8;font-size:.98rem; }
        .contact-list, .locations-list { list-style:none;margin:0;padding:0;color:#d6cfc3;line-height:2;font-size:.92rem; text-align: center; align-items: center; }
        .contact-list a, .locations-list a { color:inherit;text-decoration:none;opacity:0.95; }
        .partner-cta { display:inline-block;margin-top:8px;padding:8px 12px;background:linear-gradient(135deg,#c84b31,#d4af37);color:#070707;font-weight:700;border-radius:8px;text-decoration:none;box-shadow:0 6px 18px rgba(208,137,54,0.12); }
        .newsletter { flex:1 1 300px; min-width:260px; display: none; } /* Hide newsletter section */
        .socials { display:flex; gap:7px; margin-top:10px; margin-top: 60px;}
        .socials a {margin-right: 10px; margin-left: 20px; font-size: 20px; display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;background:rgba(0, 0, 0, 0);color:#f3efe6;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,0.45); }
        .footer-bottom { border-top:1px solid rgba(255,255,255,0.03); margin-top:24px;padding-top:18px;text-align:center;color:#bfb6a8;font-size:.88rem; }
        .socials a:hover { background:rgba(77, 37, 209, 1);color:#fff; transition: 0.4s; transform: scale(1.2);}
        .partner-cta:hover {scale: 1.05; box-shadow:0 8px 24px rgba(208,137,54,0.2); }
        @media (max-width:780px){  .nav-links { position: absolute; top: 100%; left: 0; width: 100%; background-color: var(--dark); overflow: hidden; max-height: 0; transition: max-height 0.3s ease; }
            .nav-links.active { max-height: 400px; border-top: 1px solid #444; }
            .nav-links ul { flex-direction: column; align-items: flex-start; padding: 10px 0; background-color: white; }
            .nav-links ul li { width: 100%; margin: 0; }
            .sidebar{
                position: static;
                width: 100%;
                box-shadow: none;
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
                margin-top: 10px;
            }
            .user-menu-container i{ display: none;}
            .nav-links ul li a { display: block; padding: 15px 20px; border-bottom: 1px solid #444; }
            .menu-toggle { display: block; }
           .footer-inner{flex-direction:column;align-items:stretch;} }
       
        
    </style>
</head>
<body>
    <header>
        <div class="containerN">
          <div class="logo">  <h1><?php echo h(__('app_name')); ?></h1> </div>
          <div class="menu-toggle" onclick="toggleMenu()"><i class="fas fa-bars"></i></div>
              <nav class="nav-links" id="navLinks"> 
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
                        
                        <!-- Language Switcher -->
                        <li class="lang-switcher">
                            <a href="?lang=en" class="<?php echo ($current_lang === 'en' ? 'active-lang' : ''); ?>">English</a>
                            <a href="?lang=am" class="<?php echo ($current_lang === 'am' ? 'active-lang' : ''); ?>">አማርኛ</a>
                        </li>
                </ul>
            </nav> 
        </div>
    </header>

    <main >
        <section class="hero" style="background-image: url('image/newpic.jpg'); background-size: cover; background-position: center center;">
            <div class="container">
                <h2><?php echo h(__('hero_headline')); ?></h2>
                <p><?php echo h(__('hero_tagline')); ?></p>
                <a href="restaurants.php" class="btn"><?php echo h(__('order_now')); ?></a>
            </div>
        </section>

        <section class="how-it-works" style="background-image: url('image/newpic.jpg'); background-size: cover; background-position: center center;">
            <div class="container" >
                <h2><?php echo h(__('how_it_works_title')); ?></h2>
                <div class="steps">
                    <div class="step">
                        <h3><?php echo h(__('step1_title')); ?></h3>
                        <p><?php echo h(__('step1_description')); ?></p>
                    </div>
                    <div class="step">
                        <h3><?php echo h(__('step2_title')); ?></h3>
                        <p><?php echo h(__('step2_description')); ?></p>
                    </div>
                    <div class="step">
                        <h3><?php echo h(__('step3_title')); ?></h3>
                        <p><?php echo h(__('step3_description')); ?></p>
                    </div>
                    <div class="step">
                        <h3><?php echo h(__('step4_title')); ?></h3>
                        <p><?php echo h(__('step4_description')); ?></p>
                    </div>
                </div>
            </div>
        </section>

        <!-- New Map Section Added Here -->
        <section class="map-section">
            <div class="container">
                <!-- Translated title for the map section -->
                <h2><?php echo h(__('service_area_title')); ?></h2> 
                <!-- The div where the Leaflet map will render -->
                <div id="homeMap"></div>
                
                <!-- The Geolocation button and status message have been removed -->

                <p class="map-callout">
                    <?php echo h(__('full_map_view')); ?> <a href="debretabor_maps.php"><?php echo h(__('debretabor_map')); ?></a>.
                </p>
            </div>
        </section>
        <!-- End of Map Section -->

        
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
    <!-- Leaflet JS Library (CRUCIAL for map functionality) -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

    <script>
        // Debre Tabor Coordinates
        const DEBRE_TABOR_LAT = 11.85;
        const DEBRE_TABOR_LNG = 38.00; 
        const INITIAL_ZOOM = 13;

        // 1. Get restaurant data passed from PHP
        const RESTAURANT_LOCATIONS = JSON.parse('<?php echo $restaurants_json; ?>');
        
        // Global variables for map elements
        let map = null;
        let featureGroup = null;

         function toggleMenu() {
        const nav = document.getElementById('navLinks');
        nav.classList.toggle('active');
    }

        // Function to fit map to restaurant markers (Fallback)
        function fitRestaurantBounds() {
            if (RESTAURANT_LOCATIONS.length > 0) {
                // Fit map view to cover all layers in the feature group
                map.fitBounds(featureGroup.getBounds(), { padding: [50, 50] }); 
            } else {
                // If no data, just set the initial view
                map.setView([DEBRE_TABOR_LAT, DEBRE_TABOR_LNG], INITIAL_ZOOM);
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Check if the map div exists before initializing Leaflet
            if (document.getElementById('homeMap')) {
                
                // Initialize the map. Added minZoom option to allow further zoom-out.
                map = L.map('homeMap', {
                    minZoom: 13 // Allows users to zoom out more than the default setting
                }).setView([DEBRE_TABOR_LAT, DEBRE_TABOR_LNG], INITIAL_ZOOM);

                // Add the OpenStreetMap tiles
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    maxZoom: 18,
                }).addTo(map);

                // Create a feature group to hold all markers and the circle
                featureGroup = L.featureGroup();

                // --- Loop through restaurant data and add markers ---
                RESTAURANT_LOCATIONS.forEach(restaurant => {
                    const popupContent = `
                        <div style="font-family: 'Noto Sans Ethiopic', 'Inter', sans-serif; padding: 5px;">
                            <h4 style="margin: 0 0 5px; color: #ff6b6b; font-weight: bold;">${restaurant.name}</h4>
                            <p style="margin: 0 0 8px; font-size: 0.9rem;"> ${restaurant.address}</p>
                            <a href="restaurants.php" style="color: #4CAF50; text-decoration: none; font-weight: bold; border: 1px solid #4CAF50; padding: 4px 8px; border-radius: 4px; display: inline-block;"><?php echo h(__('order_now'))?></a>
                        </div>
                    `;
                    
                    const marker = L.marker([restaurant.latitude, restaurant.longitude], {
                        // UPDATED: Using fa-utensils, bright red, and larger size for clarity
                        icon: L.divIcon({
                            className: 'custom-restaurant-icon',
                            html: '<i class="fa-solid fa-utensils" style="font-size: 28px; color: #ff0000; text-shadow: 1px 1px 3px #00000050;"></i>',
                            iconSize: [30, 30], // Increased size for the container
                            iconAnchor: [15, 30] // Adjust anchor point
                        })
                    })
                    .bindPopup(popupContent, { 
                        maxWidth: 250, 
                        closeButton: true 
                    });
                    
                    featureGroup.addLayer(marker);
                });

                // Add the central service hub marker
                const hubMarker = L.marker([DEBRE_TABOR_LAT, DEBRE_TABOR_LNG], {
                    icon: L.divIcon({
                        className: 'custom-div-icon',
                        html: '<i class="fa-solid fa-location-dot" style="font-size: 24px; color: #333; text-shadow: 1px 1px 2px #fff;"></i>',
                        iconSize: [22, 22]
                    })
                }).bindPopup("<b>Debre Tabor</b><br>Service Hub.");
                featureGroup.addLayer(hubMarker);


                // Add the service area circle 
                const serviceCircle = L.circle([DEBRE_TABOR_LAT, DEBRE_TABOR_LNG], {
                    color: '#4CAF50', 
                    fillColor: '#4CAF50',
                    fillOpacity: 0.05,
                    radius: 5000 
                }).bindPopup("Approximate Service Radius (5km)");
                featureGroup.addLayer(serviceCircle);

                // Add the feature group to the map
                featureGroup.addTo(map);

                // --- INITIALIZATION ---
                // 1. Set the initial view based on restaurant bounds
                fitRestaurantBounds();
            }
        });
    </script>
    <script src="js/script.js"></script>
</body>
</html>
