<?php
// Always start the session at the very beginning of the script
session_start();

// Include the database connection file
require_once 'config.php';

// Define variables and initialize with empty values
$username = $email = $phone = $role = $password = $confirm_password = $vehicle = "";
$username_err = $email_err = $phone_err = $role_err = $password_err = $confirm_password_err = $vehicle_err = "";
$success_message = $error_message = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. Validate Username
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter a username.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', trim($_POST["username"]))) {
        $username_err = "Username can only contain letters, numbers, and underscores.";
    } else {
        $sql = "SELECT user_id FROM users WHERE username = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $param_username);
            $param_username = trim($_POST["username"]);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $username_err = "This username is already taken.";
                } else {
                    $username = trim($_POST["username"]);
                }
            } else {
                $error_message = "Oops! Something went wrong checking username. Please try again later.";
            }
            $stmt->close();
        }
    }

    // 2. Validate Email
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter an email address.";
    } elseif (!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
        $email_err = "Please enter a valid email address.";
    } else {
        $sql = "SELECT user_id FROM users WHERE email = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $param_email);
            $param_email = trim($_POST["email"]);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $email_err = "This email is already registered.";
                } else {
                    $email = trim($_POST["email"]);
                }
            } else {
                $error_message = "Oops! Something went wrong checking email. Please try again later.";
            }
            $stmt->close();
        }
    }

    // 3. Validate Phone
    if (empty(trim($_POST["phone"]))) {
        $phone_err = "Please enter your phone number.";
    } elseif (!preg_match("/^[0-9+\-\s()]{10,15}$/", trim($_POST["phone"]))) {
        $phone_err = "Please enter a valid phone number.";
    } else {
        $phone = trim($_POST["phone"]);
    }

    // 4. Validate Role
    if (empty(trim($_POST["role"]))) {
        $role_err = "Please select a role.";
    } else {
        $role_input = trim($_POST["role"]);
        if ($role_input === "manager") {
            $role = "restaurant_manager";
        } elseif ($role_input === "delivery") {
            $role = "delivery_personnel";
        } else {
            $role_err = "Invalid role selected.";
        }
    }

    // 5. Validate Password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
    } else {
        $password = trim($_POST["password"]);
    }

    // 6. Validate Confirm Password
    if (empty(trim($_POST["confirmPassword"]))) {
        $confirm_password_err = "Please confirm password.";
    } else {
        $confirm_password = trim($_POST["confirmPassword"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Password did not match.";
        }
    }

    // 7. Validate Vehicle (only for delivery personnel)
    if (isset($_POST["role"]) && trim($_POST["role"]) === "delivery") {
        if (empty(trim($_POST["vehicle"]))) {
            $vehicle_err = "Please select a vehicle type.";
        } else {
            $vehicle = trim($_POST["vehicle"]);
        }
    }

    // Check input errors before inserting in database
    if (empty($username_err) && empty($email_err) && empty($phone_err) && empty($role_err) &&
        empty($password_err) && empty($confirm_password_err) && empty($vehicle_err)) {

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $is_active = 1; // Staff accounts are active by default

        $conn->begin_transaction();

        try {
            // INSERT INTO users table
            $sql_user = "INSERT INTO users (username, email, phone_number, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, ?)";
            if ($stmt_user = $conn->prepare($sql_user)) {
                $stmt_user->bind_param("sssssi", $username, $email, $phone, $hashed_password, $role, $is_active);
                if (!$stmt_user->execute()) {
                    throw new Exception("Error registering user: " . $stmt_user->error);
                }
                $user_id = $conn->insert_id;
                $stmt_user->close();
            } else {
                throw new Exception("Error preparing user registration query: " . $conn->error);
            }

            // If delivery personnel, insert vehicle info into delivery_personnel_detail table
            if ($role === "delivery_personnel" && !empty($vehicle)) {
                $sql_vehicle = "INSERT INTO delivery_personnel_details (user_id, vehicle_type) VALUES (?, ?)";
                if ($stmt_vehicle = $conn->prepare($sql_vehicle)) {
                    $stmt_vehicle->bind_param("is", $user_id, $vehicle);
                    if (!$stmt_vehicle->execute()) {
                        throw new Exception("Error saving vehicle information: " . $stmt_vehicle->error);
                    }
                    $stmt_vehicle->close();
                } else {
                    throw new Exception("Error preparing vehicle insertion query: " . $conn->error);
                }
            }

            $conn->commit();
            $success_message = "Staff registration successful! The account is now active.";

            // Clear form data
            $username = $email = $phone = $role = $password = $confirm_password = $vehicle = "";

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Registration</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        /* CSS: Advanced Styling */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: #333;
            background-size: cover;
            background-position: center;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            /* Glassmorphism Effect */
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 3rem;
            border-radius: 20px;
            width: 100%;
            max-width: 450px;
            color: white;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 600;
            letter-spacing: 1px;
            color: #fff;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: #ddd;
        }

        /* Styling Inputs to look clean and transparent */
        input, select {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: white;
            font-size: 1rem;
            outline: none;
            transition: all 0.3s;
        }

        /* Placeholder color correction for dark background */
        ::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        /* Change dropdown option colors so they are readable on white */
        select option {
            background-color: #333;
            color: white;
        }

        input:focus, select:focus {
            background: rgba(255, 255, 255, 0.2);
            border-color: #ff7e5f;
            box-shadow: 0 0 10px rgba(255, 126, 95, 0.3);
        }

        /* Hidden section for vehicle type */
        #vehicle-section {
            display: none; /* Hidden by default */
            animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(to right, #ff7e5f, #feb47b);
            color: white;
            border: none;
            border-radius: 30px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 1rem;
        }

        button:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(255, 126, 95, 0.4);
        }

        .error-message {
            color: #ff6b6b;
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
        }
    </style>
</head>
<body>

    <div class="container">
        <h2>Join the Team</h2>

        <?php
        if (!empty($error_message)) {
            echo '<div style="color: #ff6b6b; background: rgba(255, 107, 107, 0.1); border: 1px solid #ff6b6b; padding: 10px; border-radius: 8px; margin-bottom: 20px; text-align: center;">' . htmlspecialchars($error_message) . '</div>';
        }
        if (!empty($success_message)) {
            echo '<div style="color: #4CAF50; background: rgba(76, 175, 80, 0.1); border: 1px solid #4CAF50; padding: 10px; border-radius: 8px; margin-bottom: 20px; text-align: center;">' . htmlspecialchars($success_message) . '</div>';
        }
        ?>

        <form id="registerForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="tewodrosEw" value="<?php echo htmlspecialchars($username); ?>" required>
                <?php if (!empty($username_err)) echo '<span class="error-message" style="display: block;">' . htmlspecialchars($username_err) . '</span>'; ?>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="tewodros@restaurant.com" value="<?php echo htmlspecialchars($email); ?>" required>
                <?php if (!empty($email_err)) echo '<span class="error-message" style="display: block;">' . htmlspecialchars($email_err) . '</span>'; ?>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" placeholder="+251 934 567 890" value="<?php echo htmlspecialchars($phone); ?>" required>
                <?php if (!empty($phone_err)) echo '<span class="error-message" style="display: block;">' . htmlspecialchars($phone_err) . '</span>'; ?>
            </div>

            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" required onchange="toggleVehicleField()">
                    <option value="" disabled <?php echo empty($role) ? 'selected' : ''; ?>>Select your role...</option>
                    <option value="manager" <?php echo (isset($_POST['role']) && $_POST['role'] === 'manager') ? 'selected' : ''; ?>>Restaurant Manager</option>
                    <option value="delivery" <?php echo (isset($_POST['role']) && $_POST['role'] === 'delivery') ? 'selected' : ''; ?>>Delivery Personnel</option>
                </select>
                <?php if (!empty($role_err)) echo '<span class="error-message" style="display: block;">' . htmlspecialchars($role_err) . '</span>'; ?>
            </div>

            <div class="form-group" id="vehicle-section" style="display: <?php echo (isset($_POST['role']) && $_POST['role'] === 'delivery') ? 'block' : 'none'; ?>;">
                <label for="vehicle">Vehicle Type</label>
                <select id="vehicle" name="vehicle" <?php echo (isset($_POST['role']) && $_POST['role'] === 'delivery') ? 'required' : ''; ?>>
                    <option value="" disabled <?php echo empty($vehicle) ? 'selected' : ''; ?>>Select vehicle type...</option>
                    <option value="bike" <?php echo (isset($_POST['vehicle']) && $_POST['vehicle'] === 'bike') ? 'selected' : ''; ?>>Motorbike</option>
                    <option value="bicycle" <?php echo (isset($_POST['vehicle']) && $_POST['vehicle'] === 'bicycle') ? 'selected' : ''; ?>>Bicycle</option>
                    <option value="car" <?php echo (isset($_POST['vehicle']) && $_POST['vehicle'] === 'car') ? 'selected' : ''; ?>>Car</option>
                </select>
                <?php if (!empty($vehicle_err)) echo '<span class="error-message" style="display: block;">' . htmlspecialchars($vehicle_err) . '</span>'; ?>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="••••••••" required>
                <?php if (!empty($password_err)) echo '<span class="error-message" style="display: block;">' . htmlspecialchars($password_err) . '</span>'; ?>
            </div>

            <div class="form-group">
                <label for="confirmPassword">Confirm Password</label>
                <input type="password" id="confirmPassword" name="confirmPassword" placeholder="••••••••" required>
                <?php if (!empty($confirm_password_err)) echo '<span class="error-message" style="display: block;">' . htmlspecialchars($confirm_password_err) . '</span>'; ?>
            </div>

            <button type="submit">Register Now</button>
        </form>
    </div>

    <script>
        // Handle Vehicle Field Logic
        function toggleVehicleField() {
            const roleSelect = document.getElementById('role');
            const vehicleSection = document.getElementById('vehicle-section');

            // If the selected value is 'delivery', show the section. Otherwise, hide it.
            if (roleSelect.value === 'delivery') {
                vehicleSection.style.display = 'block';
                document.getElementById('vehicle').setAttribute('required', 'true');
            } else {
                vehicleSection.style.display = 'none';
                document.getElementById('vehicle').removeAttribute('required');
            }
        }

        // Initialize vehicle field visibility on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleVehicleField();
        });
    </script>
</body>
</html>