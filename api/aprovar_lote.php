<?php
/**
 * API DE IMPORTAÇÃO PARA LOTE - VERSÃO HÍBRIDA (VPS OCR + MANUAL)
 * - Suporta inserção Manual (botão + Novo Produto).
 * - Suporta Scanner via VPS (sua lógica original).
 * - Suporta Edição e Exclusão.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");

// Se for requisição OPTIONS (Pre-flight do navegador), encerra aqui
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../db/conexao.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$loja_id = $_SESSION['loja_id'] ?? 1;

$method = $_SERVER['REQUEST_METHOD'];

// ===================================================================================
//  PUT: ATUALIZAR ITEM (EDIÇÃO DE QUALQUER CAMPO)
// ===================================================================================
if ($method === 'PUT') {
    $dados = json_decode(file_get_contents("php://input"), true);
    $id = $dados['id'] ?? 0;

    if ($id) {
        $campos = [];
        $valores = [];

        // Monta a query dinamicamente
        if (isset($dados['codigo_produto'])) { $campos[] = "codigo_produto = ?"; $valores[] = $dados['codigo_produto']; }
        if (isset($dados['nome']))           { $campos[] = "nome = ?";            $valores[] = $dados['nome']; }
        if (isset($dados['preco_custo']))    { $campos[] = "preco_custo = ?";     $valores[] = floatval($dados['preco_custo']); }
        if (isset($dados['preco_venda']))    { $campos[] = "preco_venda = ?";     $valores[] = floatval($dados['preco_venda']); }
        if (isset($dados['quantidade']))     { $campos[] = "quantidade = ?";      $valores[] = intval($dados['quantidade']); }
        if (isset($dados['categoria_id']))   { $campos[] = "categoria_id = ?";    $valores[] = intval($dados['categoria_id']); }

        if (!empty($campos)) {
            $valores[] = $id; // O ID entra por último para o WHERE
            try {
                $sql = "UPDATE lote_itens SET " . implode(', ', $campos) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($valores);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => true, 'message' => 'Nada para atualizar']);
        }
    }
    exit;
}

// ===================================================================================
//  GET: LISTAR ITENS DO LOTE
// ===================================================================================
if ($method === 'GET') {
    $lote_id = isset($_GET['lote_id']) ? intval($_GET['lote_id']) : 0;
    
    $stmt = $pdo->prepare("SELECT * FROM lote_itens WHERE lote_id = ? ORDER BY id DESC");
    $stmt->execute([$lote_id]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCusto = 0; $totalVenda = 0; $count = 0;
    foreach ($itens as $item) {
        $qtd = ($item['quantidade'] > 0) ? $item['quantidade'] : 1;
        $totalCusto += (floatval($item['preco_custo']) * $qtd);
        $totalVenda += (floatval($item['preco_venda']) * $qtd);
        $count += $qtd;
    }

    echo json_encode([
        'itens' => $itens,
        'resumo' => [
            'qtd' => $count, 
            'custo' => $totalCusto, 
            'venda' => $totalVenda, 
            'lucro' => $totalVenda - $totalCusto
        ]
    ]);
    exit;
}

// ===================================================================================
//  POST: ADICIONAR (MANUAL OU SCANNER)
// ===================================================================================
elseif ($method === 'POST') {
    // Tenta ler JSON primeiro (caso o JS envie JSON no manual)
    $inputJSON = json_decode(file_get_contents("php://input"), true);
    if ($inputJSON) $_POST = array_merge($_POST, $inputJSON);

    $lote_id = $_POST['lote_id'] ?? 0;
    $margem  = floatval($_POST['margem'] ?? 100);
    $imagem_base64 = $_POST['imagem'] ?? null;
    $nome_manual = $_POST['nome'] ?? null;

    if (!$lote_id) {
        echo json_encode(['success' => false, 'error' => 'ID do lote obrigatório']);
        exit;
    }

    // --- CENÁRIO 1: CADASTRO MANUAL (SEM IMAGEM) ---
    if (empty($imagem_base64) && !empty($nome_manual)) {
        $custo = floatval($_POST['custo'] ?? 0);
        $venda = floatval($_POST['venda'] ?? 0);
        $codigo = $_POST['codigo'] ?? '';

        // Se não tiver código, gera um aleatório
        if (empty($codigo)) {
            $codigo = 'MANUAL-' . strtoupper(substr(md5(uniqid()), 0, 4));
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO lote_itens (lote_id, codigo_produto, nome, preco_custo, preco_venda, quantidade, foto_temp, status) VALUES (?, ?, ?, ?, ?, 1, 'manual_placeholder.png', 'pendente')");
            $stmt->execute([$lote_id, $codigo, $nome_manual, $custo, $venda]);
            echo json_encode(['success' => true, 'message' => 'Item manual adicionado', 'qtd' => 1]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // --- CENÁRIO 2: SCANNER VPS (COM IMAGEM) ---
    if ($imagem_base64) {
        try {
            // Configuração do seu VPS
            $vps_url = "http://72.60.8.21/ocr_api.php";
            $token = "85e8a9c0"; 

            // Função para enviar ao VPS
            function conectarVPS($base64, $url, $token) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['imagem' => $base64]),
                    CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: $token"],
                    CURLOPT_TIMEOUT => 60
                ]);
                $res = curl_exec($ch);
                
                if (curl_errno($ch)) {
                    throw new Exception('Erro Curl: ' . curl_error($ch));
                }
                
                curl_close($ch);
                return json_decode($res, true);
            }

            $ocr = conectarVPS($imagem_base64, $vps_url, $token);
            
            if (!$ocr || !isset($ocr['text'])) {
                throw new Exception("VPS não retornou texto ou falhou.");
            }

            $texto_bruto = $ocr['text'];
            $linhas = explode("\n", $texto_bruto);
            $contador = 0;

            // --- SEU ALGORITMO DE PROCESSAMENTO DE TEXTO ---
            foreach ($linhas as $linha) {
                $linha = trim($linha);
                if (strlen($linha) < 5) continue;

                $partes = preg_split('/\s+/', $linha);
                if (count($partes) < 2) continue;

                // A: CÓDIGO
                $codigo_bruto = $partes[0];
                $codigo = preg_replace('/[^A-Z0-9\-]/', '', $codigo_bruto);
                
                // Correção Q/O -> 0
                if (strlen($codigo) >= 4) {
                    if (preg_match('/^([A-Z]{3})([QO])(.*)$/', $codigo, $matches)) {
                        $codigo = $matches[1] . '0' . $matches[3];
                    }
                }

                $codigoValido = false;
                if (strlen($codigo) >= 4) {
                    $codigoValido = true;
                    array_shift($partes);
                } else {
                    // Tenta juntar partes se o código quebrou
                    $codigo_tentativa = $partes[0] . ($partes[1] ?? '');
                    $codigo_tentativa = preg_replace('/[^A-Z0-9\-]/', '', $codigo_tentativa);
                     if (preg_match('/^([A-Z]{3})([QO])(.*)$/', $codigo_tentativa, $m)) {
                         $codigo_tentativa = $m[1] . '0' . $m[3];
                     }
                     if (strlen($codigo_tentativa) >= 5 && preg_match('/[0-9]/', $codigo_tentativa)) {
                         $codigo = $codigo_tentativa;
                         $codigoValido = true;
                         array_shift($partes);
                         if(isset($partes[0])) array_shift($partes);
                     }
                }

                if (!$codigoValido) $codigo = "SCAN-" . strtoupper(substr(md5(uniqid()), 0, 5));

                // B: PREÇOS
                $precos_encontrados = [];
                $indices_para_remover = [];

                foreach ($partes as $idx => $palavra) {
                    if (preg_match('/[0-9]{1,3}(?:[\.,][0-9]{3})*[\.,][0-9]{2}/', $palavra)) {
                        $precos_encontrados[] = $palavra;
                        $indices_para_remover[] = $idx;
                    }
                }

                if (empty($precos_encontrados)) continue;

                $preco_string = $precos_encontrados[0];
                $custo = (float)str_replace(['.', ','], ['', '.'], $preco_string);

                if ($custo <= 0) continue;

                // C: NOME
                foreach ($indices_para_remover as $idx) { unset($partes[$idx]); }
                
                $nome_sujo = implode(' ', $partes);
                $nome = preg_replace('/\s(UN|PC|PÇ)\s?.*$/i', '', $nome_sujo); 
                $nome = preg_replace('/[\|_]/', '', $nome);
                $nome = trim($nome);
                
                if (strlen($nome) < 3) $nome = "Item Detectado";

                // INSERÇÃO NO BANCO
                $venda = $custo * (1 + ($margem / 100));

                try {
                    $stmt = $pdo->prepare("INSERT INTO lote_itens (lote_id, codigo_produto, nome, preco_custo, preco_venda, foto_temp, status) VALUES (?, ?, ?, ?, ?, 'manual_placeholder.png', 'pendente')");
                    $stmt->execute([$lote_id, $codigo, $nome, $custo, $venda]);
                    $contador++;
                } catch (Exception $e) {
                    continue;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Processado via VPS. $contador itens inseridos.",
                'qtd' => $contador
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Dados insuficientes (envie nome ou imagem).']);
    exit;
}

// ===================================================================================
//  DELETE: REMOVER ITEM (SINGLE OU MASS)
// ===================================================================================
elseif ($method === 'DELETE') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    // Suporte para exclusão em massa (?ids=1,2,3)
    if (isset($_GET['ids'])) {
        $ids = explode(',', $_GET['ids']);
        $ids = array_map('intval', $ids); // Sanitiza
        $in  = str_repeat('?,', count($ids) - 1) . '?';
        try {
            $stmt = $pdo->prepare("DELETE FROM lote_itens WHERE id IN ($in)");
            $stmt->execute($ids);
            echo json_encode(['success' => true]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM lote_itens WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    exit;
}
?>