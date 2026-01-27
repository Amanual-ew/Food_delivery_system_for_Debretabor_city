<?php
// Always start the session at the very beginning of the script
session_start();

// Include the database connection file
require_once 'config.php';

// Define variables and initialize with empty values
$username = $password = "";
$username_err = $password_err = "";
$login_error = ""; // For general login failures

// Check if the user is already logged in, if yes, redirect them to their dashboard
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    // Redirect based on role
    if (isset($_SESSION["role"])) {
        switch ($_SESSION["role"]) {
            case "customer":
                header("location: customer_dashboard.php");
                break;
            case "restaurant_manager":
                header("location: restaurant_manager_dashboard.php");
                break;
            case "delivery_personnel":
                header("location: delivery_personnel_dashboard.php");
                break;
            case "admin":
                header("location: admin_dashboard.php");
                break;
            default:
                header("location: welcome.php"); // Generic welcome for unhandled roles
                break;
        }
    } else {
        header("location: welcome.php"); // Default if role is somehow not set in session
    }
    exit; // Important to exit after header redirect
}

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate username
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter username.";
    } else {
        $username = trim($_POST["username"]);
    }

    // Validate password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }

    // Check input errors before attempting to log in
    if (empty($username_err) && empty($password_err)) {
        // Prepare a select statement
        // Added is_active check
        $sql = "SELECT user_id, username, password_hash, role, is_active FROM users WHERE username = ?";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $param_username);
            $param_username = $username;

            if ($stmt->execute()) {
                $stmt->store_result();

                // Check if username exists, if yes then verify password
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($user_id, $username, $hashed_password, $role, $is_active);
                    if ($stmt->fetch()) {
                        // Ensure the fetched hash is not null before verifying to satisfy type expectations
                        if ($hashed_password !== null && password_verify($password, (string)$hashed_password)) {
                            // Password is correct, now check if account is active
                            if ($is_active == 1) {
                                // Password and account are active, so start a new session
                                session_regenerate_id(); // Regenerate session ID for security
                                $_SESSION["loggedin"] = true;
                                $_SESSION["user_id"] = $user_id;
                                $_SESSION["username"] = $username;
                                $_SESSION["role"] = $role;

                                // Redirect user to appropriate dashboard based on role
                                switch ($role) {
                                    case "customer":
                                        header("location: customer_dashboard.php");
                                        break;
                                    case "restaurant_manager":
                                        header("location: restaurant_manager_dashboard.php");
                                        break;
                                    case "delivery_personnel":
                                        header("location: delivery_personnel_dashboard.php");
                                        break;
                                    case "admin":
                                        header("location: admin_dashboard.php");
                                        break;
                                    default:
                                        header("location: welcome.php"); // Fallback
                                        break;
                                }
                                exit; // Important to exit after header redirect
                            } else {
                                $login_error = "Your account is currently inactive. Please contact support.";
                            }
                        } else {
                            // Password is not valid
                            $login_error = "Invalid username or password.";
                        }
                    }
                } else {
                    // Username doesn't exist
                    $login_error = "Invalid username or password.";
                }
            } else {
                $login_error = "Oops! Something went wrong. Please try again later.";
            }
            $stmt->close();
        } else {
            $login_error = "Database query preparation failed: " . $conn->error;
        }
    }
    $conn->close(); // Close connection if no redirect happens
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        /* ... existing styles ... */
        header { position: relative; z-index: 1000; }
        .user-menu-container { position: relative; display: inline-block; }
        .user-icon { font-size: 1.5em; color: #fff; cursor: pointer; padding: 10px; transition: color 0.3s ease; }
        .user-icon:hover { color: #ff6f61; }
        .sidebar { position: absolute; top: 100%; right: 0; width: 150px; background-color: #fff; box-shadow: 0 4px 8px rgba(0,0,0,0.1); border-radius: 5px; padding: 10px; z-index: 999; visibility: hidden; opacity: 0; transform: translateY(-10px); transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease; }
        .user-menu-container:hover .sidebar { visibility: visible; opacity: 1; transform: translateY(0); }
        .sidebar a { display: block; padding: 8px 12px; text-decoration: none; color: #333; transition: background-color 0.3s ease; }
        .sidebar a:hover { background-color: #f1f1f1; }
        
        .login-form { background: white; padding: 30px; margin: 30px ; border-radius: 8px; width: 30%; box-shadow: 0 4px 10px rgba(242, 8, 8, 0.87); max-width: 400px; font-family: Arial, sans-serif; color: #333; position: relative; }
        .login-form h2 { text-align: center; color: #333; margin-bottom: 30px; }
        .login-form label { display: block; margin-bottom: 8px; font-weight: bold; color: #555; }
        .login-form input[type="text"], .login-form input[type="password"] { width: 120%; padding: 10px; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        .login-form input[type="submit"] { width: 80%; background-color: #ff6f61; color: white; padding: 12px 20px; display: center; border: none; border-radius: 5px; cursor: pointer; font-size: 1.1em; transition: background-color 0.3s ease; }
        .login-form input[type="submit"]:hover { background-color: #e65c50; }
        
        /* Message Styles */
        .error-message { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; margin-bottom: 20px; border-radius: 5px; text-align: center; }
        .success-message { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center; font-weight: bold; }
        main {
                padding-bottom: -500px; 
                background-size: cover; 
                background-repeat: no-repeat; 
                background-position: center; 
                display: flex; 
                justify-content: center; 
                align-items: center; 
                flex-direction: column; 
                /* Adjust the blur value as needed */
            }
    
        .login-form p.register-link { text-align: center; margin-top: 20px; }
        .login-form p.register-link a { color: #ff6f61; text-decoration: none; font-weight: bold; }
        @media (max-width: 768px) {
            .login-form { width: 80%; padding: 20px; }
            .login-form input[type="text"], .login-form input[type="password"] { width: 100%;
            
        }}
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1><?php echo h(__('app_name')); ?></h1>
            <nav>
                <ul>
                    <ul>
                    <li><a href="index.php" class="active-nav"><?php echo h(__('home')); ?></a></li>
                    <li><a href="restaurants.php"><?php echo h(__('restaurants')); ?></a></li>
                    <?php if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true): ?>
                       <li class="user-menu-container">
                            <i class="fa-solid fa-user user-icon"></i>
                            <div class="sidebar">
                                <a href="login.php"><?php echo h(__('login')); ?></a>
                                <a href="register.php"><?php echo h(__('register')); ?></a>
                            </div>
                        </li>
                </ul>
                    <?php else: ?>
                        <?php if (isset($_SESSION["role"])): ?>
                            <?php if ($_SESSION["role"] === "customer"): ?>
                                <li><a href="customer_dashboard.php"><?php echo h(__('customer_dashboard')); ?></a></li>
                                <li><a href="cart.php"><?php echo h(__('cart')); ?></a></li>
                            <?php elseif ($_SESSION["role"] === "restaurant_manager"): ?>
                                <li><a href="restaurant_manager_dashboard.php"><?php echo h(__('manager_dashboard')); ?></a></li>
                            <?php elseif ($_SESSION["role"] === "delivery_personnel"): ?>
                                <li><a href="delivery_personnel_dashboard.php"><?php echo h(__('delivery_dashboard')); ?></a></li>
                            <?php elseif ($_SESSION["role"] === "admin"): ?>
                                <li><a href="admin_dashboard.php"><?php echo h(__('admin_dashboard')); ?></a></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <li><a href="logout.php"><?php echo h(__('logout')); ?></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main class="login-sign">
        <section class="login-form">
            <h2>Login to Your Account</h2>

            <?php 
            if (isset($_SESSION['login_success_msg'])) {
                echo '<div class="success-message">' . htmlspecialchars($_SESSION['login_success_msg']) . '</div>';
                unset($_SESSION['login_success_msg']); // Clear the message so it doesn't repeat
            }
            ?>

            <?php 
            if (!empty($login_error)) {
                echo '<div class="error-message">' . $login_error . '</div>';
            }
            ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($username); ?>">
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <input type="submit" value="Login">
            </form>
            <p class="register-link">Don't have an account? <a href="register.php">Register here</a></p>
        </section>
    </main>

  <footer class="site-footer dark-footer" role="contentinfo" aria-label="Footer" style="background:#070707;color:#f3efe6;padding:48px 16px 28px;border-top:1px solid rgba(255,255,255,0.04);box-shadow:0 6px 18px rgba(0,0,0,0.6);">
        <style>
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
</body>
</html>