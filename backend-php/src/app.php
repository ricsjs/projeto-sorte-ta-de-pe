<?php
/**
 * Regras de negocio.
 * Fluxo: cliente se cadastra uma vez, entra na conta e compra quantos numeros quiser.
 * Cada compra gera um PIX de (quantidade x valor). Ao pagar, todos os numeros sao reservados de uma vez.
 *
 * Pagamento e REAL com PAGAMENTO=asaas (src/asaas.php) ou PAGAMENTO=infinitepay (src/infinitepay.php);
 * senao e simulado pelo botao da tela.
 * WhatsApp e SIMULADO. E-mail e REAL quando .env tem SMTP.
 * Pontos que viram integracao real depois:
 *   - entregar_whatsapp()           -> provedor real (Meta Cloud API, Z-API...)
 */

declare(strict_types=1);

const MAX_POR_COMPRA = 100;

/** Estoque de um sorteio (padrao: o ativo). Sem sorteio ativo, tudo zero. */
function estoque(?int $sorteioId = null): array
{
    $sorteioId ??= sorteio_ativo()['id'] ?? 0;
    $r = um("SELECT SUM(status='livre') AS livres, SUM(status='atribuido') AS atribuidos,
                    SUM(status='cancelado') AS cancelados, COUNT(*) AS total FROM numeros WHERE sorteio_id=?", [$sorteioId]);
    return array_map(fn($v) => (int) ($v ?? 0), $r);
}

function sorteio_ativo(): ?array
{
    return um('SELECT * FROM sorteios WHERE ativo=1');
}

// ================================================================= CLIENTES

function so_digitos(string $s): string
{
    return preg_replace('/\D/', '', $s);
}

function cpf_valido(string $cpf): bool
{
    $c = so_digitos($cpf);
    if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int) $c[$i] * ($t + 1 - $i);
        }
        if ((int) $c[$t] !== ((10 * $soma) % 11) % 10) {
            return false;
        }
    }
    return true;
}

function cliente_publico(array $c): array
{
    return ['id' => (int) $c['id'], 'nome' => $c['nome'], 'cpf' => $c['cpf'], 'email' => $c['email'],
            'whatsapp' => $c['whatsapp'], 'criado_em' => $c['criado_em']];
}

function criar_sessao_cliente(int $id): string
{
    $token = bin2hex(random_bytes(24));
    q('INSERT INTO sessoes_cliente (token, cliente_id) VALUES (?,?)', [$token, $id]);
    return $token;
}

function token_requisicao(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    return trim(preg_replace('/^Bearer\s+/i', '', $h));
}

/** Cliente da sessao atual ou erro 401. */
function cliente_atual(): array
{
    $token = token_requisicao();
    if ($token === '') {
        erro(401, 'Entre na sua conta para continuar');
    }
    return um('SELECT c.* FROM sessoes_cliente s JOIN clientes c ON c.id=s.cliente_id WHERE s.token=?', [$token])
        ?? erro(401, 'Sessão expirada. Entre novamente.');
}

function rota_cadastro(): void
{
    $b = corpo_json();
    $nome = trim((string) ($b['nome'] ?? ''));
    $cpf = so_digitos((string) ($b['cpf'] ?? ''));
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $whatsapp = so_digitos((string) ($b['whatsapp'] ?? ''));
    $senha = (string) ($b['senha'] ?? '');

    if (mb_strlen($nome) < 3 || !str_contains($nome, ' ')) erro(422, 'Informe nome e sobrenome');
    if (!cpf_valido($cpf)) erro(422, 'CPF inválido');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro(422, 'E-mail inválido');
    if (strlen($whatsapp) < 10 || strlen($whatsapp) > 13) erro(422, 'WhatsApp inválido. Use DDD + número.');
    if (strlen($senha) < 6) erro(422, 'A senha precisa ter pelo menos 6 caracteres');
    if (um('SELECT 1 FROM clientes WHERE cpf=?', [$cpf])) erro(409, 'Já existe uma conta com este CPF. Entre com sua senha.');
    if (um('SELECT 1 FROM clientes WHERE email=?', [$email])) erro(409, 'Já existe uma conta com este e-mail. Entre com sua senha.');

    q('INSERT INTO clientes (nome, cpf, email, whatsapp, senha_hash) VALUES (?,?,?,?,?)',
        [$nome, $cpf, $email, $whatsapp, password_hash($senha, PASSWORD_DEFAULT)]);
    $id = (int) db()->lastInsertId();
    json(['token' => criar_sessao_cliente($id), 'cliente' => cliente_publico(um('SELECT * FROM clientes WHERE id=?', [$id]))]);
}

