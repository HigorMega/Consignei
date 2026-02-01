<?php
// Arquivo: api/configuracoes.php
header('Content-Type: application/json');
require_once "../db/conexao.php";
session_start();

// 1. Verifica se a sessão existe
if (!isset($_SESSION['loja_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit;
}

$loja_id = $_SESSION['loja_id'];

// 2. Verifica se é uma requisição de busca (GET) ou de salvamento (POST)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM configuracoes WHERE loja_id = ?");
        $stmt->execute([$loja_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Se não houver configurações ainda, retorna um objeto vazio padrão
        echo json_encode($config ?: new stdClass());
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recebe o JSON enviado pelo JavaScript
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
        exit;
    }

    // Mapeia os dados do JS para as colunas do banco
    $nome_loja = $data['nome_loja'] ?? '';
    $whatsapp  = $data['whatsapp'] ?? '';
    $vendedor  = $data['vendedor'] ?? ''; // No banco é 'nome_vendedor'
    $instagram = $data['instagram'] ?? '';
    $tema      = $data['tema'] ?? 'marble';

    try {
        // Verifica se já existe uma linha para essa loja
        $check = $pdo->prepare("SELECT id FROM configuracoes WHERE loja_id = ?");
        $check->execute([$loja_id]);

        if ($check->rowCount() > 0) {
            // ATUALIZA (Update)
            $sql = "UPDATE configuracoes SET 
                    nome_loja = ?, 
                    whatsapp = ?, 
                    nome_vendedor = ?, 
                    instagram = ?, 
                    tema = ? 
                    WHERE loja_id = ?";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([$nome_loja, $whatsapp, $vendedor, $instagram, $tema, $loja_id]);
        } else {
            // CRIA NOVO (Insert)
            $sql = "INSERT INTO configuracoes (loja_id, nome_loja, whatsapp, nome_vendedor, instagram, tema) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([$loja_id, $nome_loja, $whatsapp, $vendedor, $instagram, $tema]);
        }

        if ($success) {
            // Opcional: Atualiza o nome na sessão para refletir no sidebar imediatamente
            $_SESSION['nome'] = $nome_loja;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erro ao executar SQL.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro no banco: ' . $e->getMessage()]);
    }
    exit;
}