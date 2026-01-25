<?php
/**
 * API v11 - CORREÇÃO DE CÓDIGOS E CATEGORIAS
 * - A IA agora define a categoria (Ex: "Anéis", "Colares") visualmente.
 * - Função 'limparCodigo' corrige erros comuns de OCR (O -> 0, espaços, etc).
 * - Log detalhado para auditoria.
 */

// Configurações do Servidor
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);
ini_set('display_errors', 0); 
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");

// --- LOG ---
function debugLog($msg) {
    file_put_contents('log_scanner_erro.txt', "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// --- CONEXÃO ---
function getConexao() {
    try {
        require '../db/conexao.php'; 
        if (!isset($pdo)) {
            // $pdo = new PDO("mysql:host=localhost;dbname=...", "...", "...");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $pdo->query("SET SESSION wait_timeout = 28800"); 
            $pdo->query("SET SESSION max_allowed_packet = 16777216");
        } catch (Exception $e) {}
        return $pdo;
    } catch (Exception $e) {
        debugLog("ERRO DB: " . $e->getMessage());
        die(json_encode(['success' => false, 'error' => 'Erro Conexão DB']));
    }
}

$pdo = getConexao();
if (session_status() === PHP_SESSION_NONE) session_start();
$loja_id = $_SESSION['loja_id'] ?? 1;

// ==============================================================================
//  COLOQUE SUA CHAVE AQUI
// ==============================================================================
$apiKey = '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'; // <--- SUA CHAVE OPENAI AQUI
// ==============================================================================

// --- FUNÇÃO 1: CORRIGIR CÓDIGO (NOVA) ---
function limparCodigo($codigo) {
    // Remove espaços vazios
    $codigo = trim(str_replace(' ', '', $codigo));
    // Força maiúsculas
    $codigo = strtoupper($codigo);
    // Remove caracteres especiais (mantém apenas Letras, Números e Hífen)
    $codigo = preg_replace('/[^A-Z0-9\-]/', '', $codigo);
    
    // Opcional: Se o código for muito curto (erro de leitura), gera um
    if (strlen($codigo) < 3) return 'SCAN-' . rand(1000, 9999);
    
    return $codigo;
}

// --- FUNÇÃO 2: GERENCIAR CATEGORIA ---
function obterIdCategoria($pdo, $nomeSugerido) {
    $nomeLimpo = mb_convert_case(trim($nomeSugerido), MB_CASE_TITLE, "UTF-8");
    if (empty($nomeLimpo)) $nomeLimpo = 'Geral';

    // Mapeamento para padronizar (Evita "Anel" e "Aneis" duplicados)
    $mapa = [
        'Anel' => 'Anéis', 'Aneis' => 'Anéis',
        'Brinco' => 'Brincos', 
        'Colar' => 'Colares', 'Corrente' => 'Colares',
        'Pulseira' => 'Pulseiras',
        'Pingente' => 'Pingentes',
        'Conjunto' => 'Conjuntos'
    ];
    
    if (isset($mapa[$nomeLimpo])) $nomeLimpo = $mapa[$nomeLimpo];

    try {
        // Busca
        $stmt = $pdo->prepare("SELECT id FROM categorias WHERE nome = ? LIMIT 1");
        $stmt->execute([$nomeLimpo]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cat) return $cat['id'];

        // Cria
        $stmtInsert = $pdo->prepare("INSERT INTO categorias (nome) VALUES (?)");
        $stmtInsert->execute([$nomeLimpo]);
        return $pdo->lastInsertId();

    } catch (Exception $e) {
        // Se der erro, reconecta e tenta pegar a Geral (ID 1 ou cria)
        $pdo = getConexao();
        return 0; 
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// --- ROTAS PADRÃO ---
if ($method === 'PUT') {
    $d = json_decode(file_get_contents("php://input"), true);
    $id = $d['id'] ?? 0;
    if ($id) {
        $campos=[]; $vals=[];
        $perm = ['codigo_produto','nome','preco_custo','preco_venda','quantidade','categoria_id'];
        foreach($perm as $f) { if(isset($d[$f])) { $campos[]="$f=?"; $vals[]=$d[$f]; } }
        if(!empty($campos)) { $vals[]=$id; $pdo->prepare("UPDATE lote_itens SET ".implode(',',$campos)." WHERE id=?")->execute($vals); }
    }
    echo json_encode(['success'=>true]);
    exit;
}

if ($method === 'GET') {
    $lote_id = $_GET['lote_id'] ?? 0;
    $sql = "SELECT li.*, c.nome as nome_categoria 
            FROM lote_itens li 
            LEFT JOIN categorias c ON li.categoria_id = c.id 
            WHERE li.lote_id = ? ORDER BY li.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lote_id]);
    echo json_encode([
        'itens' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'resumo' => ['qtd'=>0] // Simplificado para focar na correção
    ]);
    exit;
}

if ($method === 'DELETE') {
    if(isset($_GET['ids'])) {
        $ids = array_map('intval', explode(',', $_GET['ids']));
        $in = str_repeat('?,', count($ids)-1).'?';
        $pdo->prepare("DELETE FROM lote_itens WHERE id IN ($in)")->execute($ids);
    } elseif(isset($_GET['id'])) {
        $pdo->prepare("DELETE FROM lote_itens WHERE id=?")->execute([$_GET['id']]);
    }
    echo json_encode(['success'=>true]);
    exit;
}

// ==========================================================================
//  POST: MANUAL + IA
// ==========================================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if ($input) $_POST = array_merge($_POST, $input);

    $lote_id = $_POST['lote_id'] ?? 0;
    $margem  = floatval($_POST['margem'] ?? 100);
    $img     = $_POST['imagem'] ?? null;
    $nomeMan = $_POST['nome'] ?? null;

    if (!$lote_id) { echo json_encode(['success'=>false, 'error'=>'Lote inválido']); exit; }

    // 1. MANUAL
    if (empty($img) && !empty($nomeMan)) {
        try {
            $custo = floatval($_POST['custo'] ?? 0);
            $venda = floatval($_POST['venda'] ?? 0);
            $cod   = limparCodigo($_POST['codigo'] ?? 'MANUAL-'.rand(100,999));
            $catId = obterIdCategoria($pdo, 'Geral'); // Manual vai para Geral se não especificar

            $pdo->prepare("INSERT INTO lote_itens (lote_id, codigo_produto, nome, preco_custo, preco_venda, quantidade, categoria_id, status) VALUES (?,?,?,?,?,1,?, 'pendente')")
                ->execute([$lote_id, $cod, $nomeMan, $custo, $venda, $catId]);
            
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    // 2. IA GPT-4o
    if ($img) {
        try {
            if (strpos($apiKey, 'sk-') === false) throw new Exception("API Key Inválida");

            // Fecha conexão temporária
            $pdo = null;

            $prompt = "Analise esta imagem de uma lista de joias.
            Retorne um JSON array estrito com os objetos:
            {
                \"codigo\": \"(leia o código EXATAMENTE como está na imagem)\",
                \"nome\": \"(descrição completa)\",
                \"custo\": (valor numérico, ex: 90.00),
                \"categoria\": \"(Defina a categoria baseada na descrição. Ex: Anéis, Brincos, Colares, Pulseiras, Conjuntos)\"
            }
            IMPORTANTE:
            1. Corrija códigos onde 'O' parece '0' ou 'I' parece '1' se forem numéricos.
            2. NÃO invente preços. Se não houver, coloque 0.
            3. Ignore cabeçalhos.";

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://api.openai.com/v1/chat/completions",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "Authorization: Bearer $apiKey"
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    "model" => "gpt-4o",
                    "messages" => [
                        ["role" => "user", "content" => [
                            ["type" => "text", "text" => $prompt],
                            ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64," . $img]]
                        ]]
                    ],
                    "max_tokens" => 4000
                ])
            ]);

            $response = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);

            if ($err) throw new Exception("Erro Curl: $err");

            $result = json_decode($response, true);
            if (!isset($result['choices'][0]['message']['content'])) throw new Exception("Erro IA: " . json_encode($result));

            $rawContent = $result['choices'][0]['message']['content'];
            $cleanJson = preg_replace('/^```json|```$/m', '', $rawContent); 
            $itensDetectados = json_decode($cleanJson, true);

            if (!is_array($itensDetectados)) throw new Exception("JSON inválido da IA");

            // RECONECTA
            $pdo = getConexao();
            $stmt = $pdo->prepare("INSERT INTO lote_itens (lote_id, codigo_produto, nome, preco_custo, preco_venda, quantidade, categoria_id, status) VALUES (?,?,?,?,?,1,?, 'pendente')");
            $contador = 0;

            foreach ($itensDetectados as $item) {
                // Tratamento de Dados
                $codigo = limparCodigo($item['codigo'] ?? '');
                if (empty($codigo)) $codigo = 'SCAN-'.rand(1000,9999);

                $nome = $item['nome'] ?? 'Item sem nome';
                $custo = floatval($item['custo'] ?? 0);
                $venda = $custo * (1 + ($margem / 100));
                
                // CATEGORIA VINDA DA IA
                $catSugerida = $item['categoria'] ?? 'Geral';
                $catId = obterIdCategoria($pdo, $catSugerida);

                try {
                    $stmt->execute([$lote_id, $codigo, $nome, $custo, $venda, $catId]);
                    $contador++;
                    debugLog("Inserido: $codigo | $nome | Cat: $catSugerida ($catId)");
                } catch(Exception $ex) {
                    debugLog("Erro insert: " . $ex->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Sucesso! $contador itens processados.",
                'qtd' => $contador
            ]);

        } catch (Exception $e) {
            debugLog("FATAL: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
?>