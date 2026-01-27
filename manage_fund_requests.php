<?php
session_start();
require_once 'config.php';

// Check if the user is logged in AND if their role is 'admin'
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== "admin") {
    header("location: login.php"); // Redirect to login if not an admin
    exit;
}

$admin_user_id = $_SESSION["user_id"];
$success_message = "";
$error_message = "";

// Define the base URL for uploaded screenshots (adjust if your web server path is different)
// Ensure your 'uploads/fund_proofs/' directory exists and is writable by the web server!
$base_upload_url = '';


// --- Handle Fund Request Actions (Accept/Decline) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && isset($_POST['request_id'])) {
    $request_id = (int)$_POST['request_id'];
    $action = $_POST['action']; // 'accept' or 'decline'
    // Admin notes are now optional and submitted with each action
    $admin_notes = isset($_POST['admin_notes']) ? trim($_POST['admin_notes']) : '';

    // Fetch the request details to ensure it's still pending and valid
    $sql_fetch_request = "SELECT user_id, amount, status FROM fund_requests WHERE request_id = ?";
    if ($stmt_fetch = $conn->prepare($sql_fetch_request)) {
        $stmt_fetch->bind_param("i", $request_id);
        $stmt_fetch->execute();
        $result_fetch = $stmt_fetch->get_result();
        $request_data = $result_fetch->fetch_assoc();
        $stmt_fetch->close();

        if ($request_data && $request_data['status'] === 'pending') {
            $conn->begin_transaction(); // Start transaction for atomic updates

            try {
                // Update fund_requests table
                // Note: processed_by_admin_id and processed_date are kept in DB but not displayed in table
                $sql_update_request = "UPDATE fund_requests SET status = ?, admin_notes = ?, processed_by_admin_id = ?, processed_date = NOW() WHERE request_id = ?";
                if ($stmt_update = $conn->prepare($sql_update_request)) {
                    $new_status = ($action === 'accept') ? 'accepted' : 'declined';
                    $stmt_update->bind_param("ssii", $new_status, $admin_notes, $admin_user_id, $request_id);
                    if (!$stmt_update->execute()) {
                        throw new Exception("Error updating fund request status: " . $stmt_update->error);
                    }
                    $stmt_update->close();
                } else {
                    throw new Exception("Error preparing update fund request query: " . $conn->error);
                }

                if ($action === 'accept') {
                    // If accepted, add amount to user's virtual_balance
                    $customer_user_id = $request_data['user_id'];
                    $amount_to_add = $request_data['amount'];

                    $sql_update_user_balance = "UPDATE users SET virtual_balance = virtual_balance + ? WHERE user_id = ?";
                    if ($stmt_balance = $conn->prepare($sql_update_user_balance)) {
                        $stmt_balance->bind_param("di", $amount_to_add, $customer_user_id);
                        if (!$stmt_balance->execute()) {
                            throw new Exception("Error updating user virtual balance: " . $stmt_balance->error);
                        }
                        $stmt_balance->close();
                    } else {
                        throw new Exception("Error preparing update user balance query: " . $conn->error);
                    }
                    $success_message = "Fund request #{$request_id} from user ID {$customer_user_id} ACCEPTED. Balance updated.";
                } else {
                    $success_message = "Fund request #{$request_id} from user ID {$request_data['user_id']} DECLINED.";
                }

                $conn->commit(); // Commit the transaction
            } catch (Exception $e) {
                $conn->rollback(); // Rollback on any error
                $error_message = "Transaction failed for request #{$request_id}: " . $e->getMessage();
            }
        } else {
            $error_message = "Fund request #{$request_id} is no longer pending or does not exist.";
        }
    }
}


// --- Fetch All Fund Requests for Display ---
$fund_requests = [];
$sql_requests = "SELECT 
                    fr.request_id, 
                    fr.user_id, 
                    u.username AS customer_username,
                    u.email AS customer_email,
                    fr.amount, 
                    fr.request_date, 
                    fr.status, 
                    fr.screenshot_path
                    -- Removed admin_notes, processed_date, admin_processor_username from SELECT for display
                 FROM fund_requests fr
                 JOIN users u ON fr.user_id = u.user_id
                 -- LEFT JOIN users a ON fr.processed_by_admin_id = a.user_id -- Removed JOIN for admin user
                 ORDER BY fr.request_date DESC";

