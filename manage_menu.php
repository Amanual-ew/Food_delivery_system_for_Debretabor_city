<?php
session_start();
require_once 'config.php';

// Check if the user is logged in AND if their role is 'restaurant_manager'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "restaurant_manager") {
    header("location: login.php"); // Redirect to login if not a restaurant manager
    exit;
}

$user_id = $_SESSION["user_id"];
$restaurant_id = null;
$restaurant_name = "";
$name = $description = $price = "";
$item_id_to_edit = null; // Used to pre-fill form for editing
$is_available = 1; // Default for adding new items
$current_image_path = ""; // To display current image in edit mode
$name_err = $description_err = $price_err = $image_err = "";
$success_message = "";
$error_message = "";

// Define the directory where menu item images will be saved
// This is relative to the manage_menu.php script itself
$upload_dir = 'uploads/menu_items/';
// Ensure the upload directory exists and is writable
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true); // Create directory with full permissions (for development, adjust for production)
}

// 1. Get the restaurant_id for the logged-in manager
$sql_get_restaurant_id = "SELECT restaurant_id, name FROM restaurants WHERE manager_id = ? AND is_active = 1";
if ($stmt_rest_id = $conn->prepare($sql_get_restaurant_id)) {
    $stmt_rest_id->bind_param("i", $user_id);
    if ($stmt_rest_id->execute()) {
        $result_rest_id = $stmt_rest_id->get_result();
        if ($result_rest_id->num_rows == 1) {
            $row = $result_rest_id->fetch_assoc();
            $restaurant_id = $row['restaurant_id'];
            $restaurant_name = $row['name'];
        } else {
            $error_message = "No active restaurant found for this manager. Please contact admin.";
        }
    } else {
        $error_message = "Error fetching restaurant ID: " . $stmt_rest_id->error;
    }
    $stmt_rest_id->close();
} else {
    $error_message = "Error preparing restaurant ID query: " . $conn->error;
}


