<?php
/**
 * Disparador de e-mail via SMTP, sem dependencias (nao precisa de Composer/PHPMailer).
 * Funciona com Gmail (senha de app), Outlook, Resend, SendGrid, Brevo, Amazon SES etc.
 * Sem SMTP_HOST configurado, roda em modo SIMULADO: nada sai, so registra no painel.
 */

declare(strict_types=1);

function email_config(): array
{
    return [
        'host' => env('SMTP_HOST'),
        'port' => (int) env('SMTP_PORT', '587'),
        'user' => env('SMTP_USER'),
        'password' => env('SMTP_PASS'),
        'from_email' => env('SMTP_FROM', env('SMTP_USER')),
        'from_name' => env('SMTP_FROM_NAME', 'Roteiro de Viagem'),
        'seguranca' => strtolower(env('SMTP_SECURITY', 'starttls')),   // starttls | ssl | none
        'ebook' => caminho_ebook(env('EBOOK_PATH', 'assets/ebook_exemplo.pdf')),
        'regulamento_url' => env('REGULAMENTO_URL'),
    ];
}

/** 2026-11-28 -> 28/11/2026; outro texto (ex.: nome do sorteio vindo da planilha) passa igual. */
function data_br(string $s): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $s;
}

function caminho_ebook(string $p): string
{
    // caminho relativo e resolvido a partir da pasta do backend
    return preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $p) ? $p : BASE_DIR . '/' . $p;
}

function email_configurado(): bool
{
    $c = email_config();
    return $c['host'] !== '' && $c['from_email'] !== '';
}

function formatar_endereco(string $nome, string $email): string
{
    return $nome !== '' ? sprintf('%s <%s>', $nome, $email) : $email;
}

/** O que o painel pode mostrar. Nunca devolve a senha. */
function email_status_publico(): array
{
    $c = email_config();
    return [
        'modo' => email_configurado() ? 'real' : 'simulado',
        'host' => $c['host'] ?: null,
        'port' => $c['port'],
        'remetente' => $c['from_email'] ? formatar_endereco($c['from_name'], $c['from_email']) : null,
        'seguranca' => $c['seguranca'],
        'ebook_anexo' => is_file($c['ebook']) ? basename($c['ebook']) : null,
    ];
}

// ---------------------------------------------------------------- template

/**
 * E-mail de entrega com todos os numeros da compra.
 * @param array<array{numero:string,serie:?string,sorteio:?string}> $numeros
 * @return array{0:string,1:string,2:string} [assunto, texto, html]
 */
