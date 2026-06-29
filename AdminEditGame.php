<?php
session_start();
if ($_SESSION['role'] !== 'admin') die("Access denied.");

$db = new mysqli("localhost", "root", "", "SquidPro");

$id = (int)$_GET['id'];
$db->query("DELETE FROM users WHERE id=$id");

header("Location: AdminUsers.php");
exit;