function rota_login(): void
{
    $b = corpo_json();
    $login = trim((string) ($b['login'] ?? ''));
    $senha = (string) ($b['senha'] ?? '');
    $c = str_contains($login, '@')
        ? um('SELECT * FROM clientes WHERE email=?', [strtolower($login)])
        : um('SELECT * FROM clientes WHERE cpf=?', [so_digitos($login)]);
    if (!$c || !password_verify($senha, $c['senha_hash'])) {
        erro(401, 'E-mail/CPF ou senha incorretos');
    }
    json(['token' => criar_sessao_cliente((int) $c['id']), 'cliente' => cliente_publico($c)]);
}

/** Cliente altera o proprio WhatsApp e/ou a senha. Sempre exige a senha atual. */
function rota_editar_cadastro(): void
{
    $c = cliente_atual();
    $b = corpo_json();
    if (!password_verify((string) ($b['senha_atual'] ?? ''), $c['senha_hash'])) erro(400, 'Senha atual incorreta');

    $whatsapp = so_digitos((string) ($b['whatsapp'] ?? $c['whatsapp']));
    if (strlen($whatsapp) < 10 || strlen($whatsapp) > 13) erro(422, 'WhatsApp inválido. Use DDD + número.');
    $nova = (string) ($b['nova_senha'] ?? '');
    if ($nova !== '' && strlen($nova) < 6) erro(422, 'A nova senha precisa ter pelo menos 6 caracteres');

    q('UPDATE clientes SET whatsapp=? WHERE id=?', [$whatsapp, $c['id']]);
    if ($nova !== '') {
        q('UPDATE clientes SET senha_hash=? WHERE id=?', [password_hash($nova, PASSWORD_DEFAULT), $c['id']]);
        // senha nova derruba as outras sessoes abertas
        q('DELETE FROM sessoes_cliente WHERE cliente_id=? AND token<>?', [$c['id'], token_requisicao()]);
    }
    json(['ok' => true, 'cliente' => cliente_publico(um('SELECT * FROM clientes WHERE id=?', [$c['id']]))]);
}

function rota_logout(): void
{
    q('DELETE FROM sessoes_cliente WHERE token=?', [token_requisicao()]);
    json(['ok' => true]);
}

