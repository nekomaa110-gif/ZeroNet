<?php

namespace App\Services;

use Exception;

class MikrotikClient
{
    private $socket;

    private int $timeout;

    private bool $putus = false;

    private float $terakhir;

    public function __construct(
        string $host,
        string $user,
        string $pass,
        int    $port    = 8728,
        int    $timeout = 5
    ) {
        $this->timeout  = max(1, $timeout);
        $this->terakhir = microtime(true);
        $this->socket  = @fsockopen($host, $port, $errno, $errstr, $this->timeout);

        if (! is_resource($this->socket)) {
            throw new Exception("Tidak dapat terhubung ke {$host}:{$port} ({$errstr})");
        }

        stream_set_timeout($this->socket, $this->timeout);

        try {
            $this->login($user, $pass);
        } catch (Exception $e) {
            $this->tutup();
            throw $e;
        }
    }

    public function aturTimeout(int $detik): void
    {
        $detik = max(1, $detik);

        if ($detik !== $this->timeout && is_resource($this->socket)) {
            $this->timeout = $detik;
            stream_set_timeout($this->socket, $detik);
        }
    }

    public function idle(): float
    {
        return microtime(true) - $this->terakhir;
    }

    public function masihTersambung(): bool
    {
        return ! $this->putus && is_resource($this->socket) && ! feof($this->socket);
    }

    private function gagalKoneksi(string $pesan): never
    {
        $this->putus = true;

        throw new Exception($pesan);
    }

    private function encodeLen(int $n): string
    {
        if ($n < 0x80)       return chr($n);
        if ($n < 0x4000)     return pack('n', $n | 0x8000);
        if ($n < 0x200000)   return substr(pack('N', $n | 0xC00000), 1);
        if ($n < 0x10000000) return pack('N', $n | 0xE0000000);
        return chr(0xF0) . pack('N', $n);
    }

    private function writeSentence(array $words): void
    {
        $buf = '';
        foreach ($words as $w) {
            $buf .= $this->encodeLen(strlen($w)) . $w;
        }
        $buf .= chr(0);

        $this->terakhir = microtime(true);
        $batas          = $this->terakhir + $this->timeout;
        while ($buf !== '') {
            $n = @fwrite($this->socket, $buf);
            if ($n === false || ($n === 0 && microtime(true) > $batas)) {
                $this->gagalKoneksi('Koneksi ke router terputus saat mengirim perintah.');
            }
            $buf = (string) substr($buf, $n);
        }
    }

    private function readBytes(int $n): string
    {
        $buf   = '';
        $batas = microtime(true) + $this->timeout;

        while (strlen($buf) < $n) {
            $chunk = @fread($this->socket, $n - strlen($buf));

            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($this->socket)['timed_out'] ?? false) {
                    $this->gagalKoneksi('Router tidak menjawab (timeout ' . $this->timeout . ' dtk).');
                }
                if (feof($this->socket)) {
                    $this->gagalKoneksi('Koneksi ke router terputus.');
                }
                if (microtime(true) > $batas) {
                    $this->gagalKoneksi('Router tidak menjawab (timeout ' . $this->timeout . ' dtk).');
                }
                continue;
            }

