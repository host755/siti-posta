<?php
// Invio dell'email di una demo tramite FreeScout (progetto SitiDemo).
// Uso: php invia.php ID   -> legge posta/ID.json e crea in FreeScout una conversazione in uscita (thread "message"):
// FreeScout manda l'email al destinatario dalla casella indicata.
// File del lavoro (JSON): {"destinatario":"...","oggetto":"...","testo":"...","casella":1}   (casella facoltativa)
// Configurazione (fuori dal repository, permessi 600): /var/www/vhosts/cloud755.com/posta-config.json
//   {"url_freescout":"https://...","chiave_freescout":"...","casella":1,"utente_freescout":0}
// Esito stampato: "OK id=NUMERO ..." oppure "ERRORE motivo=..." (lo legge l'esecutore e lo pubblica nel ramo esiti).
// Un lavoro gia' inviato non si invia mai due volte (marcatore); tetto di 20 invii al giorno.

$CFG = getenv('POSTA_CFG') ?: '/var/www/vhosts/cloud755.com/posta-config.json';
$DONE = getenv('POSTA_DONE') ?: '/var/www/vhosts/cloud755.com/posta-inviati';
const TETTO_GIORNO = 20;

function esci(string $riga, int $codice = 0): void { echo $riga . "\n"; exit($codice); }

$id = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $id)) esci('ERRORE motivo=id_non_valido', 1);
$file = __DIR__ . '/posta/' . $id . '.json';
if (!is_file($file)) esci('ERRORE motivo=file_lavoro_mancante', 1);
$job = json_decode((string) file_get_contents($file), true);
if (!is_array($job)) esci('ERRORE motivo=json_non_valido', 1);

$dest = strtolower(trim((string) ($job['destinatario'] ?? '')));
$ogg = trim((string) ($job['oggetto'] ?? ''));
$testo = trim((string) ($job['testo'] ?? ''));
if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) esci('ERRORE motivo=destinatario_non_valido', 1);
if ($ogg === '' || $testo === '') esci('ERRORE motivo=oggetto_o_testo_vuoto', 1);
if (mb_strlen($ogg) > 200 || mb_strlen($testo) > 8000) esci('ERRORE motivo=testo_troppo_lungo', 1);

$cfg = json_decode((string) @file_get_contents($CFG), true);
if (!is_array($cfg) || empty($cfg['chiave_freescout'])) esci('ERRORE motivo=chiave_freescout_mancante', 1);
$base = rtrim((string) ($cfg['url_freescout'] ?? ''), '/');
if ($base === '') esci('ERRORE motivo=url_freescout_mancante', 1);
$casella = (int) ($job['casella'] ?? ($cfg['casella'] ?? 1));
if ($casella < 1) esci('ERRORE motivo=casella_non_valida', 1);

if (!is_dir($DONE)) @mkdir($DONE, 0700, true);
$marca = "$DONE/$id.done";
if (is_file($marca)) esci('GIA_INVIATO motivo=marcatore_presente');
$oggi = 0;
foreach ((array) glob("$DONE/*.done") as $f) if (date('Y-m-d', (int) filemtime($f)) === date('Y-m-d')) $oggi++;
if ($oggi >= TETTO_GIORNO) esci('ERRORE motivo=tetto_giornaliero', 1);

function fs(array $cfg, string $base, string $metodo, string $percorso, ?array $corpo, ?int &$idRisorsa = null): array {
    $ch = curl_init($base . $percorso);
    $opz = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-FreeScout-API-Key: ' . $cfg['chiave_freescout']],
        CURLOPT_HEADERFUNCTION => function ($c, $riga) use (&$idRisorsa) {
            if (stripos($riga, 'Resource-ID:') === 0) $idRisorsa = (int) trim(substr($riga, 12));
            return strlen($riga);
        },
    ];
    if ($corpo !== null) $opz[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opz);
    $out = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $out === false ? curl_error($ch) : '';
    curl_close($ch);
    return [$http, is_string($out) ? $out : '', $err];
}

// Utente che firma il messaggio (obbligatorio per i thread di tipo message): quello impostato, altrimenti il primo dell'installazione.
$uid = (int) ($cfg['utente_freescout'] ?? 0);
if ($uid < 1) {
    [$h, $o, $e] = fs($cfg, $base, 'GET', '/api/users', null);
    if ($h !== 200) esci('ERRORE motivo=utenti_http_' . $h . ($e !== '' ? ' rete=' . $e : ''), 1);
    $j = json_decode($o, true);
    $uid = (int) ($j['_embedded']['users'][0]['id'] ?? 0);
    if ($uid < 1) esci('ERRORE motivo=utente_freescout_non_trovato', 1);
}

$html = '';
foreach (preg_split('/\R{2,}/u', $testo) as $par) {
    $par = trim($par);
    if ($par !== '') $html .= '<p>' . nl2br(htmlspecialchars($par, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
}

$corpo = [
    'type' => 'email',
    'mailboxId' => $casella,
    'subject' => $ogg,
    'customer' => ['email' => $dest],
    'threads' => [['type' => 'message', 'text' => $html, 'user' => $uid, 'to' => [$dest]]],
    'imported' => false,
];
$conv = null;
[$http, $out, $err] = fs($cfg, $base, 'POST', '/api/conversations', $corpo, $conv);
if ($err !== '') esci('ERRORE motivo=rete dettaglio=' . $err, 1);
if ($http !== 201) esci('ERRORE motivo=http_' . $http . ' dettaglio=' . mb_substr(preg_replace('/\s+/', ' ', $out), 0, 200), 1);

@file_put_contents($marca, date('c') . " conv=$conv\n");
esci('OK id=' . (int) $conv . ' casella=' . $casella);
