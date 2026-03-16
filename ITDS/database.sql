-- ============================================================
-- ULTIMATE ITAM - MySQL Database Schema
-- Compatible with: MySQL Workbench, XAMPP, WAMP
-- MySQL 5.7+ / 8.0+
--
-- HOW TO IMPORT IN MYSQL WORKBENCH:
-- 1. Server → Data Import
-- 2. Import from Self-Contained File → select this file
-- 3. Leave Target Schema blank (auto-creates itam_db)
-- 4. Start Import
-- 5. Edit includes/config.php → set DB_PASS
-- ============================================================

DROP DATABASE IF EXISTS itam_db;
CREATE DATABASE itam_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE itam_db;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('Admin','Technician','Manager','Helpdesk','Auditor') DEFAULT 'Technician',
    company VARCHAR(100) DEFAULT 'Main Office',
    email VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO users (name, username, password, role, company) VALUES
('Administrator', 'admin',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin',      'Main Office'),
('Technician 1',  'tech1',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Technician', 'Main Office'),
('Manager',       'manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Manager',    'Main Office');
-- Default password for all: "password"

-- ============================================================
-- DEPARTMENTS
-- ============================================================
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    head VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO departments (name) VALUES
('IT'),('HR'),('Finance'),('Marketing'),('Sales'),
('Operations'),('R&D'),('Legal'),('Administration');

-- ============================================================
-- EMPLOYEES
-- ============================================================
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_id VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    department_id INT,
    position VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(30),
    building VARCHAR(100),
    floor VARCHAR(20),
    room VARCHAR(50),
    desk VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- ============================================================
-- ASSETS
-- ============================================================
CREATE TABLE assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(50) NOT NULL UNIQUE,
    serial_number VARCHAR(100),
    brand VARCHAR(100),
    model VARCHAR(150),
    device_type VARCHAR(50) DEFAULT 'Desktop',

    -- Specs
    processor VARCHAR(150),
    ram VARCHAR(50),
    storage VARCHAR(100),
    gpu VARCHAR(150),
    monitor VARCHAR(100),
    operating_system VARCHAR(100),
    os_version VARCHAR(50),

    -- Network
    hostname VARCHAR(100),
    ip_address VARCHAR(45),
    mac_address VARCHAR(20),
    connection_type VARCHAR(50),
    isp VARCHAR(100),

    -- Assignment
    employee_id INT,

    -- Purchase
    purchase_date DATE,
    supplier VARCHAR(100),
    po_number VARCHAR(100),
    purchase_cost DECIMAL(12,2) DEFAULT 0.00,
    warranty_expiry DATE,

    -- Status
    status ENUM('Active','Maintenance','Spare','Retired','Lost','Stolen','Disposed') DEFAULT 'Active',
    lifecycle_state VARCHAR(50) DEFAULT 'Active',
    company VARCHAR(100) DEFAULT 'Main Office',
    is_flagged TINYINT(1) DEFAULT 0,
    flag_reason VARCHAR(200),

    -- Location
    building VARCHAR(100),
    floor VARCHAR(20),
    room VARCHAR(50),
    desk VARCHAR(50),

    -- Security
    antivirus_installed TINYINT(1) DEFAULT 1,
    antivirus_name VARCHAR(100) DEFAULT 'Windows Defender',
    firewall_enabled TINYINT(1) DEFAULT 1,
    encryption_enabled TINYINT(1) DEFAULT 0,
    last_virus_scan DATE,
    last_backup DATE,

    -- Photo
    photo_url VARCHAR(255),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
);

-- ============================================================
-- INSTALLED SOFTWARE
-- ============================================================
CREATE TABLE installed_software (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    version VARCHAR(50),
    license_key VARCHAR(255),
    install_date DATE,
    notes TEXT,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);

-- ============================================================
-- ASSET UPGRADES
-- ============================================================
CREATE TABLE asset_upgrades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    upgrade_type VARCHAR(100),
    old_value VARCHAR(200),
    new_value VARCHAR(200),
    upgrade_date DATE,
    cost DECIMAL(10,2) DEFAULT 0.00,
    technician VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);

