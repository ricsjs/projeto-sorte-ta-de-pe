<?php
/**
 * Integracao com o Asaas (cobranca PIX).
 * Ativa quando o .env tem PAGAMENTO=asaas e ASAAS_API_KEY.
 *
 * Fluxo: cliente do Asaas (1 por CPF) -> cobranca PIX -> QR Code.
 * A confirmacao chega de dois jeitos, ambos chamam processar_pagamento():
 *   - webhook POST /api/webhook/asaas, validado pelo header asaas-access-token
 *   - consulta direta ao Asaas enquanto o comprador esta na tela do PIX (cobre webhook fora do ar)
 */

declare(strict_types=1);

const ASAAS_PAGO = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];
const ASAAS_VALOR_MINIMO = 5.00;   // o Asaas recusa cobranca abaixo disso

function asaas_ativo(): bool
{
    return env('PAGAMENTO') === 'asaas' && env('ASAAS_API_KEY') !== '';
}

/** Chamada a API v3. Erro de rede ou HTTP >= 400 vira HttpErro 502 com a mensagem do Asaas. */
function asaas(string $metodo, string $caminho, ?array $corpo = null): array
{
    $c = curl_init(rtrim(env('ASAAS_URL', 'https://api.asaas.com/v3'), '/') . $caminho);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'access_token: ' . env('ASAAS_API_KEY'),
            'Content-Type: application/json',
            'User-Agent: poc-numero-sorte',
        ],
    ];
    if (PHP_OS_FAMILY === 'Windows') {
        $opcoes[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;   // PHP do Windows nao traz lista de CAs
    }
    if ($corpo !== null) {
        $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($c, $opcoes);
    $resp = curl_exec($c);
    $codigo = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $falha = curl_error($c);
    curl_close($c);

    if ($resp === false) {
        error_log("Asaas {$metodo} {$caminho}: {$falha}");
        erro(502, 'Não foi possível falar com o Asaas. Tente de novo em instantes.');
    }
    $dados = json_decode((string) $resp, true) ?? [];
    if ($codigo >= 400) {
        $msg = implode(' ', array_column($dados['errors'] ?? [], 'description')) ?: "HTTP {$codigo}";
        error_log("Asaas {$metodo} {$caminho}: {$codigo} {$resp}");
        erro(502, "Asaas recusou a operação: {$msg}");
    }
    return $dados;
}

/** Id do cliente no Asaas. Reaproveita o cadastro existente pelo CPF antes de criar outro. */
function asaas_cliente(array $c): string
{
    if (!empty($c['asaas_id'])) {
        return $c['asaas_id'];
    }
    $achou = asaas('GET', '/customers?cpfCnpj=' . $c['cpf'] . '&limit=1')['data'][0]['id'] ?? null;
    $id = $achou ?? asaas('POST', '/customers', [
        'name' => $c['nome'],
        'cpfCnpj' => $c['cpf'],
        'email' => $c['email'],
        'mobilePhone' => so_digitos($c['whatsapp']),
        'notificationDisabled' => true,   // quem avisa o comprador e o nosso sistema
    ])['id'];
    q('UPDATE clientes SET asaas_id=? WHERE id=?', [$id, $c['id']]);
    return $id;
}

/** Cria a cobranca PIX e busca o QR Code. txid = id da cobranca no Asaas (pay_...). */
function asaas_criar_pix(float $valor, array $cliente, int $qtd): array
{
    if ($valor < ASAAS_VALOR_MINIMO) {
        $falta = (int) ceil(ASAAS_VALOR_MINIMO / ($valor / $qtd) - 0.0001);
        erro(422, 'O PIX mínimo é de R$ 5,00. Escolha pelo menos ' . $falta . ' números.');
    }
    $cob = asaas('POST', '/payments', [
        'customer' => asaas_cliente($cliente),
        'billingType' => 'PIX',
        'value' => $valor,
        'dueDate' => date('Y-m-d', strtotime('+1 day')),
        'description' => $qtd === 1 ? 'E-book + 1 número da sorte' : "E-book + {$qtd} números da sorte",
        'externalReference' => 'cliente-' . $cliente['id'],
    ]);
    $qr = asaas('GET', "/payments/{$cob['id']}/pixQrCode");
    return [
        'txid' => $cob['id'],
        'pix_copia_cola' => $qr['payload'],
        'pix_qrcode' => $qr['encodedImage'] ?? null,
        'link_pagamento' => $cob['invoiceUrl'] ?? null,
    ];
}

/**
 * Pergunta ao Asaas se a cobranca foi paga e, se sim, processa.
 * No maximo uma consulta a cada 5s por venda, porque a tela do PIX pergunta a cada 2s.
 * Devolve o id da venda quando ela acabou de ser paga, para quem chamou disparar a entrega.
 */
function asaas_conferir(array $venda): ?int
{
    if (!asaas_ativo() || $venda['status'] !== 'aguardando_pagamento' || !str_starts_with($venda['txid'], 'pay_')) {
        return null;
    }
    $agora = time();
    $mudou = q('UPDATE vendas SET conferido_em=? WHERE id=? AND COALESCE(conferido_em,0) <= ?',
        [$agora, $venda['id'], $agora - 5])->rowCount();
    if (!$mudou) {
        return null;
    }
    try {
        $status = asaas('GET', "/payments/{$venda['txid']}/status")['status'] ?? '';
    } catch (HttpErro) {
        return null;   // Asaas fora do ar: a tela tenta de novo na proxima consulta
    }
    if (!in_array($status, ASAAS_PAGO, true)) {
        return null;
    }
    $r = processar_pagamento($venda['txid']);
    return $r['status'] === 'pago' ? $r['venda_id'] : null;
}

/** Webhook do Asaas. Sempre responde 200 para eventos validos, senao o Asaas pausa a fila. */
function rota_webhook_asaas(): void
{
    $token = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';
    $esperado = env('ASAAS_WEBHOOK_TOKEN');
    if ($esperado === '' || !hash_equals($esperado, $token)) {
        erro(401, 'Token do webhook inválido');
    }
    $b = corpo_json();
    $pag = $b['payment'] ?? [];
    $evento = $b['event'] ?? '';
    if (!in_array($evento, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true) || empty($pag['id'])) {
        json(['ignorado' => $evento]);
        return;
    }
    $venda = um('SELECT valor FROM vendas WHERE txid=?', [$pag['id']]);
    if (!$venda) {
        json(['ignorado' => 'cobranca de outro sistema']);
        return;
    }
    if ((float) ($pag['value'] ?? 0) + 0.001 < (float) $venda['valor']) {
        error_log("Asaas webhook: valor {$pag['value']} menor que a venda ({$venda['valor']}) em {$pag['id']}");
        json(['ignorado' => 'valor divergente']);
        return;
    }
    responder_pagamento(processar_pagamento($pag['id']));
}
