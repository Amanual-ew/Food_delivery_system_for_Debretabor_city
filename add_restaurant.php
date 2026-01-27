<?php
session_start(); // Always start the session at the very beginning
require_once 'config.php'; // Include database connection

// Check if the user is logged in AND if their role is 'admin'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: login.php"); // Redirect to login if not an admin
    exit;
}

// --- PHP Logic for Adding Restaurant ---

// Initialize variables with empty values
// NOTE: $manager_username replaces the need to fetch all managers from the database
$name = $address = $phone_number = $email = $cuisine_type = $opening_time = $closing_time = $manager_username = $latitude = $longitude = "";
$manager_id = 0; // Initialize ID to be set after username lookup

// Initialize error variables
$name_err = $address_err = $phone_number_err = $email_err = $cuisine_type_err = $opening_time_err = $closing_time_err = $manager_username_err = $latitude_err = $longitude_err = "";
$success_message = "";

// NOTE: The initial manager fetching logic is removed to comply with the privacy request.


// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate Name
    if (empty(trim($_POST["name"]))) {
        $name_err = "Please enter restaurant name.";
    } else {
        $name = trim($_POST["name"]);
    }

    // Validate Address
    if (empty(trim($_POST["address"]))) {
        $address_err = "Please enter restaurant address.";
    } else {
        $address = trim($_POST["address"]);
    }

    // Validate Phone Number (basic check)
    if (empty(trim($_POST["phone_number"]))) {
        $phone_number_err = "Please enter restaurant phone number.";
    } elseif (!preg_match("/^[0-9]{10,15}$/", trim($_POST["phone_number"]))) { // Basic 10-15 digit number check
        $phone_number_err = "Please enter a valid phone number (10-15 digits).";
    } else {
        $phone_number = trim($_POST["phone_number"]);
    }

    // Validate Email (optional, but good practice)
    if (!empty(trim($_POST["email"])) && !filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
        $email_err = "Please enter a valid email address.";
    } else {
        $email = trim($_POST["email"]);
    }

    // Validate Cuisine Type
    if (empty(trim($_POST["cuisine_type"]))) {
        $cuisine_type_err = "Please enter cuisine type.";
    } else {
        $cuisine_type = trim($_POST["cuisine_type"]);
    }

    // Validate Opening Time
    if (empty(trim($_POST["opening_time"]))) {
        $opening_time_err = "Please enter opening time.";
    } else {
        $opening_time = trim($_POST["opening_time"]);
    }

    // Validate Closing Time
    if (empty(trim($_POST["closing_time"]))) {
        $closing_time_err = "Please enter closing time.";
    } else {
        $closing_time = trim($_POST["closing_time"]);
    }

    // ==========================================================
    // ** MODIFIED VALIDATION LOGIC FOR MANAGER USERNAME LOOKUP **
    // ==========================================================
    if (empty(trim($_POST["manager_username"]))) {
        $manager_username_err = "Please enter the restaurant manager's username.";
    } else {
        $manager_username = trim($_POST["manager_username"]);

        // Check if the username exists, has the role 'restaurant_manager', and is active
        $sql_check = "SELECT user_id FROM users WHERE username = ? AND role = 'restaurant_manager' AND is_active = 1";

        if ($stmt_check = $conn->prepare($sql_check)) {
            $stmt_check->bind_param("s", $param_manager_username);
            $param_manager_username = $manager_username;

            if ($stmt_check->execute()) {
                $result_check = $stmt_check->get_result();
                if ($result_check->num_rows == 1) {
                    $row = $result_check->fetch_assoc();
                    $manager_id = (int)$row['user_id']; // Found the ID, save it for insertion
                } else {
                    $manager_username_err = "Manager username not found or the user is not an active Restaurant Manager.";
                }
            } else {
                error_log("Error checking manager existence: " . $stmt_check->error);
                $manager_username_err = "Database error while verifying manager.";
            }
            $stmt_check->close();
        } else {
            error_log("Error preparing check query: " . $conn->error);
            $manager_username_err = "Database preparation error. Try again.";
        }
    }
    // ==========================================================
    // ** END MODIFIED VALIDATION **
    // ==========================================================

    // Validate Latitude
    if (empty(trim($_POST["latitude"]))) {
        $latitude_err = "Please enter latitude.";
    } elseif (!is_numeric(trim($_POST["latitude"])) || trim($_POST["latitude"]) < -90 || trim($_POST["latitude"]) > 90) {
        $latitude_err = "Please enter a valid latitude (-90 to 90).";
    } else {
        $latitude = (float)trim($_POST["latitude"]); // Cast to float
    }

    // Validate Longitude
    if (empty(trim($_POST["longitude"]))) {
        $longitude_err = "Please enter longitude.";
    } elseif (!is_numeric(trim($_POST["longitude"])) || trim($_POST["longitude"]) < -180 || trim($_POST["longitude"]) > 180) {
        $longitude_err = "Please enter a valid longitude (-180 to 180).";
    } else {
        $longitude = (float)trim($_POST["longitude"]); // Cast to float
    }


    // Check input errors before inserting in database
    if (empty($name_err) && empty($address_err) && empty($phone_number_err) && empty($email_err) &&
        empty($cuisine_type_err) && empty($opening_time_err) && empty($closing_time_err) && empty($manager_username_err) &&
        empty($latitude_err) && empty($longitude_err) && $manager_id > 0) { // Check if manager ID was successfully found

        // Prepare an insert statement
        $sql = "INSERT INTO restaurants (name, address, phone_number, cuisine_type, opening_time, closing_time, manager_id, latitude, longitude, is_active) VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

        if ($stmt = $conn->prepare($sql)) {
            // NOTE: The ID ($manager_id) is used here after being looked up from the username.
            $stmt->bind_param("ssssssidd", $param_name, $param_address, $param_phone_number, $param_cuisine_type, $param_opening_time, $param_closing_time, $param_manager_id, $param_latitude, $param_longitude);

            // Set parameters
            $param_name = $name;
            $param_address = $address;
            $param_phone_number = $phone_number;
            $param_cuisine_type = $cuisine_type;
            $param_opening_time = $opening_time;
            $param_closing_time = $closing_time;
            $param_manager_id = $manager_id; // Use the found ID
            $param_latitude = $latitude;
            $param_longitude = $longitude;

            // Attempt to execute the prepared statement
            if ($stmt->execute()) {
                $success_message = "Restaurant '" . htmlspecialchars($name) . "' added successfully and assigned to manager '" . htmlspecialchars($manager_username) . "'!";
                // Clear form fields after successful submission
                $name = $address = $phone_number = $email = $cuisine_type = $opening_time = $closing_time = $manager_username = $latitude = $longitude = "";
                $manager_id = 0;
            } else {
                // Use a more robust error message
                error_log("Error executing query in add_restaurant.php: " . $stmt->error);
                echo "<p class='error-message'>Error adding restaurant. Please check server logs for details.</p>";
            }

            $stmt->close();
        } else {
            error_log("Error preparing query in add_restaurant.php: " . $conn->error);
            echo "<p class='error-message'>Database query preparation failed. Please try again later.</p>";
        }
    }
}
// Close connection if it's still open (optional, as PHP closes it automatically at script end)
if ($conn && $conn->ping()) { // Check if connection is alive before trying to close
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Restaurant - Admin Panel</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        /* ======================================================= */
        /* === ADMIN HEADER STYLING (Override global style.css) === */
        /* ======================================================= */
        header {
            position: relative; /* Ensure z-index works */
            z-index: 1000; /* Ensure header is on top of other content */
        }
        /* Hide the default navigation list for admin pages */
        header nav ul {
            display: none;
        }
        /* Center the main title for admin pages */
        header h1 {
            float: none; /* Remove float from global style.css */
            text-align: center; /* Center the title */
            width: 100%; /* Ensure it takes full width for centering */
            margin-bottom: 0; /* Adjust margin as needed */
        }
        /* Clearfix might still be needed if other elements float, but H1/NAV are now handled */
        header .container::after {
            content: "";
            display: table;
            clear: both;
        }

        /* ======================================================= */
        /* === QUICK ACTIONS BUTTONS STYLING === */
        /* ======================================================= */
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 30px;
            justify-content: center;
        }
        .quick-actions .btn {
            min-width: 180px; /* Give buttons a consistent minimum width */
            text-align: center;
        }

        /* ======================================================= */
        /* === ADD RESTAURANT FORM SPECIFIC STYLING === */
        /* ======================================================= */
        .add-restaurant-form {
            background-color: #fff;
            padding: 40px;
            margin: 50px auto;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            max-width: 600px;
        }

        .add-restaurant-form h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }

        .add-restaurant-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }

        .add-restaurant-form input[type="text"],
        .add-restaurant-form input[type="email"],
        .add-restaurant-form input[type="tel"],
        .add-restaurant-form input[type="time"],
        .add-restaurant-form textarea,
        .add-restaurant-form select {
            width: calc(100% - 22px); /* Account for padding and border */
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
        }

        .add-restaurant-form input[type="submit"] {
            width: 65%;
            background-color: #ff6f61;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
            display: block; /* Make button take full row */
            margin: 20px auto 0; /* Center the button */
        }

        .add-restaurant-form input[type="submit"]:hover {
            background-color: #e65c50;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .form-row > div {
            flex: 1;
        }

        .error {
            color: #dc3545;
            font-size: 0.9em;
            display: block;
            margin-top: -15px; /* Adjust spacing after input */
            margin-bottom: 15px;
        }

        .message {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
            text-align: center;
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .error-message {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        .error-message ul {
            text-align: left;
            margin-top: 10px;
            padding-left: 20px;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Debre Tabor Food Delivery</h1>
            <nav>
                <!-- The navigation list is intentionally empty/hidden here as per admin page design -->
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <h2>Add New Restaurant</h2>

            <!-- Admin Tools Navigation -->
            <div class="quick-actions">
                <a href="admin_dashboard.php" class="btn" style="background-color: #6c757d;">Back to Dashboard</a>
                <a href="manage_users.php" class="btn" style="background-color: #007bff;">Manage Users</a>
                <a href="manage_restaurants.php" class="btn" style="background-color: #28a745;">Manage Restaurants</a>
                <a href="configure_payment_settings.php" class="btn" style="background-color: #17a2b8;">Payment Settings</a>
                <a href="logout.php" class="btn" style="background-color: #dc3545;">Logout</a>
            </div>
            
            <?php if (!empty($success_message)) { echo '<div class="message">' . $success_message . '</div>'; } ?>
            <?php
            // Consolidate error messages display
            $all_errors = array_filter([$name_err, $address_err, $phone_number_err, $email_err, $cuisine_type_err, 
                                         $opening_time_err, $closing_time_err, $manager_username_err, $latitude_err, $longitude_err]);
            if (!empty($all_errors)) {
                echo '<div class="error-message">Please correct the following errors:<ul>';
                foreach($all_errors as $err) {
                    echo '<li>' . $err . '</li>';
                }
                echo '</ul></div>';
            }
            ?>

            <div class="add-restaurant-form">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                    <div class="form-group">
                        <label for="name">Restaurant Name:</label>
                        <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($name); ?>">
                        <span class="error"><?php echo $name_err; ?></span>
                    </div>

                    <div class="form-group">
                        <label for="address">Address:</label>
                        <textarea id="address" name="address" rows="3" required><?php echo htmlspecialchars($address); ?></textarea>
                        <span class="error"><?php echo $address_err; ?></span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone_number">Phone Number:</label>
                            <input type="tel" id="phone_number" name="phone_number" required value="<?php echo htmlspecialchars($phone_number); ?>">
                            <span class="error"><?php echo $phone_number_err; ?></span>
                        </div>
                        <div class="form-group">
                            <label for="email">Email (Optional):</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>">
                            <span class="error"><?php echo $email_err; ?></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="cuisine_type">Cuisine Type (e.g., Ethiopian, Italian, Fast Food):</label>
                        <input type="text" id="cuisine_type" name="cuisine_type" required value="<?php echo htmlspecialchars($cuisine_type); ?>">
                        <span class="error"><?php echo $cuisine_type_err; ?></span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="opening_time">Opening Time:</label>
                            <input type="time" id="opening_time" name="opening_time" required value="<?php echo htmlspecialchars($opening_time); ?>">
                            <span class="error"><?php echo $opening_time_err; ?></span>
                        </div>
                        <div class="form-group">
                            <label for="closing_time">Closing Time:</label>
                            <input type="time" id="closing_time" name="closing_time" required value="<?php echo htmlspecialchars($closing_time); ?>">
                            <span class="error"><?php echo $closing_time_err; ?></span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="latitude">Latitude:</label>
                            <input type="text" id="latitude" name="latitude" required value="<?php echo htmlspecialchars($latitude); ?>" placeholder="e.g., 11.851820">
                            <span class="error"><?php echo $latitude_err; ?></span>
                        </div>
                        <div class="form-group">
                            <label for="longitude">Longitude:</label>
                            <input type="text" id="longitude" name="longitude" required value="<?php echo htmlspecialchars($longitude); ?>" placeholder="e.g., 38.016202">
                            <span class="error"><?php echo $longitude_err; ?></span>
                        </div>
                    </div>

                    <!-- ** MODIFIED: Replaced select dropdown with text input for manager username lookup ** -->
                    <div class="form-group">
                        <label for="manager_username">Assign Restaurant Manager (by **Existing Username**):</label>
                        <input type="text" id="manager_username" name="manager_username" required 
                               value="<?php echo htmlspecialchars($manager_username); ?>" 
                               placeholder="Enter existing manager's username">
                        <span class="error"><?php echo $manager_username_err; ?></span>
                    </div>
                    <!-- ** END MODIFIED ** -->

                    <input type="submit" value="Add Restaurant">
                </form>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>