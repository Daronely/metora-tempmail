<?php
/**
 * Minimal IMAP4rev1 client over raw sockets — zero extensions required.
 * Used as fallback by cron/imap_poll.php when the PHP IMAP extension is
 * missing or broken. Implements exactly what we need: LOGIN, SELECT,
 * SEARCH ALL, UID FETCH (raw RFC822), STORE +FLAGS \Deleted, EXPUNGE, LOGOUT.
 *
 * TLS is supported via stream_socket_enable_crypto (best-effort).
 */
class ImapSocketClient {
    public string $lastError = '';
    private $fp = null;
    private int $tagCounter = 0;
    private string $selectedMailbox = '';

    public function connect(string $host, int $port, bool $tls, string $user, string $pass): bool {
        $errno = 0; $errstr = '';
        $remote = ($tls ? 'tls://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $this->fp = @stream_socket_client($remote, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) { $this->lastError = "connect $remote: $errstr ($errno)"; return false; }
        stream_set_timeout($this->fp, 12);
        if ($tls) {
            // for tls:// the handshake is implicit; if server sent greeting we're fine
        }
        $greet = $this->readLine();
        if ($greet === null || strpos($greet, '* OK') === false) {
            $this->lastError = 'bad greeting: ' . substr((string)$greet, 0, 80);
            return false;
        }
        return $this->login($user, $pass);
    }

    private function nextTag(): string {
        return 'A' . (++$this->tagCounter);
    }

    private function readLine() {
        $line = fgets($this->fp);
        if ($line === false) { $this->lastError = 'read failed'; return null; }
        return $line;
    }

    /** run a command; returns [taggedStatusLine, allUntaggedLines] or null on socket death */
    private function command(string $cmd, bool $literalAware = false): ?array {
        $tag = $this->nextTag();
        if (fwrite($this->fp, "$tag $cmd\r\n") === false) { $this->lastError = 'write failed'; return null; }
        $untagged = [];
        $status = '';
        while (true) {
            $line = $this->readLine();
            if ($line === null) return null;
            $line = rtrim($line, "\r\n");
            if (strncmp($line, $tag . ' ', strlen($tag) + 1) === 0) { $status = $line; break; }
            $untagged[] = $line;
            // literal continuation: server expects us to keep reading
            if ($literalAware && preg_match('/\{(\d+)\}$/', $line, $m)) {
                $need = (int)$m[1];
                $buf = '';
                while (strlen($buf) < $need + 2) {   // +2 for trailing CRLF
                    $chunk = fread($this->fp, $need + 2 - strlen($buf));
                    if ($chunk === false || $chunk === '') break;
                    $buf .= $chunk;
                }
                $untagged[] = $buf;
            }
        }
        return [$status, $untagged];
    }

    private function login(string $user, string $pass): bool {
        $res = $this->command('LOGIN "' . addslashes($user) . '" "' . addslashes($pass) . '"');
        if ($res === null) return false;
        if (strpos($res[0], 'OK') === false) { $this->lastError = 'login rejected: ' . $res[0]; return false; }
        return true;
    }

    public function selectInbox(): bool {
        $res = $this->command('SELECT INBOX');
        if ($res === null || strpos($res[0], 'OK') === false) { $this->lastError = 'select failed: ' . ($res[0] ?? ''); return false; }
        $this->selectedMailbox = 'INBOX';
        return true;
    }

    /** @return int[] message numbers (sequence numbers) of all messages */
    public function searchAll(): array {
        if ($this->selectedMailbox === '') $this->selectInbox();
        $res = $this->command('SEARCH ALL');
        if ($res === null || strpos($res[0], 'OK') === false) return [];
        $seq = [];
        foreach ($res[1] as $line) {
            if (preg_match('/^\* SEARCH (.*)$/', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $n) if (ctype_digit($n)) $seq[] = (int)$n;
            }
        }
        return $seq;
    }

    /** fetch the full raw RFC822 of a message (by sequence number) */
    public function fetchRaw(int $seqNum): ?string {
        $res = $this->command("FETCH $seqNum (BODY.PEEK[])", true);
        if ($res === null || strpos($res[0], 'OK') === false) return null;
        // gather literal payloads between the * FETCH header and trailing )
        $raw = '';
        $inLiteral = false;
        foreach ($res[1] as $chunk) {
            if (!$inLiteral && preg_match('/\{(\d+)\}\r?$/', $chunk, $m)) { $inLiteral = true; continue; }
            if ($inLiteral) { $raw .= $chunk; }
        }
        // the literal is followed by ")\r\n" — strip trailing ")" line
        $raw = preg_replace('/\)\s*$/s', '', $raw);
        return $raw !== '' ? $raw : null;
    }

    public function deleteMessage(int $seqNum): void {
        $this->command("STORE $seqNum +FLAGS (\\Deleted)");
    }

    public function expunge(): void {
        $this->command('EXPUNGE');
    }

    public function disconnect(): void {
        if ($this->fp) { @fwrite($this->fp, $this->nextTag() . " LOGOUT\r\n"); @fclose($this->fp); $this->fp = null; }
    }
}
