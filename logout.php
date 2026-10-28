<?php
define('ARGEN_PUBLIC', true);
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') auth_logout();   // POST + cek asal (CSRF) agar tidak bisa dipicu dari situs lain
header('Location: login.php');
