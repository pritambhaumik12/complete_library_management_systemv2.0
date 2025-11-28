# 📚 Complete Library Management System (LMS) v2.0

A robust, full-featured, and modern Library Management System developed in PHP and MySQL. Designed to bridge the gap between physical library operations and digital e-learning, this system features a stunning Glassmorphism UI, multi-library support, and advanced security measures for digital content.

[LMS Dashboard Preview](assets/screenshots/dashboard-preview.png)


## 🚀 Key Features

### 🏛️ For Administrators & Librarians
* **Multi-Library Architecture:** Support for multiple library branches with Super Admin (Global) and Librarian (Local) roles.
* **Modern Glassmorphism UI:** A beautiful, responsive interface designed for clarity and ease of use.
* **Circulation Management:** Streamlined Issue and Return processes supported by **Barcode & QR Code scanning**.
* **Digital Asset Management:** Upload and manage E-Books (PDF/ePub) alongside physical inventory.
* **Smart Fine Management:** Automated calculation of overdue fines with a robust collection and receipt generation system.
* **Security & DRM:** Built-in protection for E-Books that detects screenshot attempts and automatically locks accounts to prevent piracy.
* **ID Card & Label Generation:** Auto-generate printable Member ID cards and Book QR labels directly from the system.
* **Clearance System:** One-click checking for member clearance (No Dues certificate generation).

### 🎓 For Members (Students/Faculty)
* **Personal Dashboard:** Track current loans, learning history, and outstanding dues.
* **Online Reader:** Secure, built-in PDF reader for instant access to digital resources.
* **Catalog Search:** Advanced search filtering by category, author, availability, and content type.
* **Reservation System:** Reserve physical books that are currently issued to others.
* **Favorites & History:** Keep track of reading lists and previous borrowings.

## 🛠️ Technology Stack

* **Backend:** PHP (Native/Procedural & OOP mix for robustness)
* **Database:** MySQL (Relational Schema)
* **Frontend:** HTML5, CSS3 (Custom Glassmorphism), JavaScript (Vanilla)
* **Libraries used:**
    * `PDF.js` (For secure online reading)
    * `Html5-QRCode` (For scanning book/member IDs)
    * `JsBarcode` (For generating barcodes)
    * `FontAwesome` (Icons)

## ⚙️ Installation & Setup

### Prerequisites
* A local server environment (XAMPP, WAMP, or LAMP stack).
* PHP 8.0 or higher.
* MySQL 5.7 or higher.

### Step-by-Step Guide
1.  **Database Configuration**
    * Open `phpMyAdmin` (or your preferred SQL tool).
    * Create a new database named `lmsv2_db`.
    * Import the `lms_db_setup.sql` file located in the root directory.

2.  **Default Admin Credentials**
    * **URL:** `/admin/login.php`
    * **Username:** `admin`
    * **Password:** `password`
    *(Please change these immediately after logging in via the Profile settings)*

3. **Member Login:**
```
URL: http://localhost/lms_project/login.php
```

**_⚠️ Security Note:_**

This project is designed for educational purposes. Passwords are currently stored in plain text to make the code easier to understand for beginners. For a production environment, please update the code to use password_hash() and password_verify().

This LMS implements a unique **"Anti-Piracy"** mechanism for the Online Reader (`read_online.php`).
* **Screenshot Detection:** The system listens for OS-level screenshot key combinations (e.g., `Win+Shift+S`, `PrintScreen`).
* **Context Switching:** detects if the user tries to lose focus to capture the screen.
* **Automated Action:** If a violation is detected, the user's session is immediately terminated, and their account is flagged as "Inactive." An admin must manually reactivate the account.


**_📜 License:_**

This project is licensed under the **GNU General Public License v3.0 (GPLv3)**.

* **If you wish to use this code in a closed-source or proprietary commercial application (without the GPLv3 restrictions), please **contact me** to discuss obtaining a commercial license.**

*See the [LICENSE](LICENSE) file for the full legal text.*





# 📖 User Manual - Library Management System v2.0

