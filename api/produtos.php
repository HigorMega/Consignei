<?php
session_start();
header('Content-Type: application/json');
include "../db/conexao.php";

// 1. Inicializa a variável
$loja_id = null;

// 2. Verifica se veio o ID pela URL (para acesso público)
if (isset($_GET['loja_id'])) {
    $loja_id = $_GET['loja_id'];
} 
// 3. Se não veio pela URL, tenta pegar da sessão (para acesso do admin)
elseif (isset($_SESSION['loja_id'])) {
    $loja_id = $_SESSION['loja_id'];
}

// Se não achou nenhum ID, retorna vazio
if (!$loja_id) {
    echo json_encode([]);
    exit;
}

try {
    // 4. Busca apenas os produtos que pertencem a esta loja
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE loja_id = ? ORDER BY id DESC");
    $stmt->execute([$loja_id]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($produtos);

} catch (PDOException $e) {
    echo json_encode([]);
}
?>