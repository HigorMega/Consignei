<?php
// api/get_sessao.php
session_start();
header('Content-Type: application/json');

// Verifica se a variável de sessão 'logado' existe e é verdadeira
if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
    echo json_encode([
        'logado' => true,
        'nome' => $_SESSION['nome'] ?? 'Lojista',
        'loja_id' => $_SESSION['loja_id']
    ]);
} else {
    // Se não tiver sessão, retorna logado: false
    echo json_encode(['logado' => false]);
}
?>