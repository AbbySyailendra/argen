<?php
// Salin file ini menjadi config.local.php lalu isi sesuai server. config.local.php tidak ikut git.
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'argen');
define('DB_PASS', 'ganti-dengan-sandi-database');
define('DB_NAME', 'argen');
define('DB_PORT', 3306);
define('OLLAMA_URL', 'http://127.0.0.1:11434');
define('SETUP_KEY', 'isi-teks-acak-panjang');   // dipakai untuk membuat admin pertama lewat web (atau: php adduser.php admin)
define('DEBUG', '0');
