<?php
/**
 * Acesso ao painel. Contas de operador sao separadas das contas de cliente.
 *   admin    -> usa o painel e gerencia quem tem acesso
 *   operador -> usa o painel
 * O primeiro admin e criado a partir de ADMIN_EMAIL / ADMIN_SENHA do .env, so quando nao existe nenhum operador.
 */

declare(strict_types=1);

const SESSAO_OPERADOR_HORAS = 12;
const LOGIN_MAX_FALHAS = 8;          // por IP
const LOGIN_JANELA_SEG = 900;        // 15 minutos

function garantir_admin_inicial(): void
{
    if ((int) um('SELECT COUNT(*) n FROM operadores')['n'] > 0) {
        return;
    }
    $email = strtolower(env('ADMIN_EMAIL'));
    $senha = env('ADMIN_SENHA');
    if ($email !== '' && strlen($senha) >= 8) {
        q("INSERT INTO operadores (nome, email, senha_hash, papel) VALUES (?,?,?, 'admin')",
            [env('ADMIN_NOME', 'Administrador'), $email, password_hash($senha, PASSWORD_DEFAULT)]);
    }
}

function operador_publico(array $o): array
{
    return ['id' => (int) $o['id'], 'nome' => $o['nome'], 'email' => $o['email'], 'papel' => $o['papel'],
            'criado_em' => $o['criado_em'], 'ultimo_acesso' => $o['ultimo_acesso'] ?? null];
}

function ip_cliente(): string
{
    $remoto = $_SERVER['REMOTE_ADDR'] ?? '?';
    // o cabecalho da Cloudflare so vale quando a requisicao chega pelo tunel local; direto, poderia ser forjado
    if (in_array($remoto, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return $remoto;
}

/** Operador da sessao atual ou erro 401/403. */
function operador_atual(bool $exigeAdmin = false): array
{
    $token = token_requisicao();
    if ($token === '') {
        erro(401, 'Entre no painel para continuar');
    }
    $o = um("SELECT o.*, s.criado_em AS sessao_em FROM sessoes_operador s JOIN operadores o ON o.id=s.operador_id
              WHERE s.token=? AND s.criado_em >= ?", [$token, agora(-SESSAO_OPERADOR_HORAS * 3600)]);
    if (!$o) {
        erro(401, 'Sessão do painel expirada. Entre novamente.');
    }
    if ($exigeAdmin && $o['papel'] !== 'admin') {
        erro(403, 'Somente administradores podem fazer isso');
    }
    return $o;
}

function rota_painel_login(): void
{
    $ip = ip_cliente();
    $limite = time() - LOGIN_JANELA_SEG;
    q('DELETE FROM tentativas_login WHERE em < ?', [$limite]);
    if ((int) um('SELECT COUNT(*) n FROM tentativas_login WHERE ip=?', [$ip])['n'] >= LOGIN_MAX_FALHAS) {
        erro(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
    }

    $b = corpo_json();
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $senha = (string) ($b['senha'] ?? '');
    $o = um('SELECT * FROM operadores WHERE email=?', [$email]);
    if (!$o || !password_verify($senha, $o['senha_hash'])) {
        q('INSERT INTO tentativas_login (ip, em) VALUES (?,?)', [$ip, time()]);
        usleep(400000);   // atrasa tentativas automatizadas
        erro(401, 'E-mail ou senha incorretos');
    }
    q('DELETE FROM tentativas_login WHERE ip=?', [$ip]);
    q('UPDATE operadores SET ultimo_acesso=? WHERE id=?', [agora(), $o['id']]);
    $token = bin2hex(random_bytes(24));
    q('INSERT INTO sessoes_operador (token, operador_id) VALUES (?,?)', [$token, $o['id']]);
    json(['token' => $token, 'operador' => operador_publico($o)]);
}

function rota_painel_logout(): void
{
    q('DELETE FROM sessoes_operador WHERE token=?', [token_requisicao()]);
    json(['ok' => true]);
}

function rota_painel_me(): void
{
    json(operador_publico(operador_atual()));
}

function rota_listar_operadores(): void
{
    operador_atual(true);
    json(array_map('operador_publico', todos('SELECT * FROM operadores ORDER BY id')));
}

function rota_criar_operador(): void
{
    operador_atual(true);
    $b = corpo_json();
    $nome = trim((string) ($b['nome'] ?? ''));
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $senha = (string) ($b['senha'] ?? '');
    $papel = ($b['papel'] ?? '') === 'admin' ? 'admin' : 'operador';
    if (mb_strlen($nome) < 2) erro(422, 'Informe o nome');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro(422, 'E-mail inválido');
    if (strlen($senha) < 8) erro(422, 'A senha precisa ter pelo menos 8 caracteres');
    if (um('SELECT 1 FROM operadores WHERE email=?', [$email])) erro(409, 'Já existe um acesso com este e-mail');
    q('INSERT INTO operadores (nome, email, senha_hash, papel) VALUES (?,?,?,?)',
        [$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $papel]);
    json(operador_publico(um('SELECT * FROM operadores WHERE id=?', [(int) db()->lastInsertId()])));
}

function rota_remover_operador(int $id): void
{
    $eu = operador_atual(true);
    if ($id === (int) $eu['id']) erro(400, 'Você não pode remover o próprio acesso');
    $alvo = um('SELECT * FROM operadores WHERE id=?', [$id]) ?? erro(404, 'Acesso não encontrado');
    if ($alvo['papel'] === 'admin' && (int) um("SELECT COUNT(*) n FROM operadores WHERE papel='admin'")['n'] <= 1) {
        erro(400, 'O painel precisa de pelo menos um administrador');
    }
    q('DELETE FROM sessoes_operador WHERE operador_id=?', [$id]);
    q('DELETE FROM operadores WHERE id=?', [$id]);
    json(['ok' => true]);
}

/** Cada operador troca a propria senha. Encerra as outras sessoes dele. */
function rota_trocar_senha(): void
{
    $eu = operador_atual();
    $b = corpo_json();
    if (!password_verify((string) ($b['atual'] ?? ''), $eu['senha_hash'])) erro(400, 'Senha atual incorreta');
    $nova = (string) ($b['nova'] ?? '');
    if (strlen($nova) < 8) erro(422, 'A nova senha precisa ter pelo menos 8 caracteres');
    q('UPDATE operadores SET senha_hash=? WHERE id=?', [password_hash($nova, PASSWORD_DEFAULT), $eu['id']]);
    q('DELETE FROM sessoes_operador WHERE operador_id=? AND token<>?', [$eu['id'], token_requisicao()]);
    json(['ok' => true]);
}
