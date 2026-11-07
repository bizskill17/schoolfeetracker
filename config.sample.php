<?php
// Database settings – EXAMPLE ONLY. Never commit the real file.
//
// The installer (yoursite/install/) creates this for you. To create it by hand on Hostinger:
//   1. Copy this file to ONE FOLDER ABOVE the app and name it  schoolfee-config.php
//      e.g. app in /domains/yourschool.in/public_html  →  /domains/yourschool.in/schoolfee-config.php
//      (outside public_html, so it can never be opened from a browser)
//   2. Fill in the values from hPanel → Databases → MySQL Databases.
// Alternatively set environment variables DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD.
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'u000000000_dbname',
    'db_user' => 'u000000000_dbuser',
    'db_pass' => 'your-database-password',
    'timezone' => 'Asia/Kolkata',
    'debug' => false,   // true only while troubleshooting
];
