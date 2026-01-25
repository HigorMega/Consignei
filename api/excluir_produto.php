<?php
// api/excluir_produto.php - Com limpeza de imagem

header("Content-Type: application/json; charset=UTF-8");
require_once '../db/conexao.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id) {
    try {
        // 1. Busca o nome da imagem
        $stmt = $pdo->prepare("SELECT imagem FROM produtos WHERE id = ?");
        $stmt->execute([$id]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Apaga o arquivo físico se existir
        if ($produto && !empty($produto['imagem'])) {
            $caminho = "../public/uploads/" . $produto['imagem'];
            // Verifica se não é uma imagem padrão ou URL externa
            if (file_exists($caminho) && strpos($produto['imagem'], 'http') === false) {
                unlink($caminho); // <--- O comando mágico que deleta o arquivo
            }
        }

        // 3. Apaga o registro do banco
        $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
}
?>