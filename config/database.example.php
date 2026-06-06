<?php
return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'database' => getenv('DB_NAME') ?: 'sportlauf',
    'username' => getenv('DB_USER') ?: 'sportlauf_user',
    'password' => getenv('DB_PASS') ?: 'CHANGE_ME_STRONG_PASSWORD',
    'charset' => 'utf8mb4',
];
