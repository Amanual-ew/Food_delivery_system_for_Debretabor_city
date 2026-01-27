<?php
session_start();
require_once 'config.php'; // Ensure this path is correct for your database connection

// Check if the user is logged in and is a delivery personnel
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "delivery_personnel") {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Delivery Personnel'; // Fallback username
$error_message = '';
$success_message = '';

// Initialize user data variables
$current_username = '';
$current_email = '';
$current_phone_number = '';
$current_role = '';

// Determine active section (e.g., 'account-settings', 'help-center')
$active_section = isset($_GET['section']) ? $_GET['section'] : 'account-settings';

// Fetch current user data from the database
$sql_fetch_user_data = "SELECT username, email, phone_number, role FROM users WHERE user_id = ?";
if ($stmt = $conn->prepare($sql_fetch_user_data)) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $user_data = $result->fetch_assoc();
            $current_username = h($user_data['username']);
            $current_email = h($user_data['email']);
            $current_phone_number = h($user_data['phone_number']);
            $current_role = h($user_data['role']);
        } else {
            $error_message .= "User data not found. Please contact support.";
        }
    } else {
        $error_message .= "Error fetching user data: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error_message .= "Error preparing user data fetch query: " . $conn->error;
}


// --- Handle Form Submissions ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle Profile Update
    if (isset($_POST['update_profile'])) {
        $new_username = trim($_POST['username']);
        $new_email = trim($_POST['email']);
        $new_phone_number = trim($_POST['phone_number']);

        // Basic validation
        if (empty($new_username) || empty($new_email) || empty($new_phone_number)) {
            $error_message .= "Please fill in all profile fields.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message .= "Invalid email format.";
        } else {
            // Check if username or email already exists (excluding current user)
            $sql_check_duplicate = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?";
            if ($stmt_check = $conn->prepare($sql_check_duplicate)) {
                $stmt_check->bind_param("ssi", $new_username, $new_email, $user_id);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $error_message .= "Username or Email already taken by another user.";
                }
                $stmt_check->close();
            } else {
                $error_message .= "Error preparing duplicate check: " . $conn->error;
            }

            if (empty($error_message)) {
                $sql_update_profile = "UPDATE users SET username = ?, email = ?, phone_number = ? WHERE user_id = ?";
                if ($stmt_update = $conn->prepare($sql_update_profile)) {
                    $stmt_update->bind_param("sssi", $new_username, $new_email, $new_phone_number, $user_id);
                    if ($stmt_update->execute()) {
                        $success_message .= "Profile updated successfully.";
                        // Update session username immediately
                        $_SESSION['username'] = $new_username;
                        // Re-fetch current data to reflect changes in the form
                        $current_username = h($new_username);
                        $current_email = h($new_email);
                        $current_phone_number = h($new_phone_number);
                    } else {
                        $error_message .= "Error updating profile: " . $stmt_update->error;
                    }
                    $stmt_update->close();
                } else {
                    $error_message .= "Error preparing profile update query: " . $conn->error;
                }
            }
        }
    }

    // Handle Password Change
    if (isset($_POST['change_password'])) {
        $old_password = $_POST['old_password'];
        $new_password = $_POST['new_password'];
        $confirm_new_password = $_POST['confirm_new_password'];

        // Fetch current hashed password from DB
        $sql_get_hashed_pass = "SELECT password_hash FROM users WHERE user_id = ?";
        $hashed_password = '';
        if ($stmt_pass = $conn->prepare($sql_get_hashed_pass)) {
            $stmt_pass->bind_param("i", $user_id);
            if ($stmt_pass->execute()) {
                $result_pass = $stmt_pass->get_result();
                if ($row = $result_pass->fetch_assoc()) {
                    $hashed_password = $row['password_hash'];
                }
            }
            $stmt_pass->close();
        }

        // Validate old password
        if (!password_verify($old_password, $hashed_password)) {
            $error_message .= "Incorrect old password.";
        } elseif (empty($new_password) || empty($confirm_new_password)) {
            $error_message .= "Please fill in all password fields.";
        } elseif ($new_password !== $confirm_new_password) {
            $error_message .= "New password and confirmation do not match.";
        } elseif (strlen($new_password) < 6) {
            $error_message .= "New password must be at least 6 characters long.";
        } else {
            // Hash new password and update
            $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql_update_pass = "UPDATE users SET password_hash = ? WHERE user_id = ?";
            if ($stmt_update_pass = $conn->prepare($sql_update_pass)) {
                $stmt_update_pass->bind_param("si", $new_hashed_password, $user_id);
                if ($stmt_update_pass->execute()) {
                    $success_message .= "Password updated successfully.";
                } else {
                    $error_message .= "Error updating password: " . $stmt_update_pass->error;
                }
                $stmt_update_pass->close();
            } else {
                $error_message .= "Error preparing password update query: " . $conn->error;
            }
        }
    }
}