function montar_entrega(string $nome, array $numeros): array
{
    $c = email_config();
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $primeiroNome = $nome !== '' ? explode(' ', trim($nome))[0] : '';
    $primeiro = $primeiroNome !== '' ? $e($primeiroNome) : 'cliente';
    $qtd = count($numeros);
    $assunto = $qtd === 1 ? "Seu número da sorte: {$numeros[0]['numero']}" : "Seus {$qtd} números da sorte";
    $regTxt = $c['regulamento_url'] ? "\nRegulamento: {$c['regulamento_url']}\n" : '';
    $regHtml = $c['regulamento_url']
        ? '<p style="margin:16px 0 0"><a href="' . $e($c['regulamento_url']) . '" style="color:#e0ad45">Ler o regulamento do sorteio</a></p>'
        : '';

    $linhasTxt = '';
    $linhasHtml = '';
    foreach ($numeros as $n) {
        $serie = $n['serie'] ?: '-';
        $sorteio = data_br($n['sorteio'] ?: '-');
        $linhasTxt .= "  {$n['numero']}  (série {$serie}, sorteio {$sorteio})\n";
        $linhasHtml .= '<tr><td style="padding:10px 14px;font-size:24px;font-weight:700;letter-spacing:.06em;color:#f2c65a;border-top:1px solid #3a3018">'
            . $e($n['numero']) . '</td><td style="padding:10px 14px;font-size:13px;color:#a99d82;border-top:1px solid #3a3018;text-align:right">Série '
            . $e($serie) . ' · Sorteio ' . $e($sorteio) . '</td></tr>';
    }
    $titulo = $qtd === 1 ? 'aqui está o seu número da sorte' : "aqui estão os seus {$qtd} números da sorte";
    $rotulo = $qtd === 1 ? 'NÚMERO DA SORTE' : "SEUS {$qtd} NÚMEROS DA SORTE";

    $texto = "Olá {$primeiroNome}!\n\n"
        . "Seu pagamento foi confirmado.\n\n"
        . "{$rotulo}:\n{$linhasTxt}\n"
        . "O sorteio é feito pela Loteria Federal.\n"
        . "{$regTxt}\n"
        . "O seu e-book segue em anexo.\n\nBoa sorte!\n";

    $html = <<<HTML
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark"></head>
<body style="margin:0;background:#0a0907;font-family:Segoe UI,Arial,sans-serif;color:#f6eedb" bgcolor="#0a0907">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#0a0907" style="background:#0a0907;padding:24px 12px">
    <tr><td align="center">
      <img src="cid:logo" width="160" alt="A Sorte Tá de Pé" style="display:block;width:160px;max-width:60%;height:auto;margin:0 auto 16px;border:0">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#14110c" style="max-width:520px;background:#14110c;border:1px solid #4a3c1c;border-radius:14px">
        <tr><td style="padding:28px 28px 8px">
          <p style="margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#3ccf6a;font-weight:700">Pagamento confirmado</p>
          <h1 style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:23px;line-height:1.25;color:#e0ad45">Olá {$primeiro}, {$titulo}</h1>
        </td></tr>
        <tr><td style="padding:16px 28px">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#1c1810" style="background:#1c1810;border:1px solid #4a3c1c;border-radius:10px">
            <tr><td colspan="2" style="padding:10px 14px 6px;font-size:11px;letter-spacing:.08em;font-weight:700;color:#a99d82">{$rotulo}</td></tr>
            {$linhasHtml}
          </table>
        </td></tr>
        <tr><td style="padding:0 28px 28px;font-size:15px;line-height:1.55;color:#f6eedb">
          <p style="margin:0">O sorteio é feito pela Loteria Federal. Guarde este e-mail como comprovante. Seus números também ficam na sua conta.</p>
          <p style="margin:12px 0 0">O seu e-book com o roteiro segue em anexo.</p>
          {$regHtml}
          <p style="margin:18px 0 0;font-family:Georgia,'Times New Roman',serif;font-size:17px;color:#e0ad45">Boa sorte!</p>
        </td></tr>
      </table>
      <p style="font-size:12px;color:#7d735e;margin:16px 0 0">Você recebeu este e-mail porque comprou o e-book.</p>
    </td></tr>
  </table>
</body></html>
HTML;
    return [$assunto, $texto, $html];
}

// ---------------------------------------------------------------- MIME