            $buf .= $chunk;
        }

        $this->terakhir = microtime(true);

        return $buf;
    }

    private function readLen(): int
    {
        $b = ord($this->readBytes(1));
        if (($b & 0x80) === 0) return $b;
        if (($b & 0xC0) === 0x80) return (($b & 0x3F) << 8) | ord($this->readBytes(1));
        if (($b & 0xE0) === 0xC0) {
            $r = unpack('n', $this->readBytes(2))[1];
            return (($b & 0x1F) << 16) | $r;
        }
        if (($b & 0xF0) === 0xE0) {
            $r = unpack('N', chr(0) . $this->readBytes(3))[1];
            return (($b & 0x0F) << 24) | $r;
        }
        return unpack('N', $this->readBytes(4))[1];
    }

    private function readWord(): string
    {
        $len = $this->readLen();

        return $len === 0 ? '' : $this->readBytes($len);
    }

    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $w = $this->readWord();
            if ($w === '') break;
            $words[] = $w;
        }
        return $words;
    }

    private static function attributes(array $sentence): array
    {
        $row = [];
        foreach (array_slice($sentence, 1) as $word) {
            if (str_starts_with($word, '=')) {
                [$k, $v] = array_pad(explode('=', substr($word, 1), 2), 2, '');
                $row[$k] = $v;
            }
        }
        return $row;
    }

    private function login(string $user, string $pass): void
    {
        $this->writeSentence(['/login', '=name=' . $user, '=password=' . $pass]);
        $sentence = $this->readSentence();

        if (empty($sentence)) {
            throw new Exception('Tidak ada respons dari router saat login.');
        }

        if ($sentence[0] === '!done') {
            $ret = null;
            foreach ($sentence as $word) {
                if (str_starts_with($word, '=ret=')) {
                    $ret = substr($word, 5);
                }
            }

            if ($ret !== null) {
                $response = '00' . md5(chr(0) . $pass . pack('H*', $ret));
                $this->writeSentence(['/login', '=name=' . $user, '=response=' . $response]);
                $s2 = $this->readSentence();
                if (($s2[0] ?? '') !== '!done') {
                    throw new Exception('Login gagal (challenge-response).');
                }
            }
            return;
        }

        if ($sentence[0] === '!trap') {
            $msg = '';
            foreach ($sentence as $word) {
                if (str_starts_with($word, '=message=')) $msg = substr($word, 9);
            }
            throw new Exception('Login gagal: ' . ($msg ?: 'salah username/password'));
        }

        throw new Exception('Respons login tidak terduga: ' . ($sentence[0] ?? 'kosong'));
    }

    public function query(string $cmd, array $params = []): array
    {
        return $this->execute($cmd, $params)[0];
    }

    public function count(string $cmd, array $params = []): int
    {
        return (int) ($this->execute($cmd, ['=count-only' => ''] + $params)[1] ?? 0);
    }

    private function execute(string $cmd, array $params): array
    {
        $words = [$cmd];
        foreach ($params as $key => $value) {
            $words[] = $value !== null ? "{$key}={$value}" : $key;
        }
        $this->writeSentence($words);

        $result = [];
        $error  = null;

        while (true) {
            $sentence = $this->readSentence();
            if (empty($sentence)) continue;

            $type = $sentence[0];

            if ($type === '!re') {
                $result[] = self::attributes($sentence);
            } elseif ($type === '!trap') {
                $error ??= self::attributes($sentence)['message'] ?? '';
            } elseif ($type === '!fatal') {
                $this->gagalKoneksi('RouterOS error: ' . ($sentence[1] ?? 'fatal'));
            } elseif ($type === '!done') {
                if ($error !== null) {
                    throw new Exception("RouterOS error: {$error}");
                }

                return [$result, self::attributes($sentence)['ret'] ?? null];
            }
        }
    }

    public function queryMany(array $commands, int $chunk = 25): array
    {
        $results = [];

        foreach (array_chunk($commands, max(1, $chunk), true) as $group) {
            foreach ($group as $i => $c) {
                $words = [$c['cmd']];
                foreach (($c['params'] ?? []) as $key => $value) {
                    $words[] = $value !== null ? "{$key}={$value}" : $key;
                }
                $words[] = '.tag=' . $i;
                $this->writeSentence($words);
            }

            foreach (array_keys($group) as $i) {
                $results[$i] = ['ok' => true, 'error' => null, 'rows' => [], 'ret' => null];
            }

            $pending = count($group);

            while ($pending > 0) {
                $sentence = $this->readSentence();

                if (empty($sentence)) {
                    continue;
                }

                $type = $sentence[0];
                $tag  = null;
                $msg  = '';
                $row  = [];

                foreach (array_slice($sentence, 1) as $word) {
                    if (str_starts_with($word, '.tag=')) {
                        $tag = (int) substr($word, 5);
                    } elseif (str_starts_with($word, '=message=')) {
                        $msg = substr($word, 9);
                    } elseif (str_starts_with($word, '=')) {
                        [$k, $v] = array_pad(explode('=', substr($word, 1), 2), 2, '');
                        $row[$k] = $v;
                    }
                }

                if ($type === '!fatal') {
                    $this->gagalKoneksi('Koneksi ke router terputus: ' . ($msg ?: ($sentence[1] ?? 'fatal')));
                }

                if ($tag === null || ! isset($results[$tag])) {
                    continue;
                }

                if ($type === '!re') {
                    $results[$tag]['rows'][] = $row;
                } elseif ($type === '!trap') {
                    $results[$tag]['ok']    = false;
                    $results[$tag]['error'] = $msg ?: 'ditolak router';
                } elseif ($type === '!done') {
                    $results[$tag]['ret'] = $row['ret'] ?? null;
                    $pending--;
                }
            }
        }

        ksort($results);

        return array_values($results);
    }

    public function tutup(): void
    {
        $this->putus = true;

        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    public function __destruct()
    {
        $this->tutup();
    }
}
