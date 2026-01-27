<?php
// Always start the session at the very beginning of the script
session_start();

// Include the database connection file
require_once 'config.php';
// Include the new file with the email function for SMTP sending
require_once 'send_otp_email.php';

// Define variables and initialize with empty values
$username = $email = $phone_number = $address = $password = $confirm_password = "";
// Hardcode role to customer
$role = "customer"; 

$username_err = $email_err = $phone_number_err = $address_err = $password_err = $confirm_password_err = "";
$success_message = $error_message = "";

// Check if the user is already logged in
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
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
                header("location: welcome.php");
                break;
        }
    } else {
        header("location: welcome.php");
    }
    exit;
}

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
                echo "<p class='error-message'>Oops! Something went wrong checking username. Please try again later.</p>";
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
                echo "<p class='error-message'>Oops! Something went wrong checking email. Please try again later.</p>";
            }
            $stmt->close();
        }
    }

    // 3. Validate Password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
    } else {
        $password = trim($_POST["password"]);
    }

    // 4. Validate Confirm Password
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Please confirm password.";
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Password did not match.";
        }
    }

    // 5. Validate Phone & Address
    if (empty(trim($_POST["phone_number"]))) {
        $phone_number_err = "Please enter your phone number.";
    } elseif (!preg_match("/^[0-9]{10,15}$/", trim($_POST["phone_number"]))) {
        $phone_number_err = "Please enter a valid phone number (10-15 digits).";
    } else {
        $phone_number = trim($_POST["phone_number"]);
    }

    if (empty(trim($_POST["address"]))) {
        $address_err = "Please enter your address.";
    } else {
        $address = trim($_POST["address"]);
    }

    // Check input errors before inserting in database
    if (empty($username_err) && empty($email_err) && empty($phone_number_err) && empty($address_err) &&
        empty($password_err) && empty($confirm_password_err)) {

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // --- OTP GENERATION ---
        $otp_code = rand(100000, 999999);
        $is_active = 0; 

        $conn->begin_transaction(); // Start transaction

        try {
            // INSERT INTO users table with verification_token and is_active = 0
            $sql_user = "INSERT INTO users (username, email, phone_number, address, password_hash, role, is_active, verification_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            if ($stmt_user = $conn->prepare($sql_user)) {
                $stmt_user->bind_param("ssssssis", $username, $email, $phone_number, $address, $hashed_password, $role, $is_active, $otp_code);
                if (!$stmt_user->execute()) {
                    throw new Exception("Error registering user: " . $stmt_user->error);
                }
                $stmt_user->close();
            } else {
                throw new Exception("Error preparing user registration query: " . $conn->error);
            }

            // =======================================================
            // === EMAIL SENDING LOGIC (Using PHPMailer Function) ===
            // =======================================================
            
            // Call the function from the included file (send_otp_email.php)
            $email_sent = send_otp_email($email, $username, $otp_code);
            
            if (!$email_sent) {
                // If the email fails to send due to SMTP configuration errors, log it and set an error message.
                // We still commit the transaction so the user can potentially use the 'Resend OTP' feature later,
                // or if the SMTP config is fixed later.
                $error_message = "Registration successful, but the verification email could not be sent. Please check your email and spam folders, or try again later. (Error logged)";
            }

            $conn->commit(); // Commit transaction
            
            // Store email in session to pass to the verification page
            $_SESSION['verification_email'] = $email;

            // Redirect to OTP entry page immediately
            header("location: verify_otp.php");
            exit;

        } catch (Exception $e) {
            $conn->rollback(); // Rollback on database error
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
    <title>Register - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        /* [Styling omitted for brevity] */
        /* ======================================================= */
        /* === HEADER STYLING (Standard for all pages) === */
        /* ======================================================= */
        
        /* User Menu for logged out state */
        .user-menu-container {
            position: relative;
            display: inline-block;
        }
        .user-icon {
            font-size: 1.5em;
            color:#fff;
            cursor: pointer;
            padding: 10px;
            transition: color 0.3s ease;
        }
        .user-icon:hover {
            color: #e65c50;
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


        /* ======================================================= */
        /* === FORM STYLING (Specific to this page) === */
        /* ======================================================= */
        .register-form {
            background-color: #fff;
            padding: 40px;
            margin: 50px auto;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(185, 12, 12, 0.99);
            max-width: 600px;
        }

        .register-form h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }

        .register-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }

        .register-form input[type="text"],
        .register-form input[type="email"],
        .register-form input[type="password"],
        .register-form input[type="tel"],
        .register-form textarea {
            width: calc(100% - 22px);
            padding: 10px;
            margin: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
        }

        .register-form input[type="submit"] {
            width: 70%;
            background-color: #ff6f61;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
            display: block;
            margin: 20px auto 0 auto;
        }

        .register-form input[type="submit"]:hover {
            background-color: #e65c50;
        }

        .register-form .error-message {
            color: red;
            font-size: 0.9em;
            text-align: center;
            margin-bottom: 15px;
        }

        .register-form .error {
            color: red;
            font-size: 0.8em;
            margin-top: -5px; 
            margin-bottom: 10px;
            display: block;
            margin-left: 5px;
        }
        .register-form p.login-link {
            text-align: center;
            margin-top: 20px;
        }
        .register-form p.login-link a {
            color: #ff6f61;
            text-decoration: none;
            font-weight: bold;
        }
        .register-form p.login-link a:hover {
            text-decoration: underline;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .form-row > div {
            flex: 1;
        }
        
        /* Section Dividers */
        .form-section-title {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-top: 20px;
            margin-bottom: 20px;
            color: #ff6f61;
        }
        main {
            height: 160vh;
            width: 100%;
            padding: 60px 0;
            margin: 0;
            background-size: cover;
            background-repeat: no-repeat;
            background-position: center center;
            background-attachment: fixed; /* stays fixed on scroll */
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        @media (max-width: 600px) {
            .form-row {
                flex-direction: column;
            }
            .register-form{
               width: 70%; 
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
                    <?php else: ?>
                      <li><a href="logout.php"><?php echo h(__('logout')); ?></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="register-form">
            <h2><?php echo h(__('customer_registration')); ?></h2>
            <?php
            if (!empty($error_message)) {
                echo '<div class="error-message">A system error occurred: ' . $error_message . '</div>';
            }
            // Display general errors if any
            if (!empty($username_err) || !empty($email_err) || !empty($phone_number_err) || !empty($address_err) ||
                !empty($password_err) || !empty($confirm_password_err)) {
                echo '<div class="error-message">Please check the form for errors.</div>';
            }
            ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">

                <h3 class="form-section-title"><?php echo h(__('account_details')); ?></h3>

                <div class="form-group">
                    <label for="username"><?php echo h(__('username')); ?>:</label>
                    <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($username); ?>">
                    <?php if (!empty($username_err)) echo '<span class="error">' . $username_err . '</span>'; ?>
                </div>

                <div class="form-group">
                    <label for="email"><?php echo h(__('email')); ?>:</label>
                    <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($email); ?>">
                    <?php if (!empty($email_err)) echo '<span class="error">' . $email_err . '</span>'; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password"><?php echo h(__('password')); ?>:</label>
                        <input type="password" id="password" name="password" required>
                        <?php if (!empty($password_err)) echo '<span class="error">' . $password_err . '</span>'; ?>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password"><?php echo h(__('confirm_password')); ?>:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                        <?php if (!empty($confirm_password_err)) echo '<span class="error">' . $confirm_password_err . '</span>'; ?>
                    </div>
                </div>

                <h3 class="form-section-title"><?php echo h(__('contact_information')); ?></h3>

                <div class="form-group">
                    <label for="phone_number"><?php echo h(__('phone_number')); ?>:</label>
                    <input type="tel" id="phone_number" name="phone_number" required value="<?php echo htmlspecialchars($phone_number); ?>">
                    <?php if (!empty($phone_number_err)) echo '<span class="error">' . $phone_number_err . '</span>'; ?>
                </div>

                <div class="form-group">
                    <label for="address"><?php echo h(__('address')); ?>:</label>
                    <textarea id="address" name="address" rows="3" required><?php echo htmlspecialchars($address); ?></textarea>
                    <?php if (!empty($address_err)) echo '<span class="error">' . $address_err . '</span>'; ?>
                </div>

                <input type="submit" value="<?php echo h(__('register')); ?>">
            </form>
            <p class="login-link"><?php echo h(__('Have_u_registered_before?,please'));?><a href="login.php"><?php echo h(__('login')); ?></a></p>
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