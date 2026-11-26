<?php
$_GET['id'] = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
$_GET['play'] = 0;
require __DIR__ . '/serie/index.php';