-- ============================================================
-- MAINTENANCE TASKS
-- ============================================================
CREATE TABLE maintenance_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT,
    task_name VARCHAR(200) NOT NULL,
    type VARCHAR(100) DEFAULT 'Preventive',
    description TEXT,
    priority ENUM('Low','Medium','High','Critical') DEFAULT 'Medium',
    status ENUM('Scheduled','In Progress','Completed','Cancelled') DEFAULT 'Scheduled',
    assigned_to VARCHAR(100),
    scheduled_date DATE,
    completed_date DATE,
    cost DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL
);

-- ============================================================
-- MAINTENANCE LOG
-- ============================================================
CREATE TABLE maintenance_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT,
    log_date DATE,
    type VARCHAR(100),
    issue TEXT,
    resolution TEXT,
    technician VARCHAR(100),
    cost DECIMAL(10,2) DEFAULT 0.00,
    status VARCHAR(50) DEFAULT 'Completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL
);

-- ============================================================
-- SUPPLIES
-- ============================================================
CREATE TABLE supplies (
    id VARCHAR(20) PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    brand VARCHAR(100),
    model VARCHAR(150),
    serial_number VARCHAR(100),
    status ENUM('Available','In Use','Defective','Disposed') DEFAULT 'Available',
    `condition` ENUM('New','Like New','Good','Fair','Poor') DEFAULT 'Good',
    location VARCHAR(100),
    purchase_date DATE,
    purchase_cost DECIMAL(10,2) DEFAULT 0.00,
    warranty_expiry DATE,
    assigned_to VARCHAR(150),
    assigned_date DATE,
    replacement_for VARCHAR(200),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- SUPPLY TRANSACTIONS
-- ============================================================
CREATE TABLE supply_transactions (
    id VARCHAR(20) PRIMARY KEY,
    supply_id VARCHAR(20),
    supply_type VARCHAR(100),
    supply_brand VARCHAR(100),
    supply_model VARCHAR(150),
    asset_tag VARCHAR(50),
    emp_name VARCHAR(150),
    old_item VARCHAR(200),
    new_item VARCHAR(200),
    reason VARCHAR(100) DEFAULT 'Defective',
    transaction_date DATE,
    technician VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- SUPPLY HISTORY
-- ============================================================
CREATE TABLE supply_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supply_id VARCHAR(20),
    action_date DATE,
    action VARCHAR(200),
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- SOFTWARE LICENSES
-- ============================================================
CREATE TABLE software_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    vendor VARCHAR(100),
    total_seats INT DEFAULT 1,
    used_seats INT DEFAULT 0,
    cost_per_seat DECIMAL(10,2) DEFAULT 0.00,
    expiration_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO software_licenses (name, vendor, total_seats, used_seats, cost_per_seat, expiration_date) VALUES
('Microsoft Office 365', 'Microsoft', 100, 75, 3500.00, '2026-12-31'),
('Adobe Creative Cloud',  'Adobe',     50,  32, 8500.00, '2026-06-30'),
('Windows Server 2022',   'Microsoft', 20,  12, 45000.00,'2027-01-31'),
('AutoCAD',               'Autodesk',  25,  18, 25000.00,'2026-09-30'),
('MATLAB',                'MathWorks', 30,  22, 18000.00,'2026-08-31');

-- ============================================================
-- HELPDESK TICKETS
-- ============================================================
CREATE TABLE tickets (
    id VARCHAR(20) PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'General',
    priority ENUM('Low','Medium','High','Critical') DEFAULT 'Medium',
    status ENUM('Open','Pending','In Progress','Resolved','Closed') DEFAULT 'Open',
    asset_id INT,
    employee_name VARCHAR(150),
    assigned_to VARCHAR(100),
    resolved_by VARCHAR(100),
    resolution TEXT,
    resolved_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL
);

-- ============================================================
-- NETWORK DEVICES
-- ============================================================
CREATE TABLE network_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostname VARCHAR(100),
    ip_address VARCHAR(45) NOT NULL,
    mac_address VARCHAR(20),
    device_type VARCHAR(50),
    manufacturer VARCHAR(100),
    location VARCHAR(150),
    status ENUM('Online','Offline','Unknown') DEFAULT 'Unknown',
    last_seen DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150),
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- AUDIT LOG
-- ============================================================
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT 0,
    user_name VARCHAR(100),
    user_role VARCHAR(50),
    company VARCHAR(100),
    action VARCHAR(200) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