// Proceed only if a restaurant_id is found for the manager
if ($restaurant_id) {

    // --- Handle Form Submissions (Add, Edit, Delete, Toggle Availability) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        if (isset($_POST['action'])) {
            $action = $_POST['action'];

            if ($action === 'add' || $action === 'edit') {
                // Validate common fields for add/edit
                if (empty(trim($_POST["name"]))) {
                    $name_err = "Please enter item name.";
                } else {
                    $name = trim($_POST["name"]);
                }

                if (empty(trim($_POST["description"]))) {
                    $description_err = "Please enter item description.";
                } else {
                    $description = trim($_POST["description"]);
                }

                if (empty(trim($_POST["price"]))) {
                    $price_err = "Please enter item price.";
                } elseif (!is_numeric(trim($_POST["price"])) || (float)trim($_POST["price"]) <= 0) {
                    $price_err = "Please enter a valid positive price.";
                } else {
                    $price = (float)trim($_POST["price"]);
                }
                
                $is_available = isset($_POST['is_available']) ? 1 : 0; // Checkbox value
                
                // For editing, get current image path if it's not being replaced
                if ($action === 'edit' && isset($_POST['item_id'])) {
                    $item_id_to_edit = (int)$_POST['item_id'];
                    $sql_get_current_image = "SELECT image_path FROM menu_items WHERE item_id = ? AND restaurant_id = ?";
                    if ($stmt_get_img = $conn->prepare($sql_get_current_image)) {
                        $stmt_get_img->bind_param("ii", $item_id_to_edit, $restaurant_id);
                        $stmt_get_img->execute();
                        $result_get_img = $stmt_get_img->get_result();
                        if ($result_get_img->num_rows > 0) {
                            $current_image_path_db = $result_get_img->fetch_assoc()['image_path'];
                            if (!empty($current_image_path_db)) {
                                $current_image_path = str_replace('\\', '/', $current_image_path_db); // Standardize slashes
                            }
                        }
                        $stmt_get_img->close();
                    }
                }


                // --- Handle Image Upload (for both add and edit) ---
                $uploaded_image_path = $current_image_path; // Start with current image path if editing
                if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] == UPLOAD_ERR_OK) {
                    $file_tmp_name = $_FILES['item_image']['tmp_name'];
                    $file_name = basename($_FILES['item_image']['name']);
                    $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp']; // Allowed image types

                    // Generate a unique file name to prevent overwrites
                    $new_file_name = uniqid('menu_item_', true) . '.' . $file_extension;
                    $destination = $upload_dir . $new_file_name;
                    $relative_db_path = str_replace('\\', '/', $destination); // Store with forward slashes

                    if (in_array($file_extension, $allowed_extensions)) {
                        if (move_uploaded_file($file_tmp_name, $destination)) {
                            $uploaded_image_path = $relative_db_path; // Use the new path

                            // If editing and a new image is uploaded, delete the old one
                            if ($action === 'edit' && !empty($current_image_path) && $current_image_path !== $uploaded_image_path) {
                                // FIX: Corrected path for unlink()
                                $old_file_full_path = __DIR__ . '/' . $current_image_path;
                                if (file_exists($old_file_full_path) && is_file($old_file_full_path)) {
                                    unlink($old_file_full_path); // Delete the old image file
                                }
                            }
                        } else {
                            $image_err = "Failed to upload image.";
                        }
                    } else {
                        $image_err = "Invalid file type. Only JPG, JPEG, PNG, GIF, WEBP are allowed.";
                    }
                } elseif ($action === 'add' && empty($_FILES['item_image']['name'])) {
                    // For 'add' action, allow no image to be uploaded (it will remain NULL in DB)
                    // You could uncomment this if image is mandatory for new items:
                    // $image_err = "Please upload an image for the menu item.";
                }
                // If in edit mode and no new image uploaded, $uploaded_image_path retains $current_image_path


                if (empty($name_err) && empty($description_err) && empty($price_err) && empty($image_err)) {
                    if ($action === 'add') {
                        $sql = "INSERT INTO menu_items (restaurant_id, name, description, price, is_available, image_path) VALUES (?, ?, ?, ?, ?, ?)";
                        if ($stmt = $conn->prepare($sql)) {
                            // Bind image_path as a string (s)
                            $stmt->bind_param("isssis", $restaurant_id, $name, $description, $price, $is_available, $uploaded_image_path);
                            if ($stmt->execute()) {
                                $success_message = "Menu item '" . htmlspecialchars($name) . "' added successfully.";
                                // Clear form fields after successful add
                                $name = $description = $price = $current_image_path = "";
                                $is_available = 1; // Reset to default
                            } else {
                                $error_message = "Error adding menu item: " . $stmt->error;
                            }
                            $stmt->close();
                        } else {
                            $error_message = "Error preparing add query: " . $conn->error;
                        }
                    } elseif ($action === 'edit' && isset($_POST['item_id'])) {
                        $item_id = (int)$_POST['item_id'];
                        $sql = "UPDATE menu_items SET name = ?, description = ?, price = ?, is_available = ?, image_path = ? WHERE item_id = ? AND restaurant_id = ?";
                        if ($stmt = $conn->prepare($sql)) {
                            // FIX: Corrected type definition string to match 7 variables.
                            // 's' for name, 's' for description, 'd' for price (double/float), 'i' for is_available,
                            // 's' for uploaded_image_path, 'i' for item_id, 'i' for restaurant_id
                            $stmt->bind_param("ssdisii", $name, $description, $price, $is_available, $uploaded_image_path, $item_id, $restaurant_id);
                            if ($stmt->execute()) {
                                $success_message = "Menu item '" . htmlspecialchars($name) . "' updated successfully.";
                                $item_id_to_edit = null; // Clear edit mode
                                // Don't clear form fields, as they're reloaded based on new data
                                header("Location: manage_menu.php?success=" . urlencode($success_message)); // Redirect to clear POST data
                                exit();
                            } else {
                                $error_message = "Error updating menu item: " . $stmt->error;
                            }
                            $stmt->close();
                        } else {
                            $error_message = "Error preparing update query: " . $conn->error;
                        }
                    }
                }
            } elseif ($action === 'delete' && isset($_POST['item_id'])) {
                $item_id = (int)$_POST['item_id'];
                
                // Get image path before deleting the item from DB
                $sql_get_image_to_delete = "SELECT image_path FROM menu_items WHERE item_id = ? AND restaurant_id = ?";
                if ($stmt_del_img = $conn->prepare($sql_get_image_to_delete)) {
                    $stmt_del_img->bind_param("ii", $item_id, $restaurant_id);
                    $stmt_del_img->execute();
                    $result_del_img = $stmt_del_img->get_result();
                    $image_to_delete = $result_del_img->fetch_assoc();
                    $stmt_del_img->close();

                    if ($image_to_delete && !empty($image_to_delete['image_path'])) {
                        // FIX: Corrected path for unlink()
                        $full_path = __DIR__ . '/' . str_replace('\\', '/', $image_to_delete['image_path']);
                        if (file_exists($full_path) && is_file($full_path)) {
                            unlink($full_path); // Delete the actual file
                        }
                    }
                }

                $sql = "DELETE FROM menu_items WHERE item_id = ? AND restaurant_id = ?";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("ii", $item_id, $restaurant_id);
                    if ($stmt->execute()) {
                        $success_message = "Menu item deleted successfully.";
                    } else {
                        $error_message = "Error deleting menu item: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Error preparing delete query: " . $conn->error;
                }
            } elseif ($action === 'toggle_availability' && isset($_POST['item_id'])) {
                $item_id = (int)$_POST['item_id'];
                $current_status = (int)$_POST['current_status'];
                $new_status = $current_status == 1 ? 0 : 1; // Toggle status
                $sql = "UPDATE menu_items SET is_available = ? WHERE item_id = ? AND restaurant_id = ?";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("iii", $new_status, $item_id, $restaurant_id);
                    if ($stmt->execute()) {
                        $success_message = "Item availability updated.";
                    } else {
                        $error_message = "Error updating item availability: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Error preparing toggle availability query: " . $conn->error;
                }
            }
        }
        // Redirect to clear POST data and prevent re-submission on refresh
        header("Location: manage_menu.php?success=" . urlencode($success_message) . "&error=" . urlencode($error_message));
        exit();
    }

    // --- Handle Edit Mode Request (GET request) ---
    // This block should run AFTER POST handling, and populate the form for editing
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['item_id'])) {
        $item_id_to_edit = (int)$_GET['item_id'];
        $sql_fetch_item = "SELECT name, description, price, is_available, image_path FROM menu_items WHERE item_id = ? AND restaurant_id = ?";
        if ($stmt_fetch = $conn->prepare($sql_fetch_item)) {
            $stmt_fetch->bind_param("ii", $item_id_to_edit, $restaurant_id);
            if ($stmt_fetch->execute()) {
                $result_fetch = $stmt_fetch->get_result();
                if ($result_fetch->num_rows == 1) {
                    $item_data = $result_fetch->fetch_assoc();
                    $name = $item_data['name'];
                    $description = $item_data['description'];
                    $price = $item_data['price'];
                    $is_available = $item_data['is_available'];
                    if (!empty($item_data['image_path'])) {
                        $current_image_path = str_replace('\\', '/', $item_data['image_path']); // Standardize for display
                    }
                } else {
                    $error_message = "Menu item not found for editing.";
                    $item_id_to_edit = null; // Clear edit mode
                }
            } else {
                $error_message = "Error fetching item for edit: " . $stmt_fetch->error;
            }
            $stmt_fetch->close();
        } else {
            $error_message = "Error preparing item fetch for edit query: " . $conn->error;
        }
    }
    // Check for messages passed via GET after a redirect
    if (isset($_GET['success'])) {
        $success_message = htmlspecialchars($_GET['success']);
    }
    if (isset($_GET['error'])) {
        $error_message = htmlspecialchars($_GET['error']);
    }

    // --- Fetch all menu items for the manager's restaurant ---
    $menu_items = [];
    // Added image_path to the select query to display it in the table
    $sql_fetch_menu = "SELECT item_id, name, description, price, is_available, image_path FROM menu_items WHERE restaurant_id = ? ORDER BY name";
    if ($stmt_fetch_menu = $conn->prepare($sql_fetch_menu)) {
        $stmt_fetch_menu->bind_param("i", $restaurant_id);
        if ($stmt_fetch_menu->execute()) {
            $result_fetch_menu = $stmt_fetch_menu->get_result();
            while ($row = $result_fetch_menu->fetch_assoc()) {
                $menu_items[] = $row;
            }
            $result_fetch_menu->free();
        } else {
            $error_message = "Error fetching current menu items: " . $stmt_fetch_menu->error;
        }
        $stmt_fetch_menu->close();
    } else {
        $error_message = "Error preparing fetch menu items query: " . $conn->error;
    }
}
$conn->close(); // Close the database connection
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Menu - <?php echo htmlspecialchars($restaurant_name); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons (Edit/Delete/Toggle buttons) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        /* ======================================================= */
        /* === HOVER SIDEBAR STYLING (For header user icon) === */
        /* ======================================================= */
        header {
            position: relative;
            z-index: 1000;
        }
        .user-menu-container {
            position: relative;
            display: inline-block;
        }
        .user-icon {
            font-size: 1.5em;
            color: #fff; /* White icon color */
            cursor: pointer;
            padding: 10px;
            transition: color 0.3s ease;
        }
        .user-icon:hover {
            color: #ff6f61; /* Hover color for contrast */
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
        /* === MANAGER DASHBOARD HEADER FIXES (copied from dashboard) === */
        /* ======================================================= */
        header h1 {
            float: left;
            text-align: left;
        }
        header nav ul {
            display: block;
            float: right;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        header nav ul li {
            display: inline;
            margin-left: 20px;
        }
        /* Style for the "Hello, Manager!" text */
        header nav ul li:first-child {
            color: #0b0101ff;
            font-weight: bold;
        }

        /* ======================================================= */
        /* === MENU MANAGEMENT SPECIFIC STYLING === */
        /* ======================================================= */
        .manage-menu-content {
            padding: 40px 20px;
        }

        .menu-form-section {
            background-color: #fff;
            padding: 30px;
            margin-bottom: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .menu-form-section h2 {
            text-align: center;
            color: #ff6f61;
            margin-bottom: 30px;
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
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"],
        input[type="number"],
        textarea {
            width: calc(100% - 22px);
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
        }
        input[type="file"] {
            width: calc(100% - 22px);
            padding: 8px; /* Slightly less padding for file input */
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
            background-color: #f8f8f8;
            cursor: pointer;
        }
        .current-image-preview {
            margin-top: 10px;
            text-align: center;
        }
        .current-image-preview img {
            max-width: 150px;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .current-image-preview p {
            font-size: 0.9em;
            color: #777;
            margin-top: 5px;
        }


        textarea {
            resize: vertical;
            min-height: 80px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: -10px; /* Adjust spacing with previous input */
            margin-bottom: 15px;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto; /* Override full width */
            margin-bottom: 0;
        }
        input[type="submit"] {
            width: 100%;
            background-color: #28a745;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
        }
        input[type="submit"]:hover {
            background-color: #218838;
        }
        .btn-cancel-edit {
            display: inline-block;
            background-color: #6c757d;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.9em;
            margin-top: 10px;
            transition: background-color 0.3s ease;
        }
        .btn-cancel-edit:hover {
            background-color: #5a6268;
        }

        .error {
            color: red;
            font-size: 0.8em;
            margin-top: -15px;
            margin-bottom: 10px;
            display: block;
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

        .menu-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 40px;
            background-color: #fff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden; /* For rounded corners on table */
        }
        .menu-items-table th, .menu-items-table td {
            border: 1px solid #ddd;
            padding: 12px 15px;
            text-align: left;
            vertical-align: middle;
        }
        .menu-items-table th {
            background-color: #f2f2f2;
            font-weight: bold;
            color: #555;
            text-transform: uppercase;
            font-size: 0.9em;
        }
        .menu-items-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .menu-items-table tbody tr:hover {
            background-color: #f1f1f1;
        }
        .menu-items-table .item-image-thumb {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #eee;
        }
        .menu-items-table .actions {
            white-space: nowrap; /* Keep buttons on one line */
        }
        .actions{
            display: flex;
            gap: 5px;
            align-items: center;
            padding-left: 0px;
        }
        .menu-items-table .actions button,
        .menu-items-table .actions a.btn {
            padding: 8px 12px;
            margin-right: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
            font-size: 0.9em;
            transition: opacity 0.3s ease;
            text-decoration: none; /* For link buttons */
            display: inline-block; /* Ensure padding/margin work */
        }
        .menu-items-table .actions .btn-edit { background-color: #007bff; }
        .menu-items-table .actions .btn-delete { background-color: #dc3545; }
        .menu-items-table .actions .btn-toggle-active { background-color: #28a745; } /* Green for active */
        .menu-items-table .actions .btn-toggle-inactive { background-color: #ffc107; color: #333;} /* Yellow for inactive */
        .menu-items-table .actions button:hover,
        .menu-items-table .actions a.btn:hover {
            opacity: 0.8;
        }

        .no-items-message {
            text-align: center;
            margin-top: 20px;
            font-size: 1.1em;
            color: #777;
        }

        /* Responsive table */
        @media (max-width: 768px) {
           
            .menu-items-table, .menu-items-table tbody, .menu-items-table tr, .menu-items-table td {
                display: block;
                font: 1em sans-serif;
                width: auto;
                margin: 0;
                padding: 5px;

            }
            .menu-items-table tr {
                margin-bottom: 15px;

            }
            .menu-items-table thead {
                display: none;
            }
            .menu-items-table tr {
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .menu-items-table td {
                text-align: right;
                padding-left: 50%;
                position: relative;
                border: none;
                border-bottom: 1px dashed #eee;
            }
            .menu-items-table td:last-child {
                border-bottom: none;
                padding: 7px;
            }
            .menu-items-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 30px);
                white-space: nowrap;
                font-weight: bold;
                color: #555;
            }
            .form-row {
                flex-direction: column;
            }
            .form-row > div {
                width: 100%;
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
                    <li>Hello, <?php echo htmlspecialchars($_SESSION["username"]); ?> (Manager)!</li>
                    <!-- Restricted navigation for Restaurant Manager -->
                    <li><a href="manage_menu.php">Manage Menu</a></li>
                    <li><a href="manage_orders.php">Orders</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="container manage-menu-content">
            <?php if ($restaurant_id): ?>
                <h2>Manage Menu for <?php echo htmlspecialchars($restaurant_name); ?></h2>
                <p>Add, edit, or remove menu items for your restaurant below.</p>

                <?php if (!empty($success_message)) { echo '<div class="message">' . $success_message . '</div>'; } ?>
                <?php if (!empty($error_message)) { echo '<div class="error-message">' . $error_message . '</div>'; } ?>

                <div class="menu-form-section">
                    <h2><?php echo $item_id_to_edit ? 'Edit Menu Item' : 'Add New Menu Item'; ?></h2>
                    <form action="manage_menu.php" method="POST" enctype="multipart/form-data">
                        <?php if ($item_id_to_edit): ?>
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($item_id_to_edit); ?>">
                        <?php else: ?>
                            <input type="hidden" name="action" value="add">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="name">Item Name:</label>
                            <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($name); ?>">
                            <span class="error"><?php echo $name_err; ?></span>
                        </div>

                        <div class="form-group">
                            <label for="description">Description:</label>
                            <textarea id="description" name="description" rows="3" required><?php echo htmlspecialchars($description); ?></textarea>
                            <span class="error"><?php echo $description_err; ?></span>
                        </div>

                        <div class="form-group">
                            <label for="price">Price (ETB):</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" required value="<?php echo htmlspecialchars($price); ?>">
                            <span class="error"><?php echo $price_err; ?></span>
                        </div>

                        <div class="form-group">
                            <label for="item_image">Item Image (JPG, PNG, GIF, WEBP - Optional for Add, Replace for Edit):</label>
                            <input type="file" id="item_image" name="item_image" accept="image/jpeg,image/png,image/gif,image/webp">
                            <span class="error"><?php echo $image_err; ?></span>
                            <?php if ($item_id_to_edit && !empty($current_image_path)): ?>
                                <div class="current-image-preview">
                                    <p>Current Image:</p>
                                    <img src="<?php echo htmlspecialchars($base_image_url . $current_image_path); ?>" alt="Current Item Image">
                                </div>
                            <?php elseif ($item_id_to_edit && empty($current_image_path)): ?>
                                <div class="current-image-preview">
                                    <p>No current image. Upload a new one.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group checkbox-group">
                            <input type="checkbox" id="is_available" name="is_available" value="1" <?php echo ($is_available == 1) ? 'checked' : ''; ?>>
                            <label for="is_available">Available for Order</label>
                        </div>

                        <input type="submit" value="<?php echo $item_id_to_edit ? 'Update Menu Item' : 'Add Menu Item'; ?>">
                        <?php if ($item_id_to_edit): ?>
                            <a href="manage_menu.php" class="btn-cancel-edit">Cancel Edit</a>
                        <?php endif; ?>
                    </form>
                </div>

                <h3>Current Menu Items</h3>
                <?php if (!empty($menu_items)): ?>
                    <table class="menu-items-table">
                        <thead>
                            <tr>
                                <th>Image</th> <!-- New column for image thumbnail -->
                                <th>Name</th>
                                <th>Description</th>
                                <th>Price</th>
                                <th>Available</th>
                                <th class="actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menu_items as $item): ?>
                                <tr>
                                    <td data-label="Image">
                                        <?php
                                        $display_image_path = 'https://placehold.co/50x50/cccccc/333333?text=No+Img';
                                        if (!empty($item['image_path'])) {
                                            $cleaned_path = str_replace('\\', '/', $item['image_path']);
                                            // FIX: Corrected path for file_exists() check for displaying in table
                                            // This now correctly points to uploads/menu_items/ within your project folder
                                            if (file_exists(__DIR__ . '/' . $cleaned_path)) {
                                                $display_image_path = htmlspecialchars($cleaned_path);
                                            }
                                        }
                                        ?>
                                        <img src="<?php echo $display_image_path; ?>" alt="Item Image" class="item-image-thumb">
                                    </td>
                                    <td data-label="Name"><?php echo htmlspecialchars($item['name']); ?></td>
                                    <td data-label="Description"><?php echo htmlspecialchars($item['description']); ?></td>
                                    <td data-label="Price">ETB <?php echo number_format($item['price'], 2); ?></td>
                                    <td data-label="Available">
                                        <?php echo $item['is_available'] == 1 ? '<span style="color: green; font-weight: bold;">Yes</span>' : '<span style="color: red; font-weight: bold;">No</span>'; ?>
                                    </td>
                                    <td class="actions">
                                        <a href="manage_menu.php?action=edit&item_id=<?php echo htmlspecialchars($item['item_id']); ?>" class="btn btn-edit"><i class="fas fa-edit"></i> Edit</a>
                                        <form action="manage_menu.php" method="POST" style="display: inline-block;">
                                            <input type="hidden" name="action" value="toggle_availability">
                                            <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($item['item_id']); ?>">
                                            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($item['is_available']); ?>">
                                            <button type="submit" class="btn <?php echo $item['is_available'] == 1 ? 'btn-toggle-inactive' : 'btn-toggle-active'; ?>">
                                                <i class="fas fa-toggle-<?php echo $item['is_available'] == 1 ? 'off' : 'on'; ?>"></i> <?php echo $item['is_available'] == 1 ? 'Set Inactive' : 'Set Active'; ?>
                                            </button>
                                        </form>
                                        <form action="manage_menu.php" method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this menu item? This will also delete its image.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($item['item_id']); ?>">
                                            <button type="submit" class="btn btn-delete"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="no-items-message">No menu items added yet. Use the form above to add your first item!</p>
                <?php endif; ?>

            <?php else: ?>
                <div class="error-message">
                    <h3>Restaurant Assignment Error</h3>
                    <p>It seems your manager account is not assigned to an active restaurant, or there was an error fetching your restaurant details.</p>
                    <p>Please contact the system administrator to assign your account to a restaurant.</p>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Debre Tabor Food Delivery. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
