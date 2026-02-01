<?php
// Arquivo: api/register.php
header('Content-Type: application/json');

// Desativa exibição de erros na tela para não quebrar o JSON
error_reporting(0);
ini_set('display_errors', 0);

require_once "../db/conexao.php";
require_once "enviar_email.php"; 

// Recebe dados via JSON ou POST
$input = json_decode(file_get_contents('php://input'), true);
$nome_loja = $input['nome_loja'] ?? $_POST['nome_loja'] ?? '';
$nome_resp = $input['nome_responsavel'] ?? $_POST['nome_responsavel'] ?? $nome_loja;
$email     = $input['email'] ?? $_POST['email'] ?? '';
$senha     = $input['senha'] ?? $_POST['senha'] ?? '';

// 1. Validação Básica
if (empty($nome_loja) || empty($email) || empty($senha)) {
    echo json_encode(['success' => false, 'message' => 'Preencha todos os campos obrigatórios.']);
    exit;
}

try {
    // 2. Verifica se o e-mail já existe
    $stmtCheck = $pdo->prepare("SELECT id FROM lojas WHERE email = ?");
    $stmtCheck->execute([$email]);
    
    if ($stmtCheck->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Este e-mail já está cadastrado.']);
        exit;
    }

    // 3. Prepara os dados de segurança
    $hashSenha = password_hash($senha, PASSWORD_DEFAULT);
    $token     = bin2hex(random_bytes(32)); 

    // 4. Insere na tabela LOJAS (Apenas colunas confirmadas no seu banco)
    $sql = "INSERT INTO lojas (nome_loja, email, senha, email_confirmado, token_ativacao) VALUES (?, ?, ?, 0, ?)";
    $stmt = $pdo->prepare($sql);
    
    if ($stmt->execute([$nome_loja, $email, $hashSenha, $token])) {
        
        $novoId = $pdo->lastInsertId();

        // 5. Salva o nome do responsável na tabela de configurações (onde a coluna já existe)
        try {
            $sqlConfig = "INSERT INTO configuracoes (loja_id, nome_loja, nome_vendedor) VALUES (?, ?, ?)";
            $stmtConfig = $pdo->prepare($sqlConfig);
            $stmtConfig->execute([$novoId, $nome_loja, $nome_resp]);
        } catch (Exception $e) {
            // Ignora erro aqui para não travar o cadastro principal
        }
        
        // 6. Envia o E-mail de Ativação
        $enviou = enviarEmailAtivacao($email, $nome_resp, $token);

        echo json_encode([
            'success' => true, 
            'message' => 'Cadastro realizado!',
            'dados' => [
                'id' => $novoId,
                'email' => $email
            ]
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar os dados no banco.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro no banco: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}
?>