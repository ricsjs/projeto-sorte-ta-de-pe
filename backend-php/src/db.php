<?php
/**
 * Banco (PDO) e utilidades de resposta HTTP.
 * DB_DRIVER=sqlite (padrao, arquivo data/poc.db) ou DB_DRIVER=mysql (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
 * O SQL das regras de negocio e o mesmo nos dois. O que muda fica nos helpers abaixo:
 * agora(), transacao(), para_update(), concat_numeros() e set_cfg().
 */

declare(strict_types=1);

const BASE_DIR = __DIR__ . '/..';

function mysql(): bool
{
    return env('DB_DRIVER', 'sqlite') === 'mysql';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $opcoes = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (mysql()) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_NAME', 'numero_sorte'));
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS'), $opcoes + [
            PDO::ATTR_EMULATE_PREPARES => false,     // inteiros e decimais voltam como numero, igual ao SQLite
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // CURRENT_TIMESTAMP no mesmo fuso do PHP, para bater com agora()
        $pdo->exec("SET time_zone = '" . date('P') . "', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        init_mysql($pdo);
    } else {
        $pdo = new PDO('sqlite:' . (getenv('DB_PATH') ?: BASE_DIR . '/data/poc.db'), null, null, $opcoes + [PDO::ATTR_TIMEOUT => 10]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        init_sqlite($pdo);
    }
    return $pdo;
}

/** Data e hora local no formato do banco (AAAA-MM-DD HH:MM:SS). */
function agora(int $deslocamentoSeg = 0): string
{
    return date('Y-m-d H:i:s', time() + $deslocamentoSeg);
}

/**
 * Roda $fn numa transacao de escrita.
 * SQLite: BEGIN IMMEDIATE trava o banco inteiro para escrita.
 * MySQL: transacao normal; quem precisa de exclusividade usa para_update() nos SELECTs.
 */
function transacao(callable $fn): mixed
{
    $pdo = db();
    for ($tentativa = 1; ; $tentativa++) {
        mysql() ? $pdo->beginTransaction() : $pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $fn();
            mysql() ? $pdo->commit() : $pdo->exec('COMMIT');
            return $r;
        } catch (Throwable $e) {
            mysql() ? $pdo->rollBack() : $pdo->exec('ROLLBACK');
            // MySQL desfaz uma das transacoes num deadlock (1213) ou espera longa (1205): repetir e seguro
            $repetir = mysql() && $e instanceof PDOException && in_array($e->errorInfo[1] ?? 0, [1205, 1213], true);
            if (!$repetir || $tentativa >= 3) {
                throw $e;
            }
            usleep(random_int(20_000, 120_000));
        }
    }
}

/** Sufixo de SELECT que trava as linhas lidas ate o fim da transacao (so no MySQL; no SQLite o banco ja esta travado). */
function para_update(): string
{
    return mysql() ? ' FOR UPDATE' : '';
}

/** Lista "a, b, c" agregada de uma coluna. */
function concat_numeros(string $coluna): string
{
    return mysql() ? "GROUP_CONCAT({$coluna} ORDER BY {$coluna} SEPARATOR ', ')" : "GROUP_CONCAT({$coluna}, ', ')";
}

/**
 * Schema MySQL (InnoDB, utf8mb4). Criado uma vez, quando a tabela config ainda nao existe.
 * Bases SQLite antigas migram com: php ferramentas/sqlite_para_mysql.php
 */
