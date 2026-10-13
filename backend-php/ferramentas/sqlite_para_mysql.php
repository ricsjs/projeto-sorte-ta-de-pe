<?php
/**
 * Copia todos os dados do SQLite (data/poc.db) para o MySQL configurado no .env.
 *
 * Uso:  php ferramentas/sqlite_para_mysql.php [caminho/do/arquivo.db]
 *
 * - Le DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS do .env (DB_DRIVER e ignorado aqui).
 * - Cria as tabelas no MySQL se ainda nao existirem.
 * - Recusa rodar se o MySQL ja tiver vendas, clientes ou numeros, para nao misturar bases.
 * - Mantem os ids originais, entao vendas, numeros e sessoes continuam ligados.
 * Depois de conferir, troque DB_DRIVER=mysql no .env.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Rode pelo terminal.\n");
}

require __DIR__ . '/../src/db.php';
carregar_env();
date_default_timezone_set(env('TZ', 'America/Sao_Paulo'));

$origem = $argv[1] ?? (getenv('DB_PATH') ?: BASE_DIR . '/data/poc.db');
if (!is_file($origem)) {
    exit("Arquivo SQLite nao encontrado: {$origem}\n");
}

// origem: abre o SQLite pelo caminho normal, o que tambem aplica as migracoes pendentes
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $origem);
$sqlite = db();

// destino: conexao nova, com o schema MySQL
putenv('DB_DRIVER=mysql');
$mysql = (function (): PDO {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_NAME', 'numero_sorte'));
    $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    init_mysql($pdo);
    return $pdo;
})();

foreach (['vendas', 'clientes', 'numeros'] as $t) {
    $n = (int) $mysql->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    if ($n > 0) {
        exit("O MySQL ja tem {$n} registro(s) em {$t}. Use um banco vazio.\n");
    }
}

// ordem respeita as chaves estrangeiras
$tabelas = ['sorteios', 'lotes', 'clientes', 'vendas', 'numeros', 'envios', 'operadores',
            'sessoes_operador', 'tentativas_login', 'sessoes_cliente', 'config'];

$mysql->beginTransaction();
try {
    $mysql->exec('DELETE FROM config');   // valores padrao do schema dao lugar aos da base antiga
    foreach ($tabelas as $t) {
        $destino = array_column($mysql->query("SHOW COLUMNS FROM {$t} WHERE Extra NOT IN ('VIRTUAL GENERATED', 'STORED GENERATED')")->fetchAll(), 'Field');
        $linhas = $sqlite->query("SELECT * FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
        if (!$linhas) {
            echo str_pad($t, 18) . "0\n";
            continue;
        }
        $cols = array_values(array_intersect(array_keys($linhas[0]), $destino));
        $faltando = array_diff(array_keys($linhas[0]), $destino);
        if ($faltando) {
            echo "  aviso: {$t} tem colunas que o MySQL nao tem e serao ignoradas: " . implode(', ', $faltando) . "\n";
        }
        $ins = $mysql->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $t, implode(',', $cols),
            implode(',', array_fill(0, count($cols), '?'))));
        foreach ($linhas as $l) {
            $ins->execute(array_map(fn($c) => $l[$c] === '' && in_array($c, ['data_sorteio', 'pago_em', 'atribuido_em', 'ultimo_acesso'], true)
                ? null : $l[$c], $cols));
        }
        echo str_pad($t, 18) . count($linhas) . "\n";
    }
    $mysql->commit();
} catch (Throwable $e) {
    $mysql->rollBack();
    exit("Falhou, nada foi gravado no MySQL: {$e->getMessage()}\n");
}

// confere contagens
$erros = 0;
foreach ($tabelas as $t) {
    $a = (int) $sqlite->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    $b = (int) $mysql->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    if ($a !== $b) {
        echo "DIFERENCA em {$t}: SQLite {$a}, MySQL {$b}\n";
        $erros++;
    }
}
echo $erros ? "Terminou com diferencas.\n" : "Pronto. Contagens conferem. Agora use DB_DRIVER=mysql no .env.\n";
