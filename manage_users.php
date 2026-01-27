<?php
session_start();
require_once 'config.php'; // Include database connection

// Check if the user is logged in AND if their role is 'admin'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: login.php"); // Redirect to login if not an admin
    exit;
}

// --- PHP Logic for User Management ---

$message = ""; // To display success/error messages
$error_message = ""; // To display general error messages

// Handle user deactivation/activation or deletion
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action']) && isset($_POST['user_id'])) {
        $user_id_to_manage = $_POST['user_id'];
        $action = $_POST['action']; // 'deactivate', 'activate', 'delete'

        // Prevent admin from managing themselves
        if ($user_id_to_manage == $_SESSION['user_id']) {
            $error_message = "You cannot " . $action . " your own account.";
        } else {
            // Use prepared statements for security
            $sql = "";
            if ($action == 'deactivate' || $action == 'activate') {
                $status = ($action == 'activate') ? 1 : 0; // 1 for active, 0 for inactive
                $sql = "UPDATE users SET is_active = ? WHERE user_id = ?";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("ii", $status, $user_id_to_manage);
                    if ($stmt->execute()) {
                        $message = "User " . $user_id_to_manage . " " . (($action == 'activate') ? 'activated' : 'deactivated') . " successfully.";
                    } else {
                        $error_message = "Error " . (($action == 'activate') ? 'activating' : 'deactivating') . " user: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Database query preparation failed: " . $conn->error;
                }
             
            }
        }
    }
}

// Fetch all users from the database to display
$users = [];
$sql = "SELECT user_id, username, email, phone_number, role, is_active FROM users ORDER BY role, username";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $result->free(); // Free result set
} else {
    $error_message = "Error fetching users: " . $conn->error;
}

$conn->close(); // Close connection after all operations
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
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
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .user-table th, .user-table td {
            border: 1px solid #ddd;
            padding: 8px;
            background-color: #ddd;
            text-align: left;
        }
        .user-table th {
            background-color: #f2f2f2;
        }
        .action-buttons button {
            padding: 5px 10px;
            margin-right: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
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
            <h2>Manage System Users</h2>

            <!-- Admin Tools Navigation -->
            <div class="quick-actions">
                 <a href="admin_dashboard.php" class="btn" style="background-color: #6c757d;">
                    <li class="fas fa-arrows"></li>Back to Dashboard</a>
               <a href="manage_restaurants.php" class="btn btn-restaurants" style="background-color: green;">
                    <i class="fas fa-utensils"></i> Manage Restaurants
                </a>
                <a href="staff_register.php" class="btn btn-staff-register" style="background-color: brown;">
                    <i class="fas fa-user-plus"></i> Register Staff
                </a>
                <a href="add_restaurant.php" class="btn btn-add-restaurant" style="background-color: teal;">
                    <i class="fas fa-plus-circle"></i> Add New Restaurant
                </a>
                <a href="manage_fund_requests.php" class="btn btn-fund-requests" style="background-color: blue;">
                    <i class="fas fa-money-check-alt"></i> Manage Fund Requests <!-- NEW LINK -->
                </a>
                <a href="configure_payment_settings.php" class="btn btn-payment-settings" style="background-color: orange;">
                    <i class="fas fa-cog"></i> Payment Settings
                </a>
                <a href="logout.php" class="btn btn-logout" style="background-color: red;">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
            
            <?php if (!empty($message)) { echo '<div class="message">' . $message . '</div>'; } ?>
            <?php if (!empty($error_message)) { echo '<div class="error-message">' . $error_message . '</div>'; } ?>

            <table class="user-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7">No users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['phone_number']); ?></td>
                                <td><?php echo htmlspecialchars($user['role']); ?></td>
                                <td><?php echo ($user['is_active'] ? 'Active' : 'Inactive'); ?></td>
                                <td class="action-buttons">
                                    <?php
                                    // Check if the current user in the loop is the logged-in admin
                                    if ($user['user_id'] == $_SESSION['user_id']) {
                                        echo '<span style="color: #6c757d; font-style: italic;">(Cannot manage self)</span>';
                                    } else {
                                        // Display action buttons only if it's not the logged-in admin
                                    ?>
                                        <form action="manage_users.php" method="POST" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            <?php if ($user['is_active']): ?>
                                                <button type="submit" name="action" value="deactivate" class="btn-deactivate">Deactivate</button>
                                            <?php else: ?>
                                                <button type="submit" name="action" value="activate" class="btn-activate">Activate</button>
                                            <?php endif; ?>
                                        </form>
                                        <form action="manage_users.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                             </form>
                                    <?php
                                    }
                                    ?>
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