function cabecalho_utf8(string $s): string
{
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function montar_mime(array $c, string $destino, string $assunto, string $texto, ?string $html, ?string $anexo): string
{
    $eol = "\r\n";
    $mix = 'mix_' . bin2hex(random_bytes(8));
    $alt = 'alt_' . bin2hex(random_bytes(8));
    $dominio = substr(strrchr($c['from_email'] ?: 'localhost@localhost', '@'), 1) ?: 'localhost';
    $from = $c['from_email'] ?: 'nao-configurado@localhost';

    $h = [
        'From: ' . cabecalho_utf8($c['from_name']) . " <{$from}>",
        "To: <{$destino}>",
        'Subject: ' . cabecalho_utf8($assunto),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . "@{$dominio}>",
        'MIME-Version: 1.0',
        "Content-Type: multipart/mixed; boundary=\"{$mix}\"",
    ];
    $b = "--{$mix}{$eol}Content-Type: multipart/alternative; boundary=\"{$alt}\"{$eol}{$eol}";
    $b .= "--{$alt}{$eol}Content-Type: text/plain; charset=UTF-8{$eol}Content-Transfer-Encoding: base64{$eol}{$eol}"
        . chunk_split(base64_encode($texto), 76, $eol);
    if ($html !== null) {
        $parteHtml = "Content-Type: text/html; charset=UTF-8{$eol}Content-Transfer-Encoding: base64{$eol}{$eol}"
            . chunk_split(base64_encode($html), 76, $eol);
        // HTML que usa cid:logo leva a logo embutida (multipart/related), sem depender de URL publica
        $logo = BASE_DIR . '/assets/logo-email.png';
        if (str_contains($html, 'cid:logo') && is_file($logo)) {
            $rel = 'rel_' . bin2hex(random_bytes(8));
            $b .= "--{$alt}{$eol}Content-Type: multipart/related; boundary=\"{$rel}\"{$eol}{$eol}"
                . "--{$rel}{$eol}{$parteHtml}"
                . "--{$rel}{$eol}Content-Type: image/png; name=\"logo.png\"{$eol}Content-Transfer-Encoding: base64{$eol}"
                . "Content-ID: <logo>{$eol}Content-Disposition: inline; filename=\"logo.png\"{$eol}{$eol}"
                . chunk_split(base64_encode((string) file_get_contents($logo)), 76, $eol)
                . "--{$rel}--{$eol}";
        } else {
            $b .= "--{$alt}{$eol}{$parteHtml}";
        }
    }
    $b .= "--{$alt}--{$eol}";
    if ($anexo !== null) {
        $nome = basename($anexo);
        $b .= "--{$mix}{$eol}Content-Type: application/pdf; name=\"{$nome}\"{$eol}"
            . "Content-Transfer-Encoding: base64{$eol}Content-Disposition: attachment; filename=\"{$nome}\"{$eol}{$eol}"
            . chunk_split(base64_encode((string) file_get_contents($anexo)), 76, $eol);
    }
    $b .= "--{$mix}--{$eol}";
    return implode($eol, $h) . $eol . $eol . $b;
}

// ---------------------------------------------------------------- SMTP

final class SmtpAuthErro extends Exception
{
    public function __construct(public int $smtpCodigo, string $texto)
    {
        parent::__construct($texto);
    }
}

final class Smtp
{
    /** @var resource */
    private $s;
    public array $ehlo = [];

    public function __construct(string $host, int $port, string $seguranca)
    {
        $alvo = ($seguranca === 'ssl' ? 'ssl://' : 'tcp://') . "{$host}:{$port}";
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client($alvo, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new RuntimeException("Não foi possível conectar em {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($s, 20);
        $this->s = $s;
        $this->esperar([220]);
        $this->cumprimentar();
        if ($seguranca === 'starttls') {
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('Falha ao iniciar TLS');
            }
            $this->cumprimentar();
        }
    }

    private function cumprimentar(): void
    {
        [, $linhas] = $this->cmd('EHLO ' . (gethostname() ?: 'localhost'), [250]);
        $this->ehlo = $linhas;
    }

    /** @return array{0:int,1:array<string>} */
    private function ler(): array
    {
        $linhas = [];
        while (($l = fgets($this->s, 4096)) !== false) {
            $linhas[] = rtrim($l, "\r\n");
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        if (!$linhas) {
            throw new RuntimeException('Conexão encerrada inesperadamente pelo servidor');
        }
        return [(int) substr($linhas[0], 0, 3), array_map(fn($x) => substr($x, 4), $linhas)];
    }

    private function esperar(array $codigos): array
    {
        [$cod, $linhas] = $this->ler();
        if (!in_array($cod, $codigos, true)) {
            throw new RuntimeException("SMTP {$cod}: " . implode(' ', $linhas));
        }
        return [$cod, $linhas];
    }

    public function cmd(string $linha, array $codigos): array
    {
        fwrite($this->s, $linha . "\r\n");
        return $this->esperar($codigos);
    }

    /**
     * Login com um unico metodo. Tentar PLAIN e depois LOGIN faz o Gmail derrubar
     * a conexao e o erro real (ex.: 534) se perde.
     */
    public function login(string $user, string $pass): void
    {
        $metodos = [];
        foreach ($this->ehlo as $l) {
            if (stripos($l, 'AUTH') === 0) {
                $metodos = array_map('strtoupper', preg_split('/\s+/', trim(substr($l, 4))));
            }
        }
        if (in_array('PLAIN', $metodos, true) || !$metodos) {
            fwrite($this->s, 'AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}") . "\r\n");
            [$cod, $linhas] = $this->ler();
        } else {
            fwrite($this->s, "AUTH LOGIN\r\n");
            [$cod, $linhas] = $this->ler();
            if ($cod === 334) {
                fwrite($this->s, base64_encode($user) . "\r\n");
                [$cod, $linhas] = $this->ler();
            }
            if ($cod === 334) {
                fwrite($this->s, base64_encode($pass) . "\r\n");
                [$cod, $linhas] = $this->ler();
            }
        }
        if ($cod !== 235 && $cod !== 503) {
            throw new SmtpAuthErro($cod, implode(' ', $linhas));
        }
    }

    public function enviar(string $de, string $para, string $mensagem): void
    {
        $this->cmd("MAIL FROM:<{$de}>", [250]);
        $this->cmd("RCPT TO:<{$para}>", [250, 251]);
        $this->cmd('DATA', [354]);
        // dot-stuffing: linha que comeca com "." ganha outro "."
        $corpo = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $mensagem));
        fwrite($this->s, rtrim($corpo, "\r\n") . "\r\n.\r\n");
        $this->esperar([250]);
    }

    public function __destruct()
    {
        if (is_resource($this->s)) {
            @fwrite($this->s, "QUIT\r\n");
            @fclose($this->s);
        }
    }
}

function explicar_auth(SmtpAuthErro $e): string
{
    $t = $e->getMessage();
    if (str_contains($t, 'Application-specific password required')) {
        return 'Gmail exige senha de app (erro 534). Crie em myaccount.google.com/apppasswords e coloque os 16 caracteres em SMTP_PASS.';
    }
    if ($e->smtpCodigo === 535) {
        return 'Usuario ou senha recusados pelo servidor (535). ' . substr($t, 0, 150);
    }
    return "Login recusado ({$e->smtpCodigo}): " . substr($t, 0, 200);
}

/**
 * Envia um e-mail. Retorna ['status' => enviado|simulado|falha, 'detalhe' => string].
 * Nunca lanca excecao: quem chama decide o que fazer com a falha.
 */
function enviar_email(string $destino, string $assunto, string $texto, ?string $html = null, bool $anexarEbook = true): array
{
    $c = email_config();
    $anexo = ($anexarEbook && is_file($c['ebook'])) ? $c['ebook'] : null;
    $mensagem = montar_mime($c, $destino, $assunto, $texto, $html, $anexo);

    if (!email_configurado()) {
        $sufixo = $anexo ? ' com anexo ' . basename($anexo) : '';
        return ['status' => 'simulado', 'detalhe' => "SMTP nao configurado. E-mail montado{$sufixo}, nao enviado."];
    }
    try {
        $smtp = new Smtp($c['host'], $c['port'], $c['seguranca']);
        if ($c['user'] !== '') {
            $smtp->login($c['user'], $c['password']);
        }
        $smtp->enviar($c['from_email'], $destino, $mensagem);
        unset($smtp);
        return ['status' => 'enviado', 'detalhe' => "via {$c['host']}"];
    } catch (SmtpAuthErro $e) {
        return ['status' => 'falha', 'detalhe' => explicar_auth($e)];
    } catch (Throwable $e) {
        return ['status' => 'falha', 'detalhe' => (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage()];
    }
}