/** Dados da conta: cliente, compras e todos os numeros. */
function rota_minha_conta(): void
{
    $c = cliente_atual();
    $compras = todos('SELECT id, qtd, valor, status, criado_em, pago_em FROM vendas WHERE cliente_id=? ORDER BY id DESC', [$c['id']]);
    $numeros = todos("SELECT n.numero, n.serie, n.sorteio, s.nome AS sorteio_nome, n.status, n.venda_id, n.atribuido_em
                        FROM numeros n JOIN vendas v ON v.id=n.venda_id JOIN sorteios s ON s.id=n.sorteio_id
                       WHERE v.cliente_id=? ORDER BY n.id", [$c['id']]);
    json([
        'cliente' => cliente_publico($c),
        'compras' => $compras,
        'numeros' => $numeros,
        'total_numeros' => count(array_filter($numeros, fn($n) => $n['status'] === 'atribuido')),
    ]);
}

// ================================================================= PAGAMENTO E ENTREGA

/** asaas | infinitepay | simulado, conforme PAGAMENTO no .env */
function modo_pagamento(): string
{
    return asaas_ativo() ? 'asaas' : (infinitepay_ativo() ? 'infinitepay' : 'simulado');
}

/** Cobranca: Asaas ou InfinitePay quando ativos, senao um codigo falso para a simulacao. */
function criar_cobranca_pix(float $valor, array $cliente, int $qtd): array
{
    if (asaas_ativo()) {
        return asaas_criar_pix($valor, $cliente, $qtd);
    }
    if (infinitepay_ativo()) {
        return infinitepay_criar_link($valor, $cliente, $qtd);
    }
    $txid = 'mock-' . substr(bin2hex(random_bytes(8)), 0, 12);
    $brcode = '00020126580014BR.GOV.BCB.PIX0136' . $txid
        . '5204000053039865406' . number_format($valor, 2, '.', '')
        . '5802BR5913POC EBOOK6009SAO PAULO62070503***6304'
        . strtoupper(bin2hex(random_bytes(2)));
    return ['txid' => $txid, 'pix_copia_cola' => $brcode, 'pix_qrcode' => null, 'link_pagamento' => null];
}

function registrar_envio(int $vendaId, string $canal, string $destino, string $mensagem, string $status, string $detalhe = ''): void
{
    q('INSERT INTO envios (venda_id, canal, destino, mensagem, status, detalhe) VALUES (?,?,?,?,?,?)',
        [$vendaId, $canal, $destino, $mensagem, $status, $detalhe]);
}

/** MOCK do WhatsApp. Trocar pela chamada ao provedor quando o numero Business estiver aprovado. */
function entregar_whatsapp(array $venda, array $numeros): array
{
    $primeiro = explode(' ', trim($venda['nome']))[0];
    $lista = implode(', ', array_column($numeros, 'numero'));
    $qtd = count($numeros);
    $msg = "Ola {$primeiro}! Seu pagamento foi confirmado.\n"
        . ($qtd === 1 ? "Seu NUMERO DA SORTE e: {$lista}\n" : "Seus {$qtd} NUMEROS DA SORTE: {$lista}\n")
        . 'Sorteio: ' . data_br((string) $numeros[0]['sorteio']) . " pela Loteria Federal.\n"
        . 'Segue em anexo o seu e-book e o regulamento. Boa sorte!';
    return ['mensagem' => $msg, 'status' => 'simulado', 'detalhe' => 'WhatsApp ainda nao integrado'];
}

/**
 * Envia os numeros por WhatsApp e e-mail e registra cada tentativa.
 * Roda depois de responder ao cliente, porque SMTP pode demorar e o webhook espera ~1s.
 */
function disparar_entrega(int $vendaId): array
{
    $venda = um('SELECT * FROM vendas WHERE id=?', [$vendaId]);
    $numeros = todos("SELECT numero, serie, sorteio FROM numeros WHERE venda_id=? AND status='atribuido' ORDER BY id", [$vendaId]);
    if (!$venda || !$numeros) {
        return ['ok' => false, 'erro' => 'venda sem numero'];
    }

    $wa = entregar_whatsapp($venda, $numeros);
    [$assunto, $texto, $html] = montar_entrega($venda['nome'], $numeros);
    $em = enviar_email($venda['email'], $assunto, $texto, $html);

    // qualquer canal com falha deixa a venda em falha_envio, para o operador ver e reenviar
    $entregue = !in_array('falha', [$em['status'], $wa['status']], true);
    registrar_envio($vendaId, 'whatsapp', $venda['whatsapp'], $wa['mensagem'], $wa['status'], $wa['detalhe']);
    registrar_envio($vendaId, 'email', $venda['email'], "{$assunto}\n\n{$texto}", $em['status'], $em['detalhe']);
    if ($venda['status'] !== 'cancelado') {
        q('UPDATE vendas SET status=? WHERE id=?', [$entregue ? 'entregue' : 'falha_envio', $vendaId]);
    }
    return ['ok' => $entregue, 'whatsapp' => $wa['status'], 'email' => $em['status'], 'detalhe_email' => $em['detalhe']];
}

/**
 * Coracao do sistema. Idempotente: webhook repetido nao gera numeros novos.
 * Reserva TODOS os numeros da compra numa unica transacao:
 * ou a compra recebe a quantidade inteira, ou nenhum numero e reservado.
 * No MySQL, a venda e o sorteio ficam travados (FOR UPDATE) ate o fim:
 * dois avisos da mesma venda ou duas vendas ao mesmo tempo nunca pegam o mesmo numero.
 */
function processar_pagamento(string $txid): array
{
    return transacao(function () use ($txid) {
        $venda = um('SELECT * FROM vendas WHERE txid=?' . para_update(), [$txid]) ?? erro(404, 'txid desconhecido');
        $ja = todos('SELECT numero FROM numeros WHERE venda_id=?', [$venda['id']]);
        if ($ja) {
            return ['status' => 'ja_processado', 'numeros' => array_column($ja, 'numero')];
        }

        // fila por sorteio: pagamentos do mesmo sorteio esperam aqui um de cada vez (evita deadlock na escolha dos numeros)
        um('SELECT id FROM sorteios WHERE id=?' . para_update(), [(int) $venda['sorteio_id']]);
        q("UPDATE vendas SET status='pago', pago_em=? WHERE id=?", [agora(), $venda['id']]);
        $qtd = max(1, (int) $venda['qtd']);
        $ids = array_column(todos("SELECT id FROM numeros WHERE status='livre' AND sorteio_id=? ORDER BY id LIMIT ?" . para_update(),
            [(int) $venda['sorteio_id'], $qtd]), 'id');

        if (count($ids) < $qtd) {
            q("UPDATE vendas SET status='sem_numero' WHERE id=?", [$venda['id']]);
            set_cfg('vendas_pausadas', '1');   // trava a loja
            return ['status' => 'sem_numero', 'faltaram' => $qtd - count($ids)];
        }

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        q("UPDATE numeros SET status='atribuido', venda_id=?, atribuido_em=? WHERE id IN ({$marcas})",
            array_merge([$venda['id'], agora()], $ids));
        $numeros = array_column(todos('SELECT numero FROM numeros WHERE venda_id=? ORDER BY id', [$venda['id']]), 'numero');
        return ['status' => 'pago', 'numeros' => $numeros, 'venda_id' => (int) $venda['id']];
    });
}

/** Depois de um pagamento novo, responde e dispara a entrega em segundo plano. */
function responder_pagamento(array $r): void
{
    if ($r['status'] === 'pago') {
        responder_e_continuar($r, fn() => disparar_entrega($r['venda_id']));
    } else {
        json($r);
    }
}

// ================================================================= LOJA

function rota_loja_status(): void
{
    $s = sorteio_ativo();
    $livres = estoque()['livres'];
    json([
        'sorteio' => $s ? ['nome' => $s['nome'], 'data_sorteio' => $s['data_sorteio']] : null,
        'pausada' => cfg('vendas_pausadas') === '1',
        'valor' => (float) cfg('valor_ebook'),
        'livres' => $livres,
        'max_por_compra' => min(MAX_POR_COMPRA, $livres),
        'total' => estoque()['total'],
        'pagamento' => modo_pagamento(),
    ]);
}

/** Nova compra do cliente logado: quantidade de numeros -> um PIX com o valor total. */
function rota_criar_venda(): void
{
    $c = cliente_atual();
    $qtd = (int) (corpo_json()['qtd'] ?? 1);
    if ($qtd < 1 || $qtd > MAX_POR_COMPRA) erro(422, 'Quantidade deve ser entre 1 e ' . MAX_POR_COMPRA);
    if (cfg('vendas_pausadas') === '1') erro(409, 'Vendas pausadas no momento');
    $sorteio = sorteio_ativo() ?? erro(409, 'Nenhum sorteio aberto no momento');
    $livres = estoque()['livres'];
    if ($livres === 0) erro(409, 'Numeros esgotados');
    if ($qtd > $livres) erro(409, "Só restam {$livres} números disponíveis");

    $unit = (float) cfg('valor_ebook');
    $valor = round($unit * $qtd, 2);
    $pix = criar_cobranca_pix($valor, $c, $qtd);
    // dados do cliente sao copiados para a venda: a planilha da Capemisa sai com o titular da compra
    q('INSERT INTO vendas (cliente_id,sorteio_id,qtd,nome,cpf,whatsapp,email,valor,txid,pix_copia_cola,pix_qrcode,link_pagamento)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$c['id'], $sorteio['id'], $qtd, $c['nome'], $c['cpf'], $c['whatsapp'], $c['email'], $valor,
         $pix['txid'], $pix['pix_copia_cola'], $pix['pix_qrcode'], $pix['link_pagamento']]);
    json(['venda_id' => (int) db()->lastInsertId(), 'qtd' => $qtd, 'valor_unitario' => $unit] + $pix + ['valor' => $valor]);
}

