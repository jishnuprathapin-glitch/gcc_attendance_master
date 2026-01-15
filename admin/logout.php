<?php

session_start();
session_destroy();
header('Location: /HRSmart/index.php');
exit;

?>
