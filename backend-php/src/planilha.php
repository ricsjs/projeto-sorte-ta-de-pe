<?php
/**
 * Leitura de planilha sem dependencias: .xlsx (primeira aba, via ZipArchive + XML) ou .csv.
 * Retorna lista de linhas como ['coluna_em_minusculo' => 'valor'].
 */

declare(strict_types=1);

function ler_planilha(string $nome, string $caminho): array
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    return in_array($ext, ['xlsx', 'xlsm'], true) ? ler_xlsx($caminho) : ler_csv($caminho);
}

function ler_csv(string $caminho): array
{
    $raw = (string) file_get_contents($caminho);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);           // remove BOM do Excel
    $primeira = strtok($raw, "\r\n") ?: '';
    $sep = substr_count($primeira, ';') > substr_count($primeira, ',') ? ';' : ',';

    $f = fopen('php://temp', 'r+');
    fwrite($f, $raw);
    rewind($f);
    $cab = fgetcsv($f, 0, $sep, '"', '');
    if (!$cab) {
        return [];
    }
    $cab = array_map(fn($h) => strtolower(trim((string) $h)), $cab);
    $out = [];
    while (($l = fgetcsv($f, 0, $sep, '"', '')) !== false) {
        if ($l === [null]) {
            continue;   // linha vazia
        }
        $linha = [];
        foreach ($cab as $i => $h) {
            $linha[$h] = $l[$i] ?? '';
        }
        $out[] = $linha;
    }
    fclose($f);
    return $out;
}

function ler_xlsx(string $caminho): array
{
    $zip = new ZipArchive();
    if ($zip->open($caminho) !== true) {
        erro(400, 'Arquivo .xlsx inválido');
    }

    // textos compartilhados
    $textos = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $ss = simplexml_load_string($xml);
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $textos[] = (string) $si->t;
            } else {                                              // texto com formatacao (runs)
                $t = '';
                foreach ($si->r as $r) {
                    $t .= (string) $r->t;
                }
                $textos[] = $t;
            }
        }
    }

    // primeira aba: workbook.xml -> r:id -> workbook.xml.rels -> arquivo da aba
    $arquivoAba = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $wbx = simplexml_load_string($wb);
        $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $aba = $wbx->xpath('//m:sheets/m:sheet')[0] ?? null;
        if ($aba) {
            $rid = (string) $aba->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach (simplexml_load_string($rels)->Relationship as $r) {
                if ((string) $r['Id'] === $rid) {
                    $alvo = ltrim((string) $r['Target'], '/');
                    $arquivoAba = str_starts_with($alvo, 'xl/') ? $alvo : 'xl/' . $alvo;
                }
            }
        }
    }
    $xmlAba = $zip->getFromName($arquivoAba);
    $zip->close();
    if ($xmlAba === false) {
        erro(400, 'Não encontrei a primeira aba da planilha');
    }

    $grade = [];
    foreach (simplexml_load_string($xmlAba)->sheetData->row as $row) {
        $valores = [];
        foreach ($row->c as $c) {
            $col = coluna_indice(preg_replace('/\d+/', '', (string) $c['r']));
            $tipo = (string) $c['t'];
            if ($tipo === 's') {
                $v = $textos[(int) $c->v] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $v = (string) $c->is->t;
            } else {
                $v = (string) $c->v;
            }
            $valores[$col] = $v;
        }
        $grade[] = $valores;
    }
    if (!$grade) {
        return [];
    }

    $cab = [];
    foreach (array_shift($grade) as $i => $h) {
        $cab[$i] = strtolower(trim($h));
    }
    $out = [];
    foreach ($grade as $valores) {
        if (!array_filter($valores, fn($v) => $v !== '')) {
            continue;
        }
        $linha = [];
        foreach ($cab as $i => $h) {
            $linha[$h] = $valores[$i] ?? '';
        }
        $out[] = $linha;
    }
    return $out;
}

/** "A" -> 0, "B" -> 1, "AA" -> 26 */
function coluna_indice(string $letras): int
{
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $l) {
        $n = $n * 26 + (ord($l) - 64);
    }
    return $n - 1;
}
