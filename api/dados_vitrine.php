<?php
// Arquivo: api/dados_vitrine.php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");

// Desativa exibição de erros no HTML
error_reporting(0);
ini_set('display_errors', 0);

require_once "../db/conexao.php";

$loja_id = isset($_GET['loja']) ? (int)$_GET['loja'] : 0;

if ($loja_id <= 0) {
    echo json_encode(['error' => 'Loja não informada']);
    exit;
}

try {
    // 1. Configurações (Onde estão WhatsApp e Instagram geralmente)
    $stmtConfig = $pdo->prepare("SELECT * FROM configuracoes WHERE loja_id = ?");
    $stmtConfig->execute([$loja_id]);
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);

    // 2. Dados da Loja (CORREÇÃO: Buscando APENAS colunas que existem na tabela 'lojas')
    $stmtLoja = $pdo->prepare("SELECT nome_loja, email FROM lojas WHERE id = ?");
    $stmtLoja->execute([$loja_id]);
    $dadosLoja = $stmtLoja->fetch(PDO::FETCH_ASSOC);

    if (!$dadosLoja) {
        echo json_encode(['error' => 'Loja não encontrada']);
        exit;
    }

    // 3. Monta o objeto final da Loja
    $lojaFinal = [
        'nome_loja' => $config['nome_loja'] ?? $dadosLoja['nome_loja'] ?? 'Loja',
        'whatsapp'  => $config['whatsapp'] ?? '', // Pega da tabela configuracoes
        'instagram' => $config['instagram'] ?? '', // Pega da tabela configuracoes
        'vendedor'  => $config['nome_vendedor'] ?? '',
        'tema'      => $config['tema'] ?? 'rose'
    ];

    // 4. Produtos (JavaScript antigo vai ler as categorias daqui)
    $sqlProd = "SELECT id, codigo_produto, nome, preco, imagem, categoria, quantidade 
                FROM produtos 
                WHERE loja_id = ? AND quantidade > 0 
                ORDER BY id DESC";
    $stmtProd = $pdo->prepare($sqlProd);
    $stmtProd->execute([$loja_id]);
    $produtos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    // Retorna exatamente o formato que seu JS antigo espera
    echo json_encode([
        'loja' => $lojaFinal,
        'produtos' => $produtos
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro DB: ' . $e->getMessage()]);
}
?>