// Function to safely output HTML special characters
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Re-using base styles from delivery_personnel_dashboard and delivery_history for consistency */
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .container {
            width: 95%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 0;
        }

        header {
            background: linear-gradient(to right, #333, #555);
            color: #fff;
            padding: 15px 0;
            border-bottom: 5px solid #4CAF50;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }

        header .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            margin: 0;
            font-size: 2.2em;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.3);
        }

        header nav ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 25px;
        }

        header nav ul li a {
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.05em;
            padding: 8px 12px;
            border-radius: 5px;
            transition: background-color 0.3s ease, color 0.3s ease, transform 0.2s ease;
            display: inline-block;
        }

        header nav ul li a:hover,
        header nav ul li a.active-nav {
            color: #4CAF50;
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        main {
            flex-grow: 1;
            padding: 20px 0;
        }

        .delivery-dashboard-wrapper {
            display: flex;
            gap: 30px;
            padding: 30px;
            background-color: #fff;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            min-height: 600px;
        }

        .sidebar {
            flex: 0 0 250px;
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 25px 0;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.05);
        }

        .sidebar .profile-summary {
            text-align: center;
            padding-bottom: 25px;
            margin-bottom: 25px;
            border-bottom: 1px solid #eee;
        }

        .sidebar .profile-summary img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #4CAF50;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .sidebar .profile-summary h3 {
            margin: 0;
            color: #333;
            font-size: 1.5em;
            font-weight: bold;
        }

        .sidebar .profile-summary p {
            margin: 5px 0 0;
            color: #777;
            font-size: 0.9em;
        }

        .sidebar nav ul {
            display: flex;
            flex-direction: column;
            gap: 5px;
            padding: 0 15px;
            float: none;
        }

        .sidebar nav ul li {
            width: 100%;
            display: block;
            margin: 0;
        }

        .sidebar nav ul li a {
            padding: 12px 15px;
            color: #555;
            text-decoration: none;
            font-weight: 500;
            border-radius: 8px;
            transition: background-color 0.3s ease, color 0.3s ease;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar nav ul li a:hover,
        .sidebar nav ul li a.active-sidebar-link {
            background-color: #e6ffe6;
            color: #4CAF50;
            font-weight: bold;
        }
        .sidebar nav ul li a i {
            font-size: 1.1em;
            width: 20px;
            text-align: center;
        }

        /* Main Content Area for Dashboard / Account Settings */
        .dashboard-content-panel {
            flex: 1;
            background-color: #fdfdfd;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
        }

        .dashboard-content-panel h2 {
            color: #333;
            font-size: 2.2em;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }

        /* Form styles */
        .settings-section {
            margin-bottom: 40px;
            padding: 25px;
            border: 1px solid #e9e9e9;
            border-radius: 10px;
            background-color: #fefefe;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .settings-section h3 {
            font-size: 1.8em;
            color: #4CAF50;
            margin-top: 0;
            margin-bottom: 20px;
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 1px dashed #e0e0e0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
            font-size: 1em;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"] {
            width: calc(100% - 20px);
            padding: 12px 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1em;
            box-sizing: border-box;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group input[type="password"]:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
            outline: none;
        }

        .btn-update {
            background-color: #4CAF50;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            transition: background-color 0.3s ease, transform 0.2s ease;
            width: auto;
            display: block;
            margin: 20px auto 0; /* Center button */
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .btn-update:hover {
            background-color: #45a049;
            transform: translateY(-2px);
        }

        /* Message styling */
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
            text-align: center;
            display: none; /* Hidden by default */
        }

        .message.success-message {
            background-color: #e6ffe6;
            color: #28a745;
            border: 1px solid #28a745;
            display: block; /* Show if populated */
        }

        .message.error-message {
            background-color: #ffe6e6;
            color: #dc3545;
            border: 1px solid #dc3545;
            display: block; /* Show if populated */
        }

        /* Help Center placeholder styling */
        .help-center-content {
            text-align: center;
            padding: 50px 20px;
            color: #666;
            font-size: 1.1em;
        }

        .help-center-content i {
            font-size: 4em;
            color: #ccc;
            margin-bottom: 30px;
        }

        .help-center-content h3 {
            font-size: 1.8em;
            color: #555;
            margin-bottom: 15px;
        }

        .help-center-content p {
            margin-bottom: 20px;
        }

        .help-center-content .contact-info {
            font-size: 0.9em;
            color: #888;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .delivery-dashboard-wrapper {
                flex-direction: column;
                padding: 20px;
            }
            .sidebar {
                flex: 0 0 auto;
                width: 100%;
                padding: 15px 0;
            }
            .sidebar nav ul {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                gap: 10px;
                padding: 0 10px;
            }
            .sidebar nav ul li {
                width: auto;
            }
            .sidebar nav ul li a {
                padding: 10px 15px;
            }
            .dashboard-content-panel {
                padding: 20px;
            }
        }
        @media (max-width: 768px) {
            header .container {
                flex-direction: column;
                text-align: center;
            }
            header h1 {
                margin-bottom: 10px;
                font-size: 1.8em;
            }
            header nav ul {
                flex-direction: column;
                gap: 10px;
            }
            header nav ul li a {
                padding: 5px 10px;
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
                    <!-- Navigation for Delivery Personnel -->
                    <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && $_SESSION["role"] === "delivery_personnel"): ?>
                        <li><a href="delivery_personnel_dashboard.php">Dashboard</a></li>
                        <li><a href="delivery_history.php">History</a></li>
                        <li><a href="earnings.php">Earnings</a></li>
                        <li><a href="account.php" class="active-nav">My Account</a></li> <!-- Active link -->
                        <li><a href="logout.php">Logout</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="delivery-dashboard-wrapper">
            <!-- Left Sidebar -->
            <div class="sidebar">
                <div class="profile-summary">
                    <img src="https://placehold.co/100x100/eeeeee/aaaaaa?text=<?php echo substr($username, 0, 1); ?>" alt="User Avatar">
                    <h3><?php echo h($username); ?></h3>
                    <p>Delivery Personnel</p>
                </div>
                <nav>
                    <ul>
                        <li><a href="delivery_personnel_dashboard.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a></li>
                        <li><a href="delivery_history.php" class="sidebar-link"><i class="fas fa-history"></i> Delivery History</a></li>
                        <li><a href="earnings.php" class="sidebar-link"><i class="fas fa-dollar-sign"></i> Earnings</a></li>
                        <li><a href="account.php" class="sidebar-link <?php echo ($active_section === 'account-settings' ? 'active-sidebar-link' : ''); ?>"><i class="fas fa-cog"></i> Account Settings</a></li>
                        <li><a href="account.php?section=help-center" class="sidebar-link <?php echo ($active_section === 'help-center' ? 'active-sidebar-link' : ''); ?>"><i class="fas fa-question-circle"></i> Help</a></li>
                    </ul>
                </nav>
            </div>

            <!-- Right Main Content Panel -->
            <div class="dashboard-content-panel">
                <?php if (!empty($success_message)): ?>
                    <div class="message success-message"><?php echo h($success_message); ?></div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="message error-message"><?php echo h($error_message); ?></div>
                <?php endif; ?>

                <?php if ($active_section === 'account-settings'): ?>
                    <h2>Account Settings</h2>

                    <div class="settings-section">
                        <h3>Update Profile Information</h3>
                        <form action="account.php?section=account-settings" method="POST">
                            <div class="form-group">
                                <label for="username">Username:</label>
                                <input type="text" id="username" name="username" value="<?php echo $current_username; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email:</label>
                                <input type="email" id="email" name="email" value="<?php echo $current_email; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="phone_number">Phone Number:</label>
                                <input type="text" id="phone_number" name="phone_number" value="<?php echo $current_phone_number; ?>" required>
                            </div>
                            <button type="submit" name="update_profile" class="btn-update">Update Profile</button>
                        </form>
                    </div>

                    <div class="settings-section">
                        <h3>Change Password</h3>
                        <form action="account.php?section=account-settings" method="POST">
                            <div class="form-group">
                                <label for="old_password">Old Password:</label>
                                <input type="password" id="old_password" name="old_password" required>
                            </div>
                            <div class="form-group">
                                <label for="new_password">New Password:</label>
                                <input type="password" id="new_password" name="new_password" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_new_password">Confirm New Password:</label>
                                <input type="password" id="confirm_new_password" name="confirm_new_password" required>
                            </div>
                            <button type="submit" name="change_password" class="btn-update">Change Password</button>
                        </form>
                    </div>
                <?php elseif ($active_section === 'help-center'): ?>
                    <h2>Help Center</h2>
                    <div class="help-center-content settings-section">
                        <i class="fas fa-question-circle"></i>
                        <h3>Need Assistance?</h3>
                        <p>If you encounter any issues or have questions, please reach out to our support team.</p>
                        <p>Our support hours are Monday - Friday, 9:00 AM - 5:00 PM (Local Time).</p>
                        <p class="contact-info">Email: <a href="mailto:debretaborfooddelivery@gmail.com">debretaborfooddelivery@gmail.com</a></p>
                        <p class="contact-info">Phone: +251 99 370 4927</p>
                        <p>We are here to help you make your delivery experience smooth!</p>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>

    
</body>
</html>