/** Detalhe de uma compra. So o dono ve. */
function rota_ver_venda(int $id): void
{
    $c = cliente_atual();
    $sql = 'SELECT * FROM vendas WHERE id=? AND cliente_id=?';
    $v = um($sql, [$id, $c['id']]) ?? erro(404, 'Compra não encontrada');
    $pagaAgora = asaas_conferir($v);
    if ($pagaAgora) {
        $v = um($sql, [$id, $c['id']]);
    }
    unset($v['conferido_em']);
    $v['numeros'] = todos('SELECT numero, serie, sorteio, status FROM numeros WHERE venda_id=? ORDER BY id', [$id]);
    $v['pagamento'] = modo_pagamento();
    if ($pagaAgora) {
        responder_e_continuar($v, fn() => disparar_entrega($pagaAgora));
    } else {
        json($v);
    }
}

/** Botao do POC: finge que o comprador pagou e chama o mesmo codigo do webhook. */
function rota_simular_pagamento(int $id): void
{
    if (modo_pagamento() !== 'simulado') erro(403, 'Pagamento real ativo: a simulação está desligada');
    $c = cliente_atual();
    $v = um('SELECT txid FROM vendas WHERE id=? AND cliente_id=?', [$id, $c['id']]) ?? erro(404, 'Compra não encontrada');
    responder_pagamento(processar_pagamento($v['txid']));
}