function init_mysql(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'config'")->fetch()) {
        return;
    }
    $agora = 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
    $tabelas = [
        "CREATE TABLE IF NOT EXISTS sorteios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            data_sorteio DATE NULL,
            ativo TINYINT NOT NULL DEFAULT 0,
            ativo_unico TINYINT AS (IF(ativo = 1, 1, NULL)) STORED,   -- no maximo 1 ativo
            criado_em {$agora},
            UNIQUE KEY uk_um_sorteio_ativo (ativo_unico)
        )",
        "CREATE TABLE IF NOT EXISTS lotes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            arquivo VARCHAR(255) NOT NULL,
            importado_em {$agora},
            total INT NOT NULL,
            sorteio_id INT NULL,
            FOREIGN KEY (sorteio_id) REFERENCES sorteios(id)
        )",
        "CREATE TABLE IF NOT EXISTS clientes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            cpf CHAR(11) NOT NULL UNIQUE,
            email VARCHAR(190) NOT NULL UNIQUE,
            whatsapp VARCHAR(20) NOT NULL,
            senha_hash VARCHAR(255) NOT NULL,
            asaas_id VARCHAR(50) NULL,
            criado_em {$agora}
        )",
        "CREATE TABLE IF NOT EXISTS vendas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT NULL,
            sorteio_id INT NULL,
            qtd INT NOT NULL DEFAULT 1,
            nome VARCHAR(150) NOT NULL,
            cpf VARCHAR(14) NOT NULL,              -- vendas antigas guardavam o CPF com pontuacao
            whatsapp VARCHAR(20) NOT NULL,
            email VARCHAR(190) NOT NULL,
            valor DOUBLE NOT NULL,
            txid VARCHAR(100) NOT NULL UNIQUE,
            pix_copia_cola TEXT NOT NULL,
            pix_qrcode MEDIUMTEXT NULL,
            link_pagamento VARCHAR(1000) NULL,
            conferido_em BIGINT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'aguardando_pagamento',
            criado_em {$agora},
            pago_em DATETIME NULL,
            KEY idx_vendas_cliente (cliente_id),
            FOREIGN KEY (cliente_id) REFERENCES clientes(id),
            FOREIGN KEY (sorteio_id) REFERENCES sorteios(id)
        )",
        "CREATE TABLE IF NOT EXISTS numeros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lote_id INT NOT NULL,
            sorteio_id INT NOT NULL,
            numero VARCHAR(50) NOT NULL,
            serie VARCHAR(50) NULL,
            sorteio VARCHAR(150) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'livre',
            venda_id INT NULL,
            atribuido_em DATETIME NULL,
            UNIQUE KEY uk_numero_sorteio (sorteio_id, numero),   -- o mesmo numero pode existir em sorteios diferentes
            KEY idx_numeros_venda (venda_id),
            KEY idx_numeros_sorteio (sorteio_id, status),
            FOREIGN KEY (lote_id) REFERENCES lotes(id),
            FOREIGN KEY (sorteio_id) REFERENCES sorteios(id),
            FOREIGN KEY (venda_id) REFERENCES vendas(id)
        )",
        "CREATE TABLE IF NOT EXISTS envios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            venda_id INT NOT NULL,
            canal VARCHAR(20) NOT NULL,
            destino VARCHAR(190) NOT NULL,
            mensagem TEXT NOT NULL,
            status VARCHAR(20) NOT NULL,
            detalhe TEXT NULL,
            enviado_em {$agora},
            FOREIGN KEY (venda_id) REFERENCES vendas(id)
        )",
        "CREATE TABLE IF NOT EXISTS operadores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            senha_hash VARCHAR(255) NOT NULL,
            papel VARCHAR(20) NOT NULL DEFAULT 'operador',
            criado_em {$agora},
            ultimo_acesso DATETIME NULL
        )",
        "CREATE TABLE IF NOT EXISTS sessoes_operador (
            token CHAR(48) PRIMARY KEY,
            operador_id INT NOT NULL,
            criado_em {$agora},
            FOREIGN KEY (operador_id) REFERENCES operadores(id)
        )",
        "CREATE TABLE IF NOT EXISTS tentativas_login (
            ip VARCHAR(45) NOT NULL,
            em BIGINT NOT NULL,
            KEY idx_tentativas_ip (ip)
        )",
        "CREATE TABLE IF NOT EXISTS sessoes_cliente (
            token CHAR(48) PRIMARY KEY,
            cliente_id INT NOT NULL,
            criado_em {$agora},
            FOREIGN KEY (cliente_id) REFERENCES clientes(id)
        )",
        // por ultimo: a existencia de config marca o schema como pronto
        "CREATE TABLE IF NOT EXISTS config (
            chave VARCHAR(50) PRIMARY KEY,
            valor VARCHAR(255) NOT NULL
        )",
        "INSERT IGNORE INTO config VALUES ('vendas_pausadas','0'), ('alerta_estoque_minimo','3'), ('valor_ebook','29.90')",
    ];
    foreach ($tabelas as $sql) {
        $pdo->exec($sql . (str_starts_with($sql, 'CREATE') ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }
}

function init_sqlite(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS lotes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  arquivo TEXT NOT NULL,
  importado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  total INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS sorteios (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT NOT NULL,
  data_sorteio TEXT,
  ativo INTEGER NOT NULL DEFAULT 0,      -- no maximo 1 ativo (indice abaixo)
  criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_um_sorteio_ativo ON sorteios(ativo) WHERE ativo=1;
CREATE TABLE IF NOT EXISTS numeros (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lote_id INTEGER NOT NULL REFERENCES lotes(id),
  sorteio_id INTEGER NOT NULL REFERENCES sorteios(id),
  numero TEXT NOT NULL,
  serie TEXT,
  sorteio TEXT,
  status TEXT NOT NULL DEFAULT 'livre',
  venda_id INTEGER REFERENCES vendas(id),
  atribuido_em TEXT,
  UNIQUE (sorteio_id, numero)            -- o mesmo numero pode existir em sorteios diferentes
);
CREATE TABLE IF NOT EXISTS vendas (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT NOT NULL,
  cpf TEXT NOT NULL,
  whatsapp TEXT NOT NULL,
  email TEXT NOT NULL,
  valor REAL NOT NULL,
  txid TEXT NOT NULL UNIQUE,
  pix_copia_cola TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'aguardando_pagamento',
  criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  pago_em TEXT
);
CREATE TABLE IF NOT EXISTS envios (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  venda_id INTEGER NOT NULL REFERENCES vendas(id),
  canal TEXT NOT NULL,
  destino TEXT NOT NULL,
  mensagem TEXT NOT NULL,
  status TEXT NOT NULL,
  detalhe TEXT,
  enviado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS config (
  chave TEXT PRIMARY KEY,
  valor TEXT NOT NULL
);
INSERT OR IGNORE INTO config VALUES ('vendas_pausadas', '0');
INSERT OR IGNORE INTO config VALUES ('alerta_estoque_minimo', '3');
INSERT OR IGNORE INTO config VALUES ('valor_ebook', '29.90');
SQL);
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS clientes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT NOT NULL,
  cpf TEXT NOT NULL UNIQUE,              -- so digitos
  email TEXT NOT NULL UNIQUE,            -- minusculo
  whatsapp TEXT NOT NULL,
  senha_hash TEXT NOT NULL,
  criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS operadores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nome TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  senha_hash TEXT NOT NULL,
  papel TEXT NOT NULL DEFAULT 'operador',   -- admin | operador
  criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  ultimo_acesso TEXT
);
CREATE TABLE IF NOT EXISTS sessoes_operador (
  token TEXT PRIMARY KEY,
  operador_id INTEGER NOT NULL REFERENCES operadores(id),
  criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS tentativas_login (
  ip TEXT NOT NULL,
  em INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS sessoes_cliente (
  token TEXT PRIMARY KEY,
  cliente_id INTEGER NOT NULL REFERENCES clientes(id),
  criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
SQL);
    // migracoes de bases antigas
    $cols = array_column($pdo->query('PRAGMA table_info(envios)')->fetchAll(), 'name');
    if (!in_array('detalhe', $cols, true)) {
        $pdo->exec('ALTER TABLE envios ADD COLUMN detalhe TEXT');
    }
    $cols = array_column($pdo->query('PRAGMA table_info(vendas)')->fetchAll(), 'name');
    if (!in_array('cliente_id', $cols, true)) {
        $pdo->exec('ALTER TABLE vendas ADD COLUMN cliente_id INTEGER REFERENCES clientes(id)');
    }
    if (!in_array('qtd', $cols, true)) {
        $pdo->exec('ALTER TABLE vendas ADD COLUMN qtd INTEGER NOT NULL DEFAULT 1');
    }
    foreach (['pix_qrcode TEXT', 'link_pagamento TEXT', 'conferido_em INTEGER'] as $col) {
        if (!in_array(explode(' ', $col)[0], $cols, true)) {
            $pdo->exec("ALTER TABLE vendas ADD COLUMN {$col}");
        }
    }
    if (!in_array('asaas_id', array_column($pdo->query('PRAGMA table_info(clientes)')->fetchAll(), 'name'), true)) {
        $pdo->exec('ALTER TABLE clientes ADD COLUMN asaas_id TEXT');
    }
    migrar_sorteios($pdo);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vendas_cliente ON vendas(cliente_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_numeros_sorteio ON numeros(sorteio_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_numeros_venda ON numeros(venda_id)');
}

/**
 * Bases de antes dos sorteios: cria um sorteio para cada valor da coluna numeros.sorteio,
 * recria numeros com sorteio_id (o UNIQUE passa a ser por sorteio) e liga lotes e vendas.
 */
function migrar_sorteios(PDO $pdo): void
{
    foreach (['lotes', 'vendas'] as $t) {
        if (!in_array('sorteio_id', array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(), 'name'), true)) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN sorteio_id INTEGER REFERENCES sorteios(id)");
        }
    }
    if (in_array('sorteio_id', array_column($pdo->query('PRAGMA table_info(numeros)')->fetchAll(), 'name'), true)) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $ins = $pdo->prepare('INSERT INTO sorteios (nome, data_sorteio) VALUES (?,?)');
        foreach ($pdo->query("SELECT DISTINCT COALESCE(NULLIF(sorteio,''),'') s FROM numeros ORDER BY 1")->fetchAll() as $r) {
            $ins->execute([$r['s'] === '' ? 'Sorteio 1' : 'Sorteio ' . $r['s'], $r['s'] ?: null]);
        }
        $pdo->exec(<<<SQL
CREATE TABLE numeros_novo (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lote_id INTEGER NOT NULL REFERENCES lotes(id),
  sorteio_id INTEGER NOT NULL REFERENCES sorteios(id),
  numero TEXT NOT NULL,
  serie TEXT,
  sorteio TEXT,
  status TEXT NOT NULL DEFAULT 'livre',
  venda_id INTEGER REFERENCES vendas(id),
  atribuido_em TEXT,
  UNIQUE (sorteio_id, numero)
);
INSERT INTO numeros_novo (id, lote_id, sorteio_id, numero, serie, sorteio, status, venda_id, atribuido_em)
  SELECT n.id, n.lote_id,
         (SELECT s.id FROM sorteios s WHERE COALESCE(s.data_sorteio,'') = COALESCE(NULLIF(n.sorteio,''),'')),
         n.numero, n.serie, n.sorteio, n.status, n.venda_id, n.atribuido_em
    FROM numeros n;
DROP TABLE numeros;
ALTER TABLE numeros_novo RENAME TO numeros;
CREATE INDEX IF NOT EXISTS idx_numeros_venda ON numeros(venda_id);
UPDATE lotes SET sorteio_id = (SELECT MIN(n.sorteio_id) FROM numeros n WHERE n.lote_id = lotes.id);
UPDATE vendas SET sorteio_id = (SELECT MIN(n.sorteio_id) FROM numeros n WHERE n.venda_id = vendas.id);
UPDATE sorteios SET ativo = 1 WHERE id = (
  SELECT sorteio_id FROM numeros WHERE status='livre' GROUP BY sorteio_id ORDER BY COUNT(*) DESC, sorteio_id DESC LIMIT 1);
UPDATE vendas SET sorteio_id = (SELECT id FROM sorteios WHERE ativo=1) WHERE sorteio_id IS NULL;
SQL);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Executa uma consulta preparada e devolve o statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    foreach (array_values($params) as $i => $v) {
        // inteiro vai como inteiro: o MySQL com prepare nativo recusa LIMIT '3'
        $st->bindValue($i + 1, $v, match (true) {
            is_int($v) => PDO::PARAM_INT,
            is_null($v) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        });
    }
    $st->execute();
    return $st;
}

function um(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function todos(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function cfg(string $chave): string
{
    return (string) um('SELECT valor FROM config WHERE chave=?', [$chave])['valor'];
}

function set_cfg(string $chave, string $valor): void
{
    q(mysql() ? 'INSERT INTO config VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)'
              : 'INSERT OR REPLACE INTO config VALUES (?,?)', [$chave, $valor]);
}

// ---------------------------------------------------------------- .env

function carregar_env(): void
{
    $f = BASE_DIR . '/.env';
    if (!is_file($f)) {
        return;
    }
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $linha, 2);
        $k = trim($k);
        if (getenv($k) === false) {
            putenv($k . '=' . trim(trim($v), "\"'"));
        }
    }
}

function env(string $k, string $padrao = ''): string
{
    $v = getenv($k);
    return $v === false ? $padrao : $v;
}

// ---------------------------------------------------------------- HTTP

final class HttpErro extends Exception
{
    public function __construct(public int $codigo, string $msg = '')
    {
        parent::__construct($msg);
    }
}

/** Formato de erro da API: {"detail": "..."} */
function erro(int $codigo, string $msg = ''): never
{
    throw new HttpErro($codigo, $msg ?: ($codigo === 404 ? 'Not Found' : 'Erro'));
}

function json(mixed $dados, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
}

function corpo_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        erro(422, 'Corpo JSON inválido');
    }
    return $d;
}

/**
 * Responde ao cliente e continua executando depois (equivalente ao BackgroundTasks).
 * PHP-FPM: fastcgi_finish_request. Apache/mod_php: Content-Length + Connection: close.
 * No servidor embutido (php -S) o cliente espera o envio terminar, o que so afeta o tempo de resposta.
 */
function responder_e_continuar(array $dados, callable $depois): void
{
    ignore_user_abort(true);
    $corpo = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($corpo));
    header('Connection: close');
    echo $corpo;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
    try {
        $depois();
    } catch (Throwable $e) {
        error_log('Falha na tarefa em segundo plano: ' . $e->getMessage());
    }
}
