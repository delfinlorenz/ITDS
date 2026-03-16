# ULTIMATE ITAM — PHP Edition
## Complete IT Asset Management System

---

## 📁 Folder Structure

```
itam/
├── index.php               ← Login page
├── logout.php
├── .htaccess
├── database.sql            ← Run this first!
├── includes/
│   ├── config.php          ← DB credentials & helpers
│   ├── header.php          ← Shared sidebar + top bar
│   └── footer.php          ← Shared scripts
├── pages/
│   ├── dashboard.php
│   ├── inventory.php
│   ├── inventory_add.php   ← Add & Edit (uses ?id= for edit)
│   ├── asset_view.php      ← Single asset detail
│   ├── employees.php
│   ├── maintenance.php
│   ├── history.php
│   ├── supplies.php
│   ├── software.php
│   ├── network.php
│   ├── helpdesk.php
│   ├── performance.php
│   ├── security.php
│   ├── gallery.php
│   ├── audit.php
│   ├── forecast.php
│   ├── notifications.php
│   ├── profile.php
│   └── users.php           ← Admin only
├── api/
│   ├── search.php          ← AJAX smart search
│   ├── assets.php          ← REST API for assets
│   ├── maintenance.php     ← REST API for tasks
│   └── notifications.php   ← REST API for notifications
└── assets/
    ├── css/style.css
    └── js/app.js
```

---

## 🚀 Quick Setup (XAMPP)

### Step 1 — Copy files
```
Copy the `itam/` folder into:
  C:\xampp\htdocs\itam\
```

### Step 2 — Create Database
1. Open `http://localhost/phpmyadmin`
2. Click **New** → name it `itam_db` → Create
3. Click the `itam_db` database
4. Click **Import** tab
5. Choose `itam/database.sql` → Click **Go**

### Step 3 — Configure DB
Open `includes/config.php` and set:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'itam_db');
define('DB_USER', 'root');
define('DB_PASS', '');        // blank for XAMPP default
```

### Step 4 — Access the app
Open: `http://localhost/itam/`

### Step 5 — Login
| Username | Password   | Role          |
|----------|------------|---------------|
| admin    | password   | Administrator |
| tech1    | password   | Technician    |
| manager  | password   | Manager       |

---

## 🌐 Shared Hosting / cPanel Setup

1. Upload `itam/` to `public_html/itam/`
2. In cPanel → MySQL Databases → create `itam_db` + user with all privileges
3. Run `database.sql` via phpMyAdmin import
4. Update `includes/config.php` with your DB credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'yourusername_itam_db');
   define('DB_USER', 'yourusername_dbuser');
   define('DB_PASS', 'yourpassword');
   ```
5. Visit `https://yourdomain.com/itam/`

---

## 🔐 Changing Passwords

To set a real password (instead of "password"), run this in phpMyAdmin:
```sql
UPDATE users SET password = '$2y$10$...' WHERE username = 'admin';
```

Or generate a PHP hash:
```php
echo password_hash('your_new_password', PASSWORD_DEFAULT);
```

---

## ✨ Features

- ✅ Login with Role-Based Access Control (Admin, Technician, Manager, Helpdesk, Auditor)
- ✅ Dashboard with live stats, charts (Chart.js), recent activity
- ✅ Full Computer Inventory CRUD with dept, location, specs, lifecycle
- ✅ Employee Directory
- ✅ Maintenance Schedule (add, complete, filter, export)
- ✅ Helpdesk Ticket System (create, assign, resolve)
- ✅ Extra Supplies Inventory
- ✅ Software & License Management
- ✅ Network Device Scanner
- ✅ Performance Monitoring
- ✅ Security Status
- ✅ Audit Trail (every action logged)
- ✅ Forecasting (replacement planning)
- ✅ QR Code generation (client-side)
- ✅ Excel Export (client-side XLSX)
- ✅ Smart Global Search (AJAX)
- ✅ Dark Mode
- ✅ Philippine Peso (₱) currency
- ✅ Notifications system
- ✅ Fully responsive

---

## 🛠 Tech Stack

| Layer    | Technology                    |
|----------|-------------------------------|
| Backend  | PHP 8.0+ with PDO             |
| Database | MySQL 5.7+ / MariaDB          |
| Frontend | Vanilla JS, Chart.js, XLSX.js |
| Server   | Apache (XAMPP / cPanel)       |
| CSS      | Custom (no framework needed)  |

---

## 📋 Requirements

- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite enabled
- PHP extensions: PDO, PDO_MySQL, json, session

---

## 💡 Tips

- Add more users via phpMyAdmin or the Users page (Admin only)
- The `includes/config.php` file contains all app-wide settings
- All API endpoints are in the `api/` folder and return JSON
- The `assets/css/style.css` contains all styling — easy to customize

---

*ULTIMATE ITAM PHP Edition — Built for Philippine organizations*
