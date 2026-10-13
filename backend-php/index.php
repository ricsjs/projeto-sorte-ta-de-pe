<?php
/**
 * POC - Numero da Sorte + E-book (versao PHP)
 * Front controller: atende a API em /api/* e serve o build do React (frontend/dist).
 *
 * Rodar local:  php -S 127.0.0.1:8000 index.php
 * Apache:       o .htaccess envia tudo para este arquivo.
 */

declare(strict_types=1);

require __DIR__ . '/src/db.php';
require __DIR__ . '/src/email.php';
require __DIR__ . '/src/planilha.php';
require __DIR__ . '/src/app.php';
require __DIR__ . '/src/asaas.php';
require __DIR__ . '/src/infinitepay.php';
require __DIR__ . '/src/operadores.php';

carregar_env();
date_default_timezone_set(env('TZ', 'America/Sao_Paulo'));

$metodo = $_SERVER['REQUEST_METHOD'];
$caminho = '/' . trim(rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/'), '/');

// CORS liberado para qualquer origem (o frontend em dev roda em outra porta)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($metodo === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// [metodo, padrao, funcao, acesso]  acesso: publico | operador | admin
$rotas = [
    ['POST', '#^/api/clientes/cadastro$#', 'rota_cadastro', 'publico'],
    ['POST', '#^/api/clientes/login$#', 'rota_login', 'publico'],
    ['POST', '#^/api/clientes/logout$#', 'rota_logout', 'publico'],
    ['GET', '#^/api/clientes/me$#', 'rota_minha_conta', 'publico'],          // exige sessao de cliente
    ['POST', '#^/api/clientes/me$#', 'rota_editar_cadastro', 'publico'],     // exige sessao de cliente
    ['GET', '#^/api/loja/status$#', 'rota_loja_status', 'publico'],
    ['POST', '#^/api/vendas$#', 'rota_criar_venda', 'publico'],             // exige sessao de cliente
    ['GET', '#^/api/vendas/(\d+)$#', 'rota_ver_venda', 'publico'],
    ['POST', '#^/api/vendas/(\d+)/simular-pagamento$#', 'rota_simular_pagamento', 'publico'],
    ['POST', '#^/api/webhook/infinitepay$#', 'rota_webhook', 'publico'],    // chamado pela InfinitePay
    ['POST', '#^/api/pagamento/infinitepay/retorno$#', 'rota_retorno_infinitepay', 'publico'],   // exige sessao de cliente
    ['POST', '#^/api/webhook/asaas$#', 'rota_webhook_asaas', 'publico'],    // chamado pelo Asaas

    ['POST', '#^/api/painel/login$#', 'rota_painel_login', 'publico'],
    ['POST', '#^/api/painel/logout$#', 'rota_painel_logout', 'publico'],
    ['GET', '#^/api/painel/me$#', 'rota_painel_me', 'operador'],
    ['POST', '#^/api/painel/senha$#', 'rota_trocar_senha', 'operador'],
    ['GET', '#^/api/painel/operadores$#', 'rota_listar_operadores', 'admin'],
    ['POST', '#^/api/painel/operadores$#', 'rota_criar_operador', 'admin'],
    ['POST', '#^/api/painel/operadores/(\d+)/remover$#', 'rota_remover_operador', 'admin'],

    ['POST', '#^/api/painel/sorteios$#', 'rota_criar_sorteio', 'admin'],
    ['POST', '#^/api/painel/sorteios/(\d+)/editar$#', 'rota_editar_sorteio', 'admin'],
    ['POST', '#^/api/painel/sorteios/(\d+)/ativar$#', 'rota_ativar_sorteio', 'admin'],
    ['POST', '#^/api/painel/sorteios/(\d+)/remover$#', 'rota_remover_sorteio', 'admin'],
    ['POST', '#^/api/lotes/importar$#', 'rota_importar_lote', 'operador'],
    ['GET', '#^/api/estoque$#', 'rota_estoque', 'operador'],
    ['GET', '#^/api/painel/vendas$#', 'rota_listar_vendas', 'operador'],
    ['POST', '#^/api/painel/vendas/(\d+)/reenviar$#', 'rota_reenviar', 'operador'],
    ['POST', '#^/api/painel/vendas/(\d+)/cancelar$#', 'rota_cancelar', 'operador'],
    ['POST', '#^/api/painel/pausar$#', 'rota_pausar', 'operador'],
    ['GET', '#^/api/painel/envios$#', 'rota_envios', 'operador'],
    ['GET', '#^/api/painel/clientes$#', 'rota_listar_clientes', 'operador'],
    ['GET', '#^/api/painel/email/status$#', fn() => json(email_status_publico()), 'operador'],
    ['POST', '#^/api/painel/email/teste$#', 'rota_email_teste', 'operador'],
    ['GET', '#^/api/painel/exportar-capemisa$#', 'rota_exportar_capemisa', 'operador'],
    ['POST', '#^/api/painel/reset$#', 'rota_reset', 'admin'],
];

if (str_starts_with($caminho, '/api/')) {
    try {
        garantir_admin_inicial();
        $metodoErrado = false;
        foreach ($rotas as [$m, $re, $fn, $acesso]) {
            if (preg_match($re, $caminho, $mt)) {
                if ($m !== $metodo) {
                    $metodoErrado = true;
                    continue;
                }
                if ($acesso !== 'publico') {
                    operador_atual($acesso === 'admin');   // bloqueia sem login de operador
                }
                $args = array_map('intval', array_slice($mt, 1));
                $fn(...$args);
                exit;
            }
        }
        erro($metodoErrado ? 405 : 404, $metodoErrado ? 'Method Not Allowed' : 'Not Found');
    } catch (HttpErro $e) {
        json(['detail' => $e->getMessage()], $e->codigo);
    } catch (Throwable $e) {
        error_log((string) $e);
        json(['detail' => 'Erro interno: ' . $e->getMessage()], 500);
    }
    exit;
}

// ---------------------------------------------------------------- frontend (build do React)
servir_frontend($caminho);

function servir_frontend(string $caminho): void
{
    $dist = realpath(getenv('FRONTEND_DIST') ?: __DIR__ . '/../frontend/dist');
    if (!$dist) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Frontend ainda não foi gerado. Rode: cd frontend && npm run build';
        return;
    }
    $alvo = realpath($dist . $caminho);
    if ($caminho !== '/' && $alvo && is_file($alvo) && str_starts_with($alvo, $dist . DIRECTORY_SEPARATOR)) {
        $tipos = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png',
                  'jpg' => 'image/jpeg', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'json' => 'application/json',
                  'html' => 'text/html; charset=utf-8'];
        $ext = strtolower(pathinfo($alvo, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000, immutable');   // nomes com hash
        header('Content-Length: ' . filesize($alvo));
        readfile($alvo);
        return;
    }
    if (str_starts_with($caminho, '/assets/')) {
        // asset de build antigo: 404 (nunca devolver index.html no lugar de um .js)
        http_response_code(404);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($dist . '/index.html');
}
