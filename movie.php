<?php
$_GET['id'] = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
$_GET['play'] = 1;
require __DIR__ . '/filme/index.php';
