<?php
require_once 'db.php';

// Session များအားလုံးကို ဖျက်ဆီးခြင်း
$_SESSION = array();
session_destroy();

// Login Page သို့ ပြန်လည်ပို့ဆောင်ခြင်း
header("Location: guest.php");
exit();
?>