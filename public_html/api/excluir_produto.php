<?php
// api/excluir_produto.php - Atualizado
header("Content-Type: application/json; charset=UTF-8");
require_once '../db/conexao.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id) {
    try {
        // 1. Busca o nome da imagem para remover do servidor
        $stmt = $pdo->prepare("SELECT imagem FROM produtos WHERE id = ?");
        $stmt->execute([$id]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Apaga o arquivo físico se existir
        if ($produto && !empty($produto['imagem'])) {
            // Ajuste no caminho para garantir que localize a pasta public/uploads
            $caminho = __DIR__ . "/../public/uploads/" . $produto['imagem'];
            
            if (file_exists($caminho) && !is_dir($caminho) && strpos($produto['imagem'], 'http') === false) {
                @unlink($caminho); // @ oculta erros caso o arquivo esteja preso por outro processo
            }
        }

        // 3. Apaga o registro do banco de dados
        $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
}
?>