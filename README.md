🍔 Debre Tabor Food Delivery

A web-based food delivery platform designed to connect customers with restaurants and delivery personnel in Debre Tabor, Ethiopia.

The project allows customers to discover restaurants, browse food items, place orders, and manage their deliveries through an easy-to-use web interface.

📌 Project Overview

Debre Tabor Food Delivery is a full-stack web application developed as a practical project to provide an online platform for food ordering and delivery by PHP.

The system is designed around three main users:

- 👤 Customer — Browse restaurants and order food.
- 🏪 Restaurant Manager — Manage restaurants, food items, and orders.
- 🚴 Delivery Personnel — Manage and deliver customer orders.
- 👨🏻‍🏫 But corelate these there by Admin.

✨ Features

👤 Customer

- User registration and login
- Browse available restaurants
- View restaurant information
- Browse food items
- Add food to cart
- Place food orders
- Track order information
- Email verification

🏪 Restaurant Manager

- Restaurant management
- Add and manage food items
- Manage incoming orders
- Update order status

🚴 Delivery Personnel

- View assigned deliveries
- Manage delivery status
- Update order delivery progress

🔐 Authentication & Security

- User authentication
- Password hashing
- Role-based access
- Email/OTP verification
- Server-side validation
- Secure database queries

🛠️ Technologies Used

Frontend

- HTML5
- CSS3
- JavaScript

Backend

- PHP

Database

- MySQL 

Development Environment

- Laragon
- VS Code

Additional Tools
- PHPMailer for email verification


⚙️ Installation

1. Clone the repository

git clone https://github.com/Amanual-ew/debre-tabor-food-delivery.git

2. Move the project to Laragon

Copy the project folder into:

C:/laragon/www

3. Start Laragon

Open Laragon and start:

- Apache
- MySQL

4. Create the database

Open:

http://localhost/phpmyadmin

Create a database for the project called "food_delivery_db"

5. Import the database

Import the project's SQL database file through phpMyAdmin.

6. Configure the database

Update your database configuration file with your local settings.

Example:

$host = "localhost";
$username = "root";
$password = "";
$database = "food_delivery_db";

7. Run the project

Open your browser and visit:

http://localhost/debre-tabor-food-delivery/

🔑 User Roles

Role| Main Responsibilities
Customer| Browse food and place orders
Restaurant Manager| Manage restaurant and orders
Delivery Personnel| Manage deliveries
Admin| Corelate the User, Restaurant Manager and  Delivery person. 

🎯 Project Goals

The main goals of this project are to:

- Make food ordering easier for people in Debre Tabor.
- Connect customers with local restaurants.
- Provide restaurants with an online ordering system.
- Organize the food delivery process.
- Practice full-stack web development.
- Build a real-world software solution for a local community.

🚀 Future Improvements

Possible future features include:

- 📍 Real-time delivery tracking
- 💳 Online payment integration
- 📱 Mobile application
- 📊 Restaurant analytics dashboard

👨‍💻 Developer

Developed as a software development project focused on solving a real-world food delivery problem in Debre Tabor, Ethiopia.

📄 License

This project is created for educational and portfolio purposes.

---

⭐ If you find this project useful, consider giving the repository a star!
