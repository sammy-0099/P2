<?php
$_GET['id'] = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
$_GET['t'] = max(1, (int)($_GET['t'] ?? 1));
$_GET['e'] = max(1, (int)($_GET['e'] ?? 1));
$_GET['play'] = 1;
require __DIR__ . '/serie/index.php';
