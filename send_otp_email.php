<?php
// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer files
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

/**
 * Sends a 6-digit OTP verification email with anti-phishing headers.
 */
function send_otp_email($recipient_email, $username, $otp_code) {
    $mail = new PHPMailer(true);

    try {
        // --- SERVER SETTINGS ---
        $mail->SMTPDebug = 0; // Set to 2 only for troubleshooting
        $mail->isSMTP();                                            
        $mail->Host       = 'smtp.gmail.com';                     
        $mail->SMTPAuth   = true;                                   
        $mail->Username   = 'debretaborfooddelivery@gmail.com'; // Your Gmail
        $mail->Password   = 'qtpevogaaspmbeuo'; // YOUR APP PASSWORD (No spaces)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         
        $mail->Port       = 587;   

        // --- ENCODING & ANTI-PHISHING HEADERS ---
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        // Unique Message-ID helps prove the email is not a generic bot
        $mail->MessageID = '<' . md5(uniqid(time())) . '@gmail.com>';

        // --- RECIPIENTS ---
        // 'From' MUST match 'Username' exactly to avoid phishing warnings
        $mail->setFrom('debretaborfooddelivery@gmail.com', 'Debre Tabor Food Delivery');
        $mail->addAddress($recipient_email, $username);
        $mail->addReplyTo('debretaborfooddelivery@gmail.com', 'Official Support');

        // --- CONTENT ---
        $mail->isHTML(true);                                  
        $mail->Subject = "Your verification code: $otp_code"; // Clean, descriptive subject
        
        // Professional HTML Body
        $mail->Body = "
            <div style='background-color: #f4f4f4; padding: 20px; font-family: Arial, sans-serif;'>
                <div style='max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;'>
                    <h2 style='color: #333; text-align: center;'>Welcome to Debre Tabor Food Delivery</h2>
                    <p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p>
                    <p>To finish setting up your account, please enter the following verification code:</p>
                    <div style='background: #fdf2f2; border: 1px dashed #ff6f61; padding: 15px; text-align: center; margin: 20px 0;'>
                        <span style='font-size: 32px; font-weight: bold; color: #ff6f61; letter-spacing: 5px;'>" . $otp_code . "</span>
                    </div>
                    <p style='font-size: 13px; color: #777;'>For your security, do not share this code with anyone. If you did not request this, please ignore this email.</p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p style='text-align: center; font-size: 11px; color: #aaa;'>Debre Tabor, Amhara Region, Ethiopia</p>
                </div>
            </div>";

        // Plain text version (Very important to satisfy spam filters)
        $mail->AltBody = "Hello " . $username . ",\n\nYour verification code is: " . $otp_code . "\n\nPlease enter this code on the website to verify your account.\n\nThank you,\nDebre Tabor Food Delivery Team";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}