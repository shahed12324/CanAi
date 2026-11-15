<?php

declare(strict_types=1);

namespace App\Outreach;

/** IMAP over a real TLS socket.  Only public hosts (checked again here, at connect time), 15 s timeouts, certificates are verified. */
final class StreamImapConnection implements ImapConnection
{
    /** @var resource|null */
    private $fp = null;

    private string $host = '';

    public function open(string $host, int $port, bool $implicitTls): void
    {
        if (! HostGuard::allowed($host, HostGuard::resolve(...))) {
            throw new InboxFailure('inbox_unavailable');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client(($implicitTls ? 'ssl://' : 'tcp://').$host.':'.$port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new InboxFailure('inbox_unavailable');
        }
        stream_set_timeout($fp, 15);
        $this->fp = $fp;
        $this->host = $host;
    }

    public function startTls(): void
    {
        if ($this->fp === null || ! @stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new InboxFailure('inbox_unavailable');
        }
    }

    public function line(): ?string
    {
        if ($this->fp === null) {
            return null;
        }
        $l = fgets($this->fp, 65536);

        return $l === false ? null : rtrim($l, "\r\n");
    }

    public function read(int $n): string
    {
        $out = '';
        while ($this->fp !== null && strlen($out) < $n) {
            $c = fread($this->fp, min(8192, $n - strlen($out)));
            if ($c === false || $c === '') {
                break;
            }
            $out .= $c;
        }

        return $out;
    }

    public function write(string $data): void
    {
        if ($this->fp !== null) {
            @fwrite($this->fp, $data);
        }
    }

    public function close(): void
    {
        if ($this->fp !== null) {
            @fclose($this->fp);
            $this->fp = null;
        }
    }
}
