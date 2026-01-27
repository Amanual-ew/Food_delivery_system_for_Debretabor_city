<?php
// Start the session to access verification data
session_start();

// Include your database connection
require_once 'config.php';


// 1. Redirect if the user didn't come from the registration page
if (!isset($_SESSION['verification_email'])) {
    header("location: register.php");
    exit;
}

$email = $_SESSION['verification_email'];
$message = ""; // Stores the text for our alerts
$message_type = ""; // 'success' for green, 'danger' for red

// 2. Process the form when the "Verify" button is clicked
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify'])) {
    $user_otp = trim($_POST["otp"]);

    if (empty($user_otp)) {
        $message = "Please enter the 6-digit code.";
        $message_type = "danger";
    } else {
        // 3. Check database using 'user_id' (fixing the previous error)
        // We look for the email and code where the account is not yet active
        $sql = "SELECT user_id FROM users WHERE email = ? AND verification_token = ? AND is_active = 0";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ss", $email, $user_otp);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows == 1) {
                // SUCCESS: Match found!
                $stmt->close();
                
                // 4. Update the user to 'is_active = 1' and clear the token
                $update_sql = "UPDATE users SET is_active = 1, verification_token = NULL WHERE email = ?";
                
                if ($update_stmt = $conn->prepare($update_sql)) {
                    $update_stmt->bind_param("s", $email);
                    if ($update_stmt->execute()) {
                        // 5. Create a success session message for login.php
                        $_SESSION['login_success_msg'] = "Account successfully verified! You can now login.";
                        
                        // Clear the verification session
                        unset($_SESSION['verification_email']);
                        
                        // Redirect to login page
                        header("location: login.php");
                        exit;
                    }
                }
            } else {
                // ERROR: Code is wrong or has expired
                $message = "The code you entered is incorrect or has expired. Please check your email again.";
                $message_type = "danger";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .verification-card { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); width: 100%; max-width: 450px; text-align: center; }
        .otp-input { font-size: 32px; font-weight: bold; letter-spacing: 10px; text-align: center; border: 2px solid #ddd; border-radius: 8px; margin-bottom: 20px; }
        .btn-verify { background-color: #ff6f61; color: white; border: none; padding: 12px; font-size: 18px; width: 100%; transition: 0.3s; }
        .btn-verify:hover { background-color: #e65b50; }
        .brand-name { color: #ff6f61; font-weight: bold; margin-bottom: 20px; }
    </style>
</head>
<body>
    

<div class="verification-card">
    <h2 class="brand-name">Debre Tabor Delivery</h2>
    <h4>Verify Your Email</h4>
    <p class="text-muted">A 6-digit code was sent to <br><strong><?php echo htmlspecialchars($email); ?></strong></p>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form action="verify_otp.php" method="POST">
        <div class="mb-3">
            <input type="text" name="otp" class="form-control otp-input" maxlength="6" placeholder="000000" autocomplete="off" required autofocus>
        </div>
        <button type="submit" name="verify" class="btn btn-verify">Verify & Activate</button>
    </form>

    <p class="mt-4 text-muted">Didn't get the code? <br>
        <small>Check your <strong>Spam folder</strong> or wait 2 minutes.</small>
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>