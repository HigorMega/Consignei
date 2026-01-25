<?php
// Arquivo: db/conexao.php

// DADOS DA HOSTINGER (Você pega isso no painel Banco de Dados)
$host = 'localhost'; // Na Hostinger geralmente é localhost mesmo
$db   = 'u988485852_consigneiapp'; // O NOME COMPLETO do banco que você criou
$user = 'u988485852_consigneiapp';     // O USUÁRIO COMPLETO que você criou
$pass = '85E8a9c0-';    // A senha que você criou

$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Em produção, não mostre o erro real para o usuário
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>