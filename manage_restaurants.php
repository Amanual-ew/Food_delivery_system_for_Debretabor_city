<?php
session_start();
require_once 'config.php'; // Include database connection

// Check if the user is logged in AND if their role is 'admin'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: login.php"); // Redirect to login if not an admin
    exit;
}

// --- PHP Logic for Restaurant Management ---

$message = ""; // To display success/error messages
$error_message = ""; // To display general error messages

// Handle restaurant deactivation/activation or deletion
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action']) && isset($_POST['restaurant_id'])) {
        $restaurant_id_to_manage = $_POST['restaurant_id'];
        $action = $_POST['action']; // 'deactivate', 'activate', 'delete'

        // Use prepared statements for security
        $sql = "";
        if ($action == 'deactivate' || $action == 'activate') {
            $status = ($action == 'activate') ? 1 : 0; // 1 for active, 0 for inactive
            $sql = "UPDATE restaurants SET is_active = ? WHERE restaurant_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ii", $status, $restaurant_id_to_manage);
                if ($stmt->execute()) {
                    $message = "Restaurant " . $restaurant_id_to_manage . " " . (($action == 'activate') ? 'activated' : 'deactivated') . " successfully.";
                } else {
                    $error_message = "Error " . (($action == 'activate') ? 'activating' : 'deactivating') . " restaurant: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Database query preparation failed: " . $conn->error;
            }
        } elseif ($action == 'delete') {
            // IMPORTANT: Deleting a restaurant will also delete its menu items due to CASCADE
            // Make sure your foreign key constraint on menu_items table has ON DELETE CASCADE
            $sql = "DELETE FROM restaurants WHERE restaurant_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("i", $restaurant_id_to_manage);
                if ($stmt->execute()) {
                    $message = "Restaurant " . $restaurant_id_to_manage . " deleted successfully.";
                } else {
                    $error_message = "Error deleting restaurant: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Database query preparation failed: " . $conn->error;
            }
        }
    }
}

// Fetch all restaurants from the database to display
$restaurants = [];
// Join with users table to get manager's username
$sql = "SELECT r.restaurant_id, r.name, r.address, r.phone_number, r.cuisine_type, r.is_active, u.username AS manager_username
        FROM restaurants r
        LEFT JOIN users u ON r.manager_id = u.user_id
        ORDER BY r.name";

if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $restaurants[] = $row;
    }
    $result->free(); // Free result set
} else {
    $error_message = "Error fetching restaurants: " . $conn->error;
}

$conn->close(); // Close connection after all operations
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Restaurants - Admin Panel</title>
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

        /* Table Styles */
        .restaurant-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .restaurant-table th, .restaurant-table td {
            border: 1px solid #ddd;
            padding: 10px;
            background-color: white;
            text-align: left;
        }
        .restaurant-table th {
            background-color: #f2f2f2;
            font-weight: bold;
            color: #555;
        }
        .action-buttons button {
            padding: 6px 12px;
            margin-right: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
            font-size: 0.9em;
            transition: opacity 0.3s ease;
        }
        .action-buttons .btn-deactivate { background-color: #f44336; } /* Red */
        .action-buttons .btn-activate { background-color: #4CAF50; } /* Green */
        .action-buttons .btn-delete { background-color: #dc3545; } /* Bootstrap danger red */
        .action-buttons button:hover {
            opacity: 0.8;
        }
        .message {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
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
            <h2>Manage System Restaurants</h2>

            <!-- Admin Tools Navigation -->
            <div class="quick-actions">
                <a href="admin_dashboard.php" class="btn" style="background-color: #6c757d;">Back to Dashboard</a>
                <a href="manage_users.php" class="btn" style="background-color: #007bff;">Manage Users</a>
                <a href="add_restaurant.php" class="btn" style="background-color: #ffc107; color: #333;">Add New Restaurant</a>
                <a href="configure_payment_settings.php" class="btn" style="background-color: #17a2b8;">Payment Settings</a>
                <a href="logout.php" class="btn" style="background-color: #dc3545;">Logout</a>
            </div>
            
            <?php if (!empty($message)) { echo '<div class="message">' . $message . '</div>'; } ?>
            <?php if (!empty($error_message)) { echo '<div class="error-message">' . $error_message . '</div>'; } ?>

            <table class="restaurant-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Address</th>
                        <th>Phone</th>
                        <th>Cuisine</th>
                        <th>Manager</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($restaurants)): ?>
                        <tr><td colspan="8">No restaurants found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($restaurants as $restaurant): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($restaurant['restaurant_id']); ?></td>
                                <td><?php echo htmlspecialchars($restaurant['name']); ?></td>
                                <td><?php echo htmlspecialchars($restaurant['address']); ?></td>
                                <td><?php echo htmlspecialchars($restaurant['phone_number']); ?></td>
                                <td><?php echo htmlspecialchars($restaurant['cuisine_type']); ?></td>
                                <td><?php echo htmlspecialchars($restaurant['manager_username'] ?: 'N/A'); ?></td>
                                <td><?php echo ($restaurant['is_active'] ? 'Active' : 'Inactive'); ?></td>
                                <td class="action-buttons">
                                    <form action="manage_restaurants.php" method="POST" style="display:inline;">
                                        <input type="hidden" name="restaurant_id" value="<?php echo $restaurant['restaurant_id']; ?>">
                                        <?php if ($restaurant['is_active']): ?>
                                            <button type="submit" name="action" value="deactivate" class="btn-deactivate">Deactivate</button>
                                        <?php else: ?>
                                            <button type="submit" name="action" value="activate" class="btn-activate">Activate</button>
                                        <?php endif; ?>
                                    </form>
                                    <form action="manage_restaurants.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this restaurant? This will also delete all its menu items.');">
                                        <input type="hidden" name="restaurant_id" value="<?php echo $restaurant['restaurant_id']; ?>">
                                        <button type="submit" name="action" value="delete" class="btn-delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
