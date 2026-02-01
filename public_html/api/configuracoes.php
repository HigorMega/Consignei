<?php
// Arquivo: api/configuracoes.php (ATUALIZADO)
// - Editar nome NÃO altera slug automaticamente
// - Slug só muda se vier no JSON (campo "slug")
// - Valida formato e unicidade do slug
// - Detecta chave da tabela lojas (id ou loja_id) e valida rowCount()

header('Content-Type: application/json; charset=UTF-8');
session_start();
require_once "../db/conexao.php";

if (!isset($_SESSION['loja_id']) || empty($_SESSION['loja_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit;
}

$loja_id = (int)$_SESSION['loja_id'];

function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function isValidSlug(string $slug): bool {
    if ($slug === '') return false;
    if (strlen($slug) > 120) return false;
    return (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
}

$hasSlug = columnExists($pdo, 'lojas', 'slug');

// Detecta qual coluna chave usar na tabela lojas
$lojasKeyCol = null;
if (columnExists($pdo, 'lojas', 'id')) {
    $lojasKeyCol = 'id';
} elseif (columnExists($pdo, 'lojas', 'loja_id')) {
    $lojasKeyCol = 'loja_id';
}

/**
 * GET: retorna configurações + slug (se existir)
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM configuracoes WHERE loja_id = ? LIMIT 1");
        $stmt->execute([$loja_id]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // adiciona slug atual da loja (se existir e tiver chave detectada)
        if ($hasSlug && $lojasKeyCol) {
            $stmt2 = $pdo->prepare("SELECT slug FROM lojas WHERE `$lojasKeyCol` = ? LIMIT 1");
            $stmt2->execute([$loja_id]);
            $row = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['slug'])) {
                $config['slug'] = $row['slug'];
            }
        }

        // monta URL bonita (ajuda no front)
        $host = $_SERVER['HTTP_HOST'] ?? 'consigneiapp.com.br';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        if (!empty($config['slug'])) {
            $config['vitrine_url'] = $scheme . '://' . $host . '/vitrine/' . $config['slug'];
        }

        echo json_encode($config ?: new stdClass(), JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro no banco.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * POST: salva configurações
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
        exit;
    }

    $nome_loja = trim((string)($data['nome_loja'] ?? ''));
    $whatsapp  = trim((string)($data['whatsapp'] ?? ''));
    $vendedor  = trim((string)($data['vendedor'] ?? '')); // no banco é nome_vendedor
    $instagram = trim((string)($data['instagram'] ?? ''));
    $tema      = trim((string)($data['tema'] ?? 'marble'));

    // slug é OPCIONAL — só altera se vier
    $newSlug = null;
    if (array_key_exists('slug', $data)) {
        $newSlug = strtolower(trim((string)$data['slug']));
        $newSlug = preg_replace('/[^a-z0-9-]/', '', $newSlug);
    }

    try {
        $pdo->beginTransaction();

        // 1) Upsert em configuracoes
        $check = $pdo->prepare("SELECT id FROM configuracoes WHERE loja_id = ? LIMIT 1");
        $check->execute([$loja_id]);

        if ($check->fetch(PDO::FETCH_ASSOC)) {
            $sql = "UPDATE configuracoes SET
                        nome_loja = ?,
                        whatsapp = ?,
                        nome_vendedor = ?,
                        instagram = ?,
                        tema = ?
                    WHERE loja_id = ?";
            $stmt = $pdo->prepare($sql);
            $okConfig = $stmt->execute([$nome_loja, $whatsapp, $vendedor, $instagram, $tema, $loja_id]);
        } else {
            $sql = "INSERT INTO configuracoes (loja_id, nome_loja, whatsapp, nome_vendedor, instagram, tema)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $okConfig = $stmt->execute([$loja_id, $nome_loja, $whatsapp, $vendedor, $instagram, $tema]);
        }

        if (!$okConfig) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao salvar configurações.']);
            exit;
        }

        // 2) Se slug veio no JSON, valida e atualiza na tabela lojas
        if ($newSlug !== null) {
            if (!$hasSlug) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Seu banco ainda não tem a coluna slug. Rode: ALTER TABLE lojas ADD COLUMN slug VARCHAR(120) UNIQUE;'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!$lojasKeyCol) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Não encontrei a coluna chave na tabela lojas (id ou loja_id).'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!isValidSlug($newSlug)) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Slug inválido. Use apenas letras minúsculas, números e hífen (ex: minha-loja).'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Verifica se já está em uso por outra loja
            $chkSlug = $pdo->prepare("SELECT `$lojasKeyCol` FROM lojas WHERE slug = ? AND `$lojasKeyCol` <> ? LIMIT 1");
            $chkSlug->execute([$newSlug, $loja_id]);

            if ($chkSlug->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Esse link (slug) já está sendo usado por outra loja. Escolha outro.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $updSlug = $pdo->prepare("UPDATE lojas SET slug = ? WHERE `$lojasKeyCol` = ? LIMIT 1");
            $okSlug = $updSlug->execute([$newSlug, $loja_id]);

            // ✅ AQUI: garante que realmente atualizou UMA linha
            if (!$okSlug || $updSlug->rowCount() < 1) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Não foi possível salvar o slug: nenhuma loja foi encontrada com $lojasKeyCol = $loja_id."
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $pdo->commit();

        // Atualiza nome na sessão para refletir no painel (sidebar etc.)
        if ($nome_loja !== '') {
            $_SESSION['nome'] = $nome_loja;
        }

        // Retorna slug atual
        $currentSlug = null;
        if ($hasSlug && $lojasKeyCol) {
            $s = $pdo->prepare("SELECT slug FROM lojas WHERE `$lojasKeyCol` = ? LIMIT 1");
            $s->execute([$loja_id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            $currentSlug = $row['slug'] ?? null;
        }

        echo json_encode([
            'success' => true,
            'slug' => $currentSlug
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro interno.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);