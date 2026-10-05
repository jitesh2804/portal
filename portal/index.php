<?php
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    redirect('/login.php');
}

redirect(roleHome($_SESSION['role'] ?? ''));
