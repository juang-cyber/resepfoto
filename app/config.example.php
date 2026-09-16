<?php
// Salin file ini menjadi config.php di server (JANGAN di-commit ke GitHub).
// Buat hash admin: php -r "echo password_hash('KODE_ADMIN_KAMU', PASSWORD_DEFAULT);"
define('ADMIN_USER', 'admin');
define('ADMIN_HASH', '$2y$10$GANTI_DENGAN_HASH_KAMU');
define('DEMO_MEMBER', ['demo', 'Member Demo', 'RF-GANTI-0000']);
define('DB_FILE', 'rf-GANTI-NAMA-ACAK.sqlite');
