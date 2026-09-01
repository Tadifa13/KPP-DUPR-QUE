<?php
require __DIR__ . '/ui/bootstrap.php';
auth_logout();
redirect('login.php');