// ================================================================= PAINEL (operador)

function rota_importar_lote(): void
{
    $f = $_FILES['arquivo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) erro(400, 'Envie o arquivo da planilha');
    $linhas = ler_planilha($f['name'], $f['tmp_name']);
    if (!$linhas || !array_key_exists('numero', $linhas[0])) {
        erro(400, "Planilha precisa ter coluna 'numero' (opcionais: serie, sorteio)");
    }

    $sid = (int) ($_POST['sorteio_id'] ?? 0) ?: (sorteio_ativo()['id'] ?? 0);
    $sorteio = um('SELECT * FROM sorteios WHERE id=?', [$sid]) ?? erro(400, 'Cadastre um sorteio antes de importar números');
    // sem coluna sorteio na planilha, os numeros levam a data (ou o nome) do sorteio escolhido
    $padrao = $sorteio['data_sorteio'] ?: $sorteio['nome'];

    $pdo = db();
    [$loteId, $ok, $rejeitados] = transacao(function () use ($pdo, $f, $sid, $sorteio, $linhas, $padrao) {
        q('INSERT INTO lotes (arquivo,total,sorteio_id) VALUES (?,?,?)', [$f['name'], 0, $sid]);
        $loteId = (int) $pdo->lastInsertId();
        $ok = 0;
        $rejeitados = [];
        $ins = $pdo->prepare('INSERT INTO numeros (lote_id,sorteio_id,numero,serie,sorteio) VALUES (?,?,?,?,?)');
        foreach ($linhas as $i => $l) {
            $linha = $i + 2;
            $numero = trim((string) ($l['numero'] ?? ''));
            if ($numero === '') {
                $rejeitados[] = ['linha' => $linha, 'motivo' => 'numero vazio'];
                continue;
            }
            try {
                $ins->execute([$loteId, $sid, $numero, trim((string) ($l['serie'] ?? '')),
                               trim((string) ($l['sorteio'] ?? '')) ?: $padrao]);
                $ok++;
            } catch (PDOException) {
                $rejeitados[] = ['linha' => $linha, 'numero' => $numero, 'motivo' => 'duplicado neste sorteio'];
            }
        }
        if ($ok === 0) {
            q('DELETE FROM lotes WHERE id=?', [$loteId]);      // nao registra lote vazio
        } else {
            q('UPDATE lotes SET total=? WHERE id=?', [$ok, $loteId]);
        }
        if ($ok > 0 && $sorteio['ativo'] && cfg('vendas_pausadas') === '1') {
            set_cfg('vendas_pausadas', '0');                    // chegou lote novo, destrava a loja
        }
        return [$loteId, $ok, $rejeitados];
    });
    json(['lote_id' => $loteId, 'sorteio' => $sorteio['nome'], 'importados' => $ok, 'rejeitados' => $rejeitados]);
}

function rota_estoque(): void
{
    $e = estoque();
    $minimo = (int) cfg('alerta_estoque_minimo');
    json($e + [
        'alerta' => $e['livres'] <= $minimo,
        'minimo' => $minimo,
        'pausada' => cfg('vendas_pausadas') === '1',
        'clientes' => (int) um('SELECT COUNT(*) n FROM clientes')['n'],
        'sorteio_ativo' => sorteio_ativo(),
        'sorteios' => listar_sorteios(),
        'lotes' => todos('SELECT l.*, s.nome AS sorteio_nome FROM lotes l LEFT JOIN sorteios s ON s.id=l.sorteio_id ORDER BY l.id DESC'),
    ]);
}

// ================================================================= SORTEIOS

function listar_sorteios(): array
{
    $inteiros = ['total', 'livres', 'atribuidos', 'vendas'];
    return array_map(fn($s) => ['ativo' => (bool) $s['ativo']]
        + array_map('intval', array_intersect_key($s, array_flip($inteiros))) + $s, todos(
        "SELECT s.id, s.nome, s.data_sorteio, s.ativo, s.criado_em,
                COUNT(n.id) AS total,
                COALESCE(SUM(n.status='livre'),0) AS livres,
                COALESCE(SUM(n.status='atribuido'),0) AS atribuidos,
                (SELECT COUNT(*) FROM vendas v WHERE v.sorteio_id=s.id) AS vendas
           FROM sorteios s LEFT JOIN numeros n ON n.sorteio_id=s.id
          GROUP BY s.id ORDER BY s.id DESC"));
}

/** Le e valida nome e data do corpo. Data no formato AAAA-MM-DD ou vazia. */
function dados_sorteio(): array
{
    $b = corpo_json();
    $nome = trim((string) ($b['nome'] ?? ''));
    $data = trim((string) ($b['data_sorteio'] ?? ''));
    if (mb_strlen($nome) < 2) erro(422, 'Informe o nome do sorteio');
    if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) erro(422, 'Data do sorteio inválida');
    return [$nome, $data ?: null, !empty($b['ativar'])];
}

function rota_criar_sorteio(): void
{
    [$nome, $data, $ativar] = dados_sorteio();
    q('INSERT INTO sorteios (nome, data_sorteio) VALUES (?,?)', [$nome, $data]);
    $id = (int) db()->lastInsertId();
    if ($ativar || !sorteio_ativo()) {
        ativar_sorteio($id);
    }
    json(['ok' => true, 'id' => $id]);
}

function rota_editar_sorteio(int $id): void
{
    $s = um('SELECT * FROM sorteios WHERE id=?', [$id]) ?? erro(404, 'Sorteio não encontrado');
    [$nome, $data] = dados_sorteio();
    q('UPDATE sorteios SET nome=?, data_sorteio=? WHERE id=?', [$nome, $data, $id]);
    // numeros importados sem coluna sorteio herdaram a data (ou o nome): acompanham a edicao
    q('UPDATE numeros SET sorteio=? WHERE sorteio_id=? AND sorteio=?',
        [$data ?: $nome, $id, $s['data_sorteio'] ?: $s['nome']]);
    json(['ok' => true]);
}

/** Troca o sorteio ativo. As compras novas passam a usar os numeros dele na hora. */
function ativar_sorteio(int $id): void
{
    transacao(function () use ($id) {
        um('SELECT 1 FROM sorteios WHERE id=?', [$id]) ?? erro(404, 'Sorteio não encontrado');
        q('UPDATE sorteios SET ativo=0 WHERE ativo=1');
        q('UPDATE sorteios SET ativo=1 WHERE id=?', [$id]);
    });
    if (estoque($id)['livres'] > 0 && cfg('vendas_pausadas') === '1') {
        set_cfg('vendas_pausadas', '0');   // mesma regra da importacao: tem numero livre, destrava a loja
    }
}

function rota_ativar_sorteio(int $id): void
{
    ativar_sorteio($id);
    json(['ok' => true]);
}

/** So remove sorteio sem nenhuma venda. Leva junto os numeros e lotes dele. */
function rota_remover_sorteio(int $id): void
{
    $s = um('SELECT * FROM sorteios WHERE id=?', [$id]) ?? erro(404, 'Sorteio não encontrado');
    if (um('SELECT 1 FROM vendas WHERE sorteio_id=?', [$id])) erro(409, 'Este sorteio já tem vendas e não pode ser removido');
    if ($s['ativo']) erro(409, 'Ative outro sorteio antes de remover este');
    q('DELETE FROM numeros WHERE sorteio_id=?', [$id]);
    q('DELETE FROM lotes WHERE sorteio_id=?', [$id]);
    q('DELETE FROM sorteios WHERE id=?', [$id]);
    json(['ok' => true]);
}

function rota_listar_vendas(): void
{
    $like = '%' . ($_GET['q'] ?? '') . '%';
    $vendas = todos("SELECT v.*, (SELECT nome FROM sorteios s WHERE s.id=v.sorteio_id) AS sorteio_nome,
                       (SELECT " . concat_numeros('n.numero') . " FROM numeros n WHERE n.venda_id=v.id) AS numeros,
                       (SELECT COUNT(*) FROM envios e WHERE e.venda_id=v.id) AS envios
                  FROM vendas v
                 WHERE v.nome LIKE ? OR v.cpf LIKE ? OR v.email LIKE ? OR v.txid LIKE ?
                    OR EXISTS (SELECT 1 FROM numeros n WHERE n.venda_id=v.id AND n.numero LIKE ?)
                 ORDER BY v.id DESC", [$like, $like, $like, $like, $like]);
    // a imagem do QR Code pesa alguns KB por venda e o painel nao usa
    json(array_map(fn($v) => array_diff_key($v, ['pix_qrcode' => 1, 'conferido_em' => 1]), $vendas));
}

function rota_listar_clientes(): void
{
    json(todos("SELECT c.id, c.nome, c.cpf, c.email, c.whatsapp, c.criado_em,
                       (SELECT COUNT(*) FROM vendas v WHERE v.cliente_id=c.id) AS compras,
                       (SELECT COUNT(*) FROM numeros n JOIN vendas v ON v.id=n.venda_id
                         WHERE v.cliente_id=c.id AND n.status='atribuido') AS numeros,
                       (SELECT COALESCE(SUM(v.valor),0) FROM vendas v
                         WHERE v.cliente_id=c.id AND v.status IN ('pago','entregue','falha_envio','sem_numero')) AS total_pago
                  FROM clientes c ORDER BY c.id DESC"));
}

/** Reenvio manual: roda na hora para o operador ver o resultado. */
function rota_reenviar(int $id): void
{
    $r = disparar_entrega($id);
    if (!empty($r['erro'])) erro(400, 'Venda sem numero atribuido');
    json($r);
}

function rota_cancelar(int $id): void
{
    q("UPDATE vendas SET status='cancelado' WHERE id=?", [$id]);
    q("UPDATE numeros SET status='cancelado' WHERE venda_id=?", [$id]);
    json(['ok' => true]);
}

function rota_pausar(): void
{
    $p = filter_var($_GET['pausar'] ?? '', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($p === null) erro(422, "Parâmetro 'pausar' deve ser true ou false");
    set_cfg('vendas_pausadas', $p ? '1' : '0');
    json(['pausada' => $p]);
}

function rota_envios(): void
{
    json(todos('SELECT e.*, v.nome FROM envios e JOIN vendas v ON v.id=e.venda_id ORDER BY e.id DESC LIMIT 50'));
}

function rota_email_teste(): void
{
    $para = trim((string) (corpo_json()['para'] ?? ''));
    if (strlen($para) < 5) erro(422, 'Informe o e-mail de destino');
    [$assunto, $texto, $html] = montar_entrega('Teste do Sistema', [
        ['numero' => '000000', 'serie' => 'TESTE', 'sorteio' => '2026-11-28'],
        ['numero' => '000001', 'serie' => 'TESTE', 'sorteio' => '2026-11-28'],
    ]);
    json(enviar_email($para, '[TESTE] ' . $assunto, $texto, $html));
}

/** Uma linha por numero, com o titular da compra (formato de retorno para a Capemisa). */
function rota_exportar_capemisa(): void
{
    $sql = "SELECT n.numero, n.serie, n.sorteio, v.nome, v.cpf, v.email, v.whatsapp, n.atribuido_em, n.lote_id
              FROM numeros n JOIN vendas v ON v.id=n.venda_id WHERE n.status='atribuido'";
    $params = [];
    if (!empty($_GET['sorteio_id'])) {
        $sql .= ' AND n.sorteio_id=?';
        $params[] = (int) $_GET['sorteio_id'];
    }
    if (!empty($_GET['lote_id'])) {
        $sql .= ' AND n.lote_id=?';
        $params[] = (int) $_GET['lote_id'];
    }
    $rows = todos($sql . ' ORDER BY n.id', $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=retorno_capemisa.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['numero', 'serie', 'sorteio', 'nome', 'cpf', 'email', 'whatsapp', 'atribuido_em', 'lote'], ';', '"', '', "\r\n");
    foreach ($rows as $r) {
        fputcsv($out, array_values($r), ';', '"', '', "\r\n");
    }
    fclose($out);
}

/** Apaga vendas e numeros. Clientes e sorteios cadastrados sao mantidos. So para o POC. */
function rota_reset(): void
{
    foreach (['envios', 'numeros', 'vendas', 'lotes'] as $t) {
        db()->exec("DELETE FROM {$t}");
    }
    set_cfg('vendas_pausadas', '0');
    json(['ok' => true]);
}
