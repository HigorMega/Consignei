<?php
/**
 * API DE RELATÓRIO - VERSÃO FINAL (CORREÇÃO DE COLLATION + CÓDIGO)
 * Resolve o erro "Illegal mix of collations" forçando o padrão na consulta.
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");

require_once '../db/conexao.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$loja_id = $_SESSION['loja_id'] ?? 1;

$lote_id = isset($_GET['lote_id']) ? intval($_GET['lote_id']) : 0;

if (!$lote_id) { echo json_encode(['success' => false, 'error' => 'Lote não informado']); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM lotes WHERE id = ? AND loja_id = ?");
    $stmt->execute([$lote_id, $loja_id]);
    $lote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lote) throw new Exception("Lote não encontrado.");

    // TRUQUE TÉCNICO: Usamos 'COLLATE utf8mb4_general_ci' para evitar erro de mistura de collations
    $sql = "
        SELECT 
            li.codigo_produto, 
            li.nome, 
            li.preco_custo, 
            li.preco_venda, 
            li.quantidade as qtd_entrada,
            COALESCE(p.quantidade, 0) as qtd_estoque_atual
        FROM lote_itens li
        LEFT JOIN produtos p ON (
            p.codigo_produto COLLATE utf8mb4_general_ci = li.codigo_produto COLLATE utf8mb4_general_ci 
            AND p.loja_id = :loja_id
        )
        WHERE li.lote_id = :lote_id AND li.status = 'aprovado'
        ORDER BY li.nome ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['loja_id' => $loja_id, 'lote_id' => $lote_id]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $relatorio = [];
    $resumo = ['total_entrada_valor' => 0, 'total_vendido_valor' => 0, 'total_devolucao_valor' => 0, 'qtd_entrada' => 0, 'qtd_vendida' => 0, 'qtd_devolver' => 0];

    foreach ($itens as $item) {
        $qtd_entrada = intval($item['qtd_entrada']);
        $qtd_atual   = intval($item['qtd_estoque_atual']);
        
        // Se estoque atual for menor que a entrada, a diferença é venda.
        // Se estoque for maior (ex: adicionou manualmente depois), venda é 0.
        $qtd_vendida = max(0, $qtd_entrada - $qtd_atual);
        
        // Se vendeu mais do que entrou (erro de estoque), ajusta para não devolver negativo
        $qtd_devolver = max(0, $qtd_entrada - $qtd_vendida);

        // Se quiser usar o estoque real como devolução (mesmo que seja maior que a entrada):
        // $qtd_devolver = $qtd_atual; 

        $a_pagar = $qtd_vendida * $item['preco_custo'];
        $valor_estoque = $qtd_devolver * $item['preco_custo'];

        $resumo['total_vendido_valor'] += $a_pagar;
        $resumo['total_devolucao_valor'] += $valor_estoque;
        $resumo['qtd_vendida'] += $qtd_vendida;
        $resumo['qtd_devolver'] += $qtd_devolver;

        $relatorio[] = [
            'codigo' => $item['codigo_produto'],
            'nome' => $item['nome'],
            'custo_unit' => $item['preco_custo'],
            'entrada' => $qtd_entrada,
            'estoque' => $qtd_devolver, // Mostramos o que deve ser devolvido
            'vendido' => $qtd_vendida,
            'total_pagar' => $a_pagar
        ];
    }

    echo json_encode(['success' => true, 'lote' => $lote, 'itens' => $relatorio, 'resumo' => $resumo]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>