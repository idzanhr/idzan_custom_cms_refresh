<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    forget_this_device();
    $_SESSION = [];
    session_destroy();
}
redirect(admin_url('login.php'));