This document serves as a comprehensive guide for Administrators, Librarians, and Members using the LMS.

---

## 🏗️ Roles & Permissions

1.  **Super Admin:** Has global access. Can manage system settings, create libraries, and manage other admins.
2.  **Librarian (Admin):** Restricted to their specific library branch. Can manage books, issue/returns, and members within their scope.
3.  **Member:** Students or Faculty who browse, read, and borrow books.

---

## 🏢 Administrator Guide

### 1. Dashboard Overview
Upon logging in, you are greeted with the **Live Dashboard**.
* **Statistics:** Real-time counters for Total Books, Issued Books, Overdue items, and Active Members.
* **Quick Actions:** Fast access buttons for "Issue Book", "Return Book", and "Add Member".
* **Security Monitor:** A dedicated panel to view security breaches (screenshot attempts) by members.

### 2. Managing Inventory (Books)
Navigate to **Catalog > Book Management**.
* **Adding a Book:** * Select "Physical", "E-Book", or "Both".
    * **Physical:** Generates unique barcodes automatically based on quantity.
    * **E-Book:** Upload a PDF/ePub or provide an external URL. 
    * **Security Control:** Set to "Yes" to enable anti-screenshot protection for E-Books.
* **Printing Labels:** Go to **Catalog > Print Labels**. You can select a specific library or a specific book to generate QR codes and Barcodes for sticking on physical copies.

### 3. Circulation (Issue & Return)
* **Issuing a Book:**
    1.  Go to **Issue Book**.
    2.  **Direct Mode:** Scan the Member ID card and the Book Barcode.
    3.  **Reservation Mode:** Enter the Reservation ID to fulfill a request.
* **Returning a Book:**
    1.  Go to **Return Book**.
    2.  Scan the Book Barcode.
    3.  The system automatically calculates overdue fines.
    4.  **Lost Books:** If a book is lost, select "Lost" from the dropdown. The system will prompt you to enter a replacement cost/fine.

### 4. Fine Management
* **Collecting Fines:** Go to **Admin > Fines**. 
    * Search for the member.
    * Click "Pay Now" next to the outstanding amount.
    * Select payment method (Cash/Card/UPI) and generate a receipt.
    * *Note: Books cannot be cleared or accounts deleted until all fines are paid.*

### 5. Library Clearance
To generate a "No Dues" certificate for a member leaving the institution:
1.  Go to **Library Clearance**.
2.  Scan or enter the Member ID.
3.  The system performs a global check for unreturned books and unpaid fines.
4.  If clean, a **Clearance Certificate** can be printed instantly.

---

## 👨‍🎓 Member Guide

### 1. Getting Started
* **Login:** Use your User ID (provided by the library) and password.
* **Dashboard:** View your current loans, due dates, and any fines you might owe.

### 2. Searching & Reserving
* Navigate to **Search Catalog**.
* Use filters to find books by Category, Author, or Availability.
* **Physical Books:** If a book is available, you can see its shelf location. If it is issued out, click **"Reserve"** to join the waitlist.
* **E-Books:** Click **"Read"** to open the digital viewer immediately.

### 3. Reading Online (Important Security Note)
When reading E-Books protected by the library:
* The book opens in a secure full-screen viewer.
* **DO NOT** attempt to take screenshots or screen recordings.
* **Violation Penalty:** The system detects capture attempts. Your account will be **instantly locked**, and you will be logged out. You must visit the librarian to reactivate your account.

### 4. My Profile
* Go to **Account Settings** to update your email or change your password.
* Check **My History** to see everything you have ever read or borrowed.

---

## ❓ Troubleshooting

**Q: I cannot delete a book.** A: You cannot delete a book if copies are currently issued to members. Please wait for them to be returned.

**Q: The QR Scanner is not working.** A: Ensure your device has a camera and you have granted the browser permission to access it. Ensure lighting is sufficient.

**Q: A member is blocked.** A: Check **System Alerts**. If they violated security protocols (screenshots), a Librarian must manually click "Unlock" in the alert log to restore access.

---
Developed with ❤️ by Pritam Bhaumik
