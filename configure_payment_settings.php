<?php
session_start();
require_once 'config.php'; // Include database connection

// Check if the user is logged in AND if their role is 'admin'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: login.php"); // Redirect to login if not an admin
    exit;
}

// --- PHP Logic for Payment Settings Management ---

$message = "";
$error_message = "";
$transaction_fee_percentage = "";

// Handle form submission to update settings
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['transaction_fee_percentage'])) {
        $new_fee = trim($_POST['transaction_fee_percentage']);

        // Basic validation
        if (!is_numeric($new_fee) || $new_fee < 0 || $new_fee > 100) {
            $error_message = "Transaction fee percentage must be a number between 0 and 100.";
        } else {
            // Update the setting in the database
            // Use ON DUPLICATE KEY UPDATE to insert if not exists, or update if it does
            $sql = "INSERT INTO system_settings (setting_name, setting_value, description)
                    VALUES ('virtual_banking_transaction_fee_percentage', ?, 'Percentage fee applied to virtual banking transactions.')
                    ON DUPLICATE KEY UPDATE setting_value = ?";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ss", $new_fee, $new_fee);
                if ($stmt->execute()) {
                    $message = "Transaction fee updated successfully!";
                } else {
                    $error_message = "Error updating fee: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Database query preparation failed: " . $conn->error;
            }
        }
    }
}

// Fetch current settings to display in the form
$sql_fetch = "SELECT setting_value FROM system_settings WHERE setting_name = 'virtual_banking_transaction_fee_percentage'";
if ($result = $conn->query($sql_fetch)) {
    if ($row = $result->fetch_assoc()) {
        $transaction_fee_percentage = htmlspecialchars($row['setting_value']);
    } else {
        // If the setting doesn't exist, provide a default or an instruction
        $transaction_fee_percentage = "0.0"; // Default value if not found
        $error_message .= " Virtual banking transaction fee setting not found in database. It will be created on first save.";
    }
    $result->free();
} else {
    $error_message .= " Error fetching current settings: " . $conn->error;
}

$conn->close(); // Close connection
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Settings - Admin Panel</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Adjust header navigation for admin dashboard */
        header nav ul {
            display: none; /* Hide the navigation list */
        }
        /* Optionally, center the h1 if nav is removed */
        header h1 {
            float: none; /* Remove float */
            text-align: center; /* Center the title */
        }
        /* Ensure quick actions buttons are well-spaced */
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

        /* Form Specific Styles (from previous version, ensuring consistency) */
        .settings-form {
            background-color: #fff;
            padding: 40px;
            margin: 50px auto;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            max-width: 500px;
            
        }
        .settings-form h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        .settings-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        .settings-form input[type="text"] {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
        }
        .settings-form input[type="submit"] {
            width: 70%;
            background-color: #ff6f61;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
        }
        .settings-form input[type="submit"]:hover {
            background-color: #e65c50;
        }
        .description {
            font-size: 0.9em;
            color: #666;
            margin-top: -15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Debre Tabor Food Delivery</h1>
            <nav>
                <!-- The navigation list is intentionally empty/hidden here -->
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <h2>Configure Payment Settings</h2>

            <!-- Admin Tools Navigation -->
            <div class="quick-actions">
                <a href="admin_dashboard.php" class="btn" style="background-color: #6c757d;">Back to Dashboard</a>
                <a href="manage_users.php" class="btn" style="background-color: #007bff;">Manage Users</a>
                <a href="manage_restaurants.php" class="btn" style="background-color: #28a745;">Manage Restaurants</a>
                <a href="add_restaurant.php" class="btn" style="background-color: #ffc107; color: #333;">Add New Restaurant</a>
                <a href="logout.php" class="btn" style="background-color: #dc3545;">Logout</a>
            </div>
            
            <?php if (!empty($message)) { echo '<div class="message">' . $message . '</div>'; } ?>
            <?php if (!empty($error_message)) { echo '<div class="error-message">' . $error_message . '</div>'; } ?>

            <div class="settings-form">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                    <label for="transaction_fee_percentage">Virtual Banking Transaction Fee Percentage (%):</label>
                    <input type="text" id="transaction_fee_percentage" name="transaction_fee_percentage" 
                           value="<?php echo htmlspecialchars($transaction_fee_percentage); ?>" required>
                    <p class="description">This percentage will be applied as a fee to all virtual banking transactions.</p>
                    <input type="submit" value="Save Settings">
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
