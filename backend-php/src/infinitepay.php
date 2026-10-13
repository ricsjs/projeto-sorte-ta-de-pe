<?php
/**
 * Integracao com a InfinitePay (Checkout Integrado: link de pagamento PIX ou cartao).
 * Ativa quando o .env tem PAGAMENTO=infinitepay e INFINITEPAY_HANDLE (InfiniteTag sem o $).
 *
 * Nao ha chave de API e o webhook nao tem assinatura. Por isso todo aviso e conferido em
 * POST /payment_check antes de liberar os numeros. txid da venda = order_nsu enviado no link.
 */

declare(strict_types=1);

function infinitepay_ativo(): bool
{
    return env('PAGAMENTO') === 'infinitepay' && env('INFINITEPAY_HANDLE') !== '';
}

function infinitepay(string $caminho, array $corpo): array
{
    $c = curl_init(rtrim(env('INFINITEPAY_URL', 'https://api.checkout.infinitepay.io'), '/') . $caminho);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE),
    ];
    if (PHP_OS_FAMILY === 'Windows') {
        $opcoes[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($c, $opcoes);
    $resp = curl_exec($c);
    $codigo = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $falha = curl_error($c);
    curl_close($c);

    if ($resp === false) {
        error_log("InfinitePay {$caminho}: {$falha}");
        erro(502, 'Não foi possível falar com a InfinitePay. Tente de novo em instantes.');
    }
    $dados = json_decode((string) $resp, true) ?? [];
    if ($codigo >= 400) {
        error_log("InfinitePay {$caminho}: {$codigo} {$resp}");
        erro(502, 'InfinitePay recusou a operação: ' . ($dados['message'] ?? $dados['error'] ?? "HTTP {$codigo}"));
    }
    return $dados;
}

/** Endereco publico deste sistema, para o retorno do cliente e o webhook. */
function url_publica(): string
{
    $base = env('URL_PUBLICA');
    if ($base === '') {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (!empty($_SERVER['HTTPS']) ? 'https' : 'http');
        $base = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return rtrim($base, '/');
}

/** Cria o link de pagamento. Valores vao em centavos. */
function infinitepay_criar_link(float $valor, array $cliente, int $qtd): array
{
    $nsu = 'ns-' . bin2hex(random_bytes(8));
    $unitario = (int) round($valor * 100 / $qtd);
    $r = infinitepay('/links', [
        'handle' => env('INFINITEPAY_HANDLE'),
        'order_nsu' => $nsu,
        'items' => [['quantity' => $qtd, 'price' => $unitario, 'description' => 'E-book + número da sorte']],
        'customer' => [
            'name' => $cliente['nome'],
            'email' => $cliente['email'],
            'phone_number' => '+55' . so_digitos($cliente['whatsapp']),
        ],
        'redirect_url' => url_publica() . '/?pagamento=' . $nsu,
        'webhook_url' => url_publica() . '/api/webhook/infinitepay',
    ]);
    $link = $r['url'] ?? $r['link'] ?? $r['checkout_url'] ?? null;
    if (!$link) {
        error_log('InfinitePay /links sem url: ' . json_encode($r));
        erro(502, 'A InfinitePay não devolveu o link de pagamento');
    }
    return ['txid' => $nsu, 'pix_copia_cola' => '', 'pix_qrcode' => null, 'link_pagamento' => $link];
}

/** Pergunta a InfinitePay se o pedido foi pago e, se o valor bate, processa. */
function infinitepay_confirmar(string $nsu, string $transacao, string $slug): array
{
    $venda = um('SELECT valor FROM vendas WHERE txid=?', [$nsu]);
    if (!$venda) {
        return ['status' => 'ignorado', 'motivo' => 'pedido desconhecido'];
    }
    $r = infinitepay('/payment_check', [
        'handle' => env('INFINITEPAY_HANDLE'),
        'order_nsu' => $nsu,
        'transaction_nsu' => $transacao,
        'slug' => $slug,
    ]);
    if (empty($r['paid'])) {
        return ['status' => 'ignorado', 'motivo' => 'nao pago'];
    }
    if ((int) ($r['amount'] ?? 0) < (int) round($venda['valor'] * 100)) {
        error_log("InfinitePay: valor {$r['amount']} menor que a venda ({$venda['valor']}) em {$nsu}");
        return ['status' => 'ignorado', 'motivo' => 'valor divergente'];
    }
    return processar_pagamento($nsu);
}

/** Webhook da InfinitePay: chamado uma vez por pagamento aprovado. */
function rota_webhook(): void
{
    $b = corpo_json();
    if (empty($b['order_nsu']) || empty($b['transaction_nsu'])) erro(422, 'Payload inválido');
    responder_pagamento(infinitepay_confirmar(
        (string) $b['order_nsu'], (string) $b['transaction_nsu'], (string) ($b['invoice_slug'] ?? '')));
}

/** Cliente voltou da pagina da InfinitePay com os dados da transacao no endereco. */
function rota_retorno_infinitepay(): void
{
    $b = corpo_json();
    if (empty($b['order_nsu']) || empty($b['transaction_nsu'])) erro(422, 'Dados do pagamento incompletos');
    $c = cliente_atual();
    $v = um('SELECT id FROM vendas WHERE txid=? AND cliente_id=?', [(string) $b['order_nsu'], $c['id']])
        ?? erro(404, 'Compra não encontrada');
    $r = infinitepay_confirmar((string) $b['order_nsu'], (string) $b['transaction_nsu'], (string) ($b['slug'] ?? ''));
    responder_pagamento($r + ['venda_id' => (int) $v['id']]);
}
