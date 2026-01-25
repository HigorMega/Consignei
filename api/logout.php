<?php
// api/logout.php
session_start();
session_destroy(); // Destrói todas as sessões
header("Location: ../public/Index.html"); // Redireciona para o login
exit;
?>