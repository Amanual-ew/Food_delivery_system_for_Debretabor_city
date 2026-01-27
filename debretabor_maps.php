<?php
session_start();
require_once 'config.php';

// --- PHP Logic to Fetch Restaurants ---
// This part fetches all active restaurants with their latitude and longitude from your database.
$restaurants_data = [];
$sql_restaurants = "SELECT name, cuisine_type, address, latitude, longitude FROM restaurants WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL";
if ($result_restaurants = $conn->query($sql_restaurants)) {
    while ($row_restaurant = $result_restaurants->fetch_assoc()) {
        $restaurants_data[] = $row_restaurant;
    }
    $result_restaurants->free();
} else {
    error_log("Error fetching restaurants for map: " . $conn->error);
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debre Tabor Map - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
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

        /* Specific styles for the map container */
        #map {
            height: 500px; /* Set a height for the map container */
            width: 70%; /* Make the map fill its container width */
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            /* Centering using margin auto on a block element */
            margin: 0 auto 20px auto; /* Top 0, left/right auto for centering, bottom 20px */
            display: block; /* Ensure it behaves like a block element for margin auto */
            background-color: #f0f0f0; /* Light background for better contrast */
            border: 1px solid #ddd; /* Subtle border for definition */
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
                    <li><a href="debretabor_maps.php">Debre Tabor Map</a></li> 

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
                                <li><a href="customer_dashboard.php">Account</a></li> <!-- New Account link for customers -->
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
            <h2>Interactive Map of Debre Tabor</h2>
            <p>Explore restaurant locations and delivery areas on the map below.</p>
            <div id="map"></div>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>

    <!-- Leaflet JavaScript -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        function initMap() {
            // Precise coordinates for Debre Tabor, Ethiopia
            const debreTaborCenter = [11.851820, 38.016202];

            // 1. DEFINE YOUR CUSTOM BOUNDARY
            // This is the LatLngBounds object that defines the South-West and North-East corners.
            const southWestCorner = L.latLng(11.8200, 37.9700);
            const northEastCorner = L.latLng(11.8800, 38.0600);
            const mapBounds = L.latLngBounds(southWestCorner, northEastCorner);

            // 2. INITIALIZE THE MAP WITH MAX BOUNDS FOR PANNING
            const map = L.map('map', {
                center: debreTaborCenter,
                maxZoom: 18, // Allow zooming in up to this level
                maxBounds: mapBounds, // Prevents panning outside the boundary
                maxBoundsViscosity: 1.0 // Makes the boundary rigid
            });

            // 3. SET THE TILE LAYER
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                bounds: mapBounds // Restrict tile loading to this boundary (optional performance boost)
            }).addTo(map);

            // 4. DRAW THE VISUAL BOUNDARY RECTANGLE (for debugging/visual confirmation)
            L.rectangle(mapBounds, {color: "#ff7800", weight: 1, fillOpacity: 0.1}).addTo(map);

            // 5. FIT THE MAP TO THE BOUNDARY ON LOAD AND SET MIN ZOOM
            // This calculates the perfect zoom level to fit `mapBounds` into the container...
            map.fitBounds(mapBounds);
            // ...and then sets that calculated zoom level as the minimum, preventing zooming out further.
            map.setMinZoom(map.getZoom());

            // 6. ADD DYNAMIC MARKERS FOR RESTAURANTS
            // This is the section that iterates through the $restaurants_data
            // fetched from your database and adds a marker for each.
            const restaurants = <?php echo json_encode($restaurants_data); ?>;
            restaurants.forEach(rest => {
                // Ensure latitude and longitude exist before adding a marker
                if (rest.latitude && rest.longitude) {
                    let popupContent = '<b>' + htmlspecialchars(rest.name) + '</b><br>';
                    if (rest.cuisine_type) popupContent += 'Cuisine: ' + htmlspecialchars(rest.cuisine_type) + '<br>';
                    if (rest.address) popupContent += 'Address: ' + htmlspecialchars(rest.address);
                    
                    // Create a Leaflet marker at the restaurant's coordinates
                    L.marker([parseFloat(rest.latitude), parseFloat(rest.longitude)]).addTo(map)
                        // Bind a popup that shows restaurant details when clicked
                        .bindPopup(popupContent);
                }
            });
        }

        // Helper function for JavaScript to escape HTML to prevent XSS
        function htmlspecialchars(str) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(str).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        document.addEventListener('DOMContentLoaded', initMap);
    </script>
</body>
</html>
`