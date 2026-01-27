<?php
session_start();

// Check if the user is logged in, otherwise redirect to login page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - Debre Tabor Food Delivery</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <header>
        <div class="container">
            <h1>Welcome to Debre Tabor Food Delivery</h1>
            <nav>
                <ul>
                    <li>Hello, <?php echo htmlspecialchars($_SESSION["username"]); ?> (<?php echo htmlspecialchars($_SESSION["role"]); ?>)!</li>
                    <li><a href="restaurants.php">Browse Restaurants</a></li>
                    <li><a href="logout.php">Logout</a></li>
                    <li><a href="cart.php">Cart</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <h2>Welcome!</h2>
            <p>You have successfully logged in as a <?php echo htmlspecialchars($_SESSION["role"]); ?>.</p>
            <p>Explore the site or proceed to your specific dashboard.</p>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>