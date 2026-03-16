# ULTIMATE ITAM — Setup Guide for Apache 2.4 + MySQL Workbench

## What You Need

| Software | Download |
|---|---|
| Apache 2.4 | https://www.apachelounge.com/download/ |
| PHP 8.x (Thread Safe) | https://windows.php.net/download/ |
| MySQL 8.0 | https://dev.mysql.com/downloads/mysql/ |
| MySQL Workbench | https://dev.mysql.com/downloads/workbench/ |

---

## PART 1 — Import the Database in MySQL Workbench

1. Open **MySQL Workbench**
2. Click your connection (e.g. `root@localhost`)
3. Go to menu: **Server → Data Import**
4. Select **"Import from Self-Contained File"**
5. Browse and select `database.sql` from this folder
6. Under **"Default Target Schema"** → click **"New..."** → type `itam_db` → OK
7. Click **"Start Import"** button (bottom right)
8. Wait for the green checkmark — done!

> **Verify**: In the left panel under **Schemas**, refresh and you should see `itam_db` with all tables listed.

---

## PART 2 — Set Up Apache 2.4

### 2a. Copy the project folder

Copy the entire `itam_php` folder to your Apache htdocs:
```
C:\Apache24\htdocs\itam_php\
```

### 2b. Edit httpd.conf

Open `C:\Apache24\conf\httpd.conf` in Notepad (as Administrator).

**Find and uncomment** (remove the `#`):
```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

**Find the PHP module line and uncomment it** (it looks like):
```apache
LoadModule php_module "C:/php/php8apache2_4.dll"
```
> If this line doesn't exist, add it. Adjust the path to where you installed PHP.

**Also add** (if not present):
```apache
PHPIniDir "C:/php"
AddHandler application/x-httpd-php .php
```

**Find this block** and change `AllowOverride None` to `AllowOverride All`:
```apache
<Directory "C:/Apache24/htdocs">
    Options Indexes FollowSymLinks
    AllowOverride All        ← change this line
    Require all granted
</Directory>
```

### 2c. Test and restart Apache

Open **Command Prompt as Administrator** and run:
```
httpd -t
```
If it says `Syntax OK`, restart:
```
httpd -k restart
```

### 2d. Test Apache is working

Visit: `http://localhost/` — you should see the Apache test page or htdocs listing.

---

## PART 3 — Configure the App

Open `includes\config.php` and update the database credentials:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'itam_db');
define('DB_USER', 'root');       // your MySQL Workbench username
define('DB_PASS', 'yourpassword'); // your MySQL Workbench password
```

> Default MySQL Workbench password is whatever you set during MySQL installation.
> It is NOT blank like XAMPP — you must fill this in.

---

## PART 4 — Access the App

Open your browser and go to:
```
http://localhost/itam_php/
```

**Default login credentials:**
| Username | Password | Role |
|---|---|---|
| admin | password | Admin |
| tech1 | password | Technician |
| manager | password | Manager |

> Change these passwords immediately after first login via **My Profile → Change Password**

---

## Troubleshooting

### "Not Found" error
- Make sure folder is at `C:\Apache24\htdocs\itam_php\`
- Make sure `AllowOverride All` is set in httpd.conf
- Make sure `mod_rewrite` is loaded (uncommented)
- Restart Apache after any httpd.conf change

### "500 Internal Server Error"
- Check `C:\Apache24\logs\error.log` for details
- Make sure PHP module path in httpd.conf is correct
- Run `httpd -t` to check for config syntax errors

### "Connection failed" / blank page
- Open `includes/config.php` and check DB_PASS matches your MySQL root password
- Open MySQL Workbench and verify the `itam_db` database was imported
- Make sure MySQL service is running (check Windows Services or MySQL Workbench connection)

### PHP not executing (shows raw PHP code)
- PHP module is not loaded — check `LoadModule php_module` line in httpd.conf
- Make sure `AddHandler application/x-httpd-php .php` is present

---

## Optional: Clean URL with Virtual Host

If you want `http://itam.local/` instead of `http://localhost/itam_php/`:

1. Add to `C:\Windows\System32\drivers\etc\hosts`:
```
127.0.0.1   itam.local
```

2. Add to bottom of `httpd.conf`:
```apache
<VirtualHost *:80>
    ServerName itam.local
    DocumentRoot "C:/Apache24/htdocs/itam_php"
    <Directory "C:/Apache24/htdocs/itam_php">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

3. Restart Apache — visit `http://itam.local/`

---

## File Structure
```
itam_php/
├── index.php               ← Login page (entry point)
├── logout.php
├── .htaccess               ← Apache config (do not delete)
├── database.sql            ← Import this into Workbench
├── apache24_httpd_config.conf  ← Reference config snippets
├── includes/
│   ├── config.php          ← Edit DB credentials here
│   ├── header.php
│   └── footer.php
├── pages/                  ← All app pages
├── api/                    ← AJAX endpoints
└── assets/
    ├── css/style.css
    └── js/app.js
```