if ($result_requests = $conn->query($sql_requests)) {
    while ($row = $result_requests->fetch_assoc()) {
        $fund_requests[] = $row;
    }
    $result_requests->free();
} else {
    $error_message .= " Error fetching fund requests: " . $conn->error;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Fund Requests - Admin Panel</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        /* ======================================================= */
        /* === ADMIN HEADER STYLING (Override global style.css) === */
        /* ======================================================= */
        header {
            position: relative; /* Ensure z-index works */
            z-index: 1000; /* Ensure header is on top of other content */
        }
        /* Hide the default navigation list for admin pages */
        header nav ul {
            display: none;
        }
        /* Center the main title for admin pages */
        header h1 {
            float: none; /* Remove float from global style.css */
            text-align: center; /* Center the title */
            width: 100%; /* Ensure it takes full width for centering */
            margin-bottom: 0; /* Adjust margin as needed */
        }
        /* Clearfix might still be needed if other elements float, but H1/NAV are now handled */
        header .container::after {
            content: "";
            display: table;
            clear: both;
        }

        /* ======================================================= */
        /* === QUICK ACTIONS BUTTONS STYLING === */
        /* ======================================================= */
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
            padding: 15px 20px;
            font-size: 1.1em;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none; /* Ensure links look like buttons */
            color: white; /* Default white text */
        }
        .quick-actions .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }

        /* Specific button colors for better visual distinction */
        .btn-users { background-color: #007bff; } /* Blue */
        .btn-restaurants { background-color: #28a745; } /* Green */
        .btn-add-restaurant { background-color: #17a2b8; } /* Cyan */
        .btn-payment-settings { background-color: #ffc107; color: #333; } /* Yellow */
        .btn-fund-requests { background-color: #6f42c1; } /* Purple */
        .btn-logout { background-color: #dc3545; } /* Red */

        /* ======================================================= */
        /* === FUND REQUESTS TABLE STYLING === */
        /* ======================================================= */
        .fund-requests-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 40px;
            background-color: #fff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden; /* For rounded corners on table */
        }
        .fund-requests-table th, .fund-requests-table td {
            border: 1px solid #ddd;
            padding: 12px 15px;
            text-align: left;
            vertical-align: top; /* Align content to top for longer descriptions */
        }
        .fund-requests-table th {
            background-color: #f2f2f2;
            font-weight: bold;
            color: #555;
            text-transform: uppercase;
            font-size: 0.9em;
        }
        .fund-requests-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .fund-requests-table tbody tr:hover {
            background-color: #f1f1f1;
        }
        .fund-requests-table .actions-cell { /* New class for the actions column */
            white-space: normal; /* Allow wrap for form elements */
            width: 250px; /* Give enough space for buttons and textarea */
        }
        .fund-requests-table .action-form {
            display: block; /* Make each form a block */
            margin-bottom: 10px; /* Space between accept/decline forms */
            padding-bottom: 10px;
            border-bottom: 1px dashed #eee;
        }
        .fund-requests-table .action-form:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .fund-requests-table .actions-cell button {
            padding: 8px 12px;
            margin-top: 5px; /* Space above button */
            margin-right: 5px; /* Space between buttons if they end up side-by-side */
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
            font-size: 0.9em;
            transition: opacity 0.3s ease;
            display: inline-block; /* Ensure it respects margin-right */
        }
        .fund-requests-table .actions-cell .btn-accept { background-color: #28a745; } /* Green */
        .fund-requests-table .actions-cell .btn-decline { background-color: #dc3545; } /* Red */
        .fund-requests-table .actions-cell .btn-view-screenshot { 
            background-color: #007bff; 
            color: white; /* Ensure text is white for blue button */
            padding: 8px 12px;
            border-radius: 4px;
            text-decoration: none; /* Make it look like a button */
            display: inline-block;
            margin-bottom: 10px; /* Space before notes/actions forms */
        }

        .fund-requests-table .actions-cell button:hover,
        .fund-requests-table .actions-cell a.btn-view-screenshot:hover {
            opacity: 0.8;
        }

        /* Status colors */
        .status-pending { color: orange; font-weight: bold; }
        .status-accepted { color: green; font-weight: bold; }
        .status-declined { color: red; font-weight: bold; }

        /* Admin notes input style within the table cells */
        .admin-notes-input {
            width: calc(100% - 20px); /* Adjust for padding/border */
            padding: 8px;
            margin-bottom: 8px; /* Space below textarea */
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
            min-height: 40px; /* Keep it compact */
            font-size: 0.9em;
        }

        /* Responsive table */
        @media (max-width: 768px) {
            .fund-requests-table, .fund-requests-table tbody, .fund-requests-table tr, .fund-requests-table td {
                display: block;
                width: 100%;
            }
            .fund-requests-table thead {
                display: none;
            }
            .fund-requests-table tr {
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .fund-requests-table td {
                text-align: right;
                padding-left: 50%;
                position: relative;
                border: none;
                border-bottom: 1px dashed #eee;
            }
            .fund-requests-table td:last-child {
                border-bottom: none;
            }
            .fund-requests-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 30px);
                white-space: nowrap;
                font-weight: bold;
                color: #555;
            }
            .fund-requests-table .actions-cell {
                white-space: normal; /* Allow actions cell to wrap content */
                width: auto; /* Remove fixed width */
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Debre Tabor Food Delivery</h1>
            <nav>
                <!-- Admin navigation is custom for dashboard -->
            </nav>
        </div>
    </header>

    <main>
        <section class="container">
            <h2>Manage Fund Requests</h2>
            <p>Review customer requests for virtual balance top-ups and their payment proofs.</p>

            <!-- Admin Tools Navigation -->
            <div class="quick-actions">
                <a href="admin_dashboard.php" class="btn" style="background-color: #6c757d;">Back to Dashboard</a>
                <a href="manage_users.php" class="btn btn-users">Manage Users</a>
                <a href="manage_restaurants.php" class="btn btn-restaurants">Manage Restaurants</a>
                <a href="add_restaurant.php" class="btn btn-add-restaurant">Add Restaurant</a>
                <a href="configure_payment_settings.php" class="btn btn-payment-settings">Payment Settings</a>
                <a href="logout.php" class="btn btn-logout">Logout</a>
            </div>

            <?php if (!empty($success_message)) { echo '<div class="message">' . $success_message . '</div>'; } ?>
            <?php if (!empty($error_message)) { echo '<div class="error-message">' . $error_message . '</div>'; } ?>

            <h3>All Fund Requests</h3>
            <?php if (!empty($fund_requests)): ?>
                <table class="fund-requests-table">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Customer (ID)</th>
                            <th>Amount</th>
                            <th>Request Date</th>
                            <th>Status</th>
                            <th>Screenshot</th>
                            <th class="actions-cell">Actions</th> <!-- Retained for buttons -->
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fund_requests as $request): ?>
                            <tr>
                                <td data-label="Request ID"><?php echo htmlspecialchars($request['request_id']); ?></td>
                                <td data-label="Customer">
                                    <?php echo htmlspecialchars($request['customer_username']); ?> (ID: <?php echo htmlspecialchars($request['user_id']); ?>)
                                    <br><small><?php echo htmlspecialchars($request['customer_email']); ?></small>
                                </td>
                                <td data-label="Amount">ETB <?php echo number_format($request['amount'], 2); ?></td>
                                <td data-label="Request Date"><?php echo date('M d, Y H:i', strtotime($request['request_date'])); ?></td>
                                <td data-label="Status" class="status-<?php echo htmlspecialchars($request['status']); ?>">
                                    <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                </td>
                                <td data-label="Screenshot">
                                    <?php if (!empty($request['screenshot_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($base_upload_url . $request['screenshot_path']); ?>" target="_blank" class="btn-view-screenshot">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions" class="actions-cell">
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <div class="action-form">
                                            <form action="manage_fund_requests.php" method="POST">
                                                <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['request_id']); ?>">
                                                <input type="hidden" name="action" value="accept">
                                                <textarea name="admin_notes" class="admin-notes-input" placeholder="Optional: Notes for acceptance..."></textarea>
                                                <button type="submit" class="btn-accept"><i class="fas fa-check"></i> Accept</button>
                                            </form>
                                        </div>
                                        <div class="action-form">
                                            <form action="manage_fund_requests.php" method="POST">
                                                <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request['request_id']); ?>">
                                                <input type="hidden" name="action" value="decline">
                                                <textarea name="admin_notes" class="admin-notes-input" placeholder="Optional: Reason for decline..."></textarea>
                                                <button type="submit" class="btn-decline"><i class="fas fa-times"></i> Decline</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <p style="color: #666; font-size: 0.9em;">Already <?php echo htmlspecialchars(ucfirst($request['status'])); ?></p>
                                        <?php if (!empty($request['admin_notes'])): ?>
                                            <p style="font-size: 0.8em; color: #888;">Notes: <?php echo htmlspecialchars($request['admin_notes']); ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; margin-top: 30px; font-size: 1.2em; color: #777;">No fund requests to display.</p>
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
