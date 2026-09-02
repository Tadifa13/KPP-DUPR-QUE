<?php
/**
 * Minimal TLS terminator.  Usage: php bin/tls-proxy.php <listen-port> <backend-port> <cert.pem> <key.pem>
 *
 * Why this exists
 * ---------------
 * Browsers only expose service workers, the Cache API and PWA installation on a
 * *secure context*: https://, or localhost. A LAN address like
 * http://192.168.1.50:8080 is NOT a secure context — `navigator.serviceWorker`
 * is not even defined there. So the organizer running the app on their own
 * machine got the full offline PWA, while every phone and tablet on the LAN
 * silently got none of it.
 *
 * PHP's built-in server speaks no TLS, and this project deliberately has no
 * dependencies, so this terminates TLS in front of it using nothing but PHP's
 * own openssl streams.
 *
 * It is a development-grade proxy for a club LAN — one fork per connection, no
 * keep-alive juggling, no HTTP parsing. It is not a public-facing web server.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
if (!extension_loaded('openssl')) {
    fwrite(STDERR, "PHP is built without openssl; cannot serve TLS.\n");
    exit(1);
}

$listenPort  = (int) ($argv[1] ?? 8443);
$backendPort = (int) ($argv[2] ?? 8080);
$certFile    = $argv[3] ?? '';
$keyFile     = $argv[4] ?? '';

foreach ([$certFile, $keyFile] as $f) {
    if (!$f || !is_readable($f)) {
        fwrite(STDERR, "Certificate or key not readable: $f\n");
        exit(1);
    }
}

// Listen in plain TCP and negotiate TLS per connection inside the child, so a
// failed handshake can never stall the accept loop.
$server = @stream_socket_server(
    "tcp://0.0.0.0:$listenPort",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);
if (!$server) {
    fwrite(STDERR, "Cannot listen on port $listenPort: $errstr ($errno)\n");
    exit(1);
}

// Applied per accepted socket with stream_context_set_option(), which takes an
// options array — not a context resource.
$sslOptions = ['ssl' => [
    'local_cert'          => $certFile,
    'local_pk'            => $keyFile,
    'allow_self_signed'   => true,
    'verify_peer'         => false,
    'verify_peer_name'    => false,
    'disable_compression' => true,
    'SNI_enabled'         => true,
    'ciphers'             => 'HIGH:!aNULL:!MD5',
]];

// Reap children as they exit rather than accumulating zombies.
pcntl_signal(SIGCHLD, SIG_IGN);
pcntl_signal(SIGINT, function () use ($server) {
    @fclose($server);
    exit(0);
});

fwrite(STDOUT, "TLS proxy :$listenPort -> 127.0.0.1:$backendPort\n");

while (true) {
    pcntl_signal_dispatch();

    $client = @stream_socket_accept($server, 30);
    if ($client === false) {
        continue;
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        fclose($client);
        continue;
    }
    if ($pid > 0) {
        fclose($client);      // parent keeps listening
        continue;
    }

    // ---- child ----
    fclose($server);
    stream_context_set_option($client, $sslOptions);
    stream_set_blocking($client, true);

    $ok = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    if ($ok !== true) {
        fclose($client);      // handshake refused, e.g. an http:// request
        exit(0);
    }

    $backend = @stream_socket_client(
        "tcp://127.0.0.1:$backendPort",
        $bErrno,
        $bErrstr,
        5
    );
    if (!$backend) {
        fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Type: text/plain\r\n"
            . "Connection: close\r\n\r\nThe application server is not running.\n");
        fclose($client);
        exit(0);
    }

    stream_set_blocking($client, false);
    stream_set_blocking($backend, false);

    // Pump bytes both ways until either side closes.
    $open = true;
    while ($open) {
        $read = [$client, $backend];
        $write = null;
        $except = null;

        if (@stream_select($read, $write, $except, 60) === false) {
            break;
        }
        if (!$read) {
            break;                       // idle timeout
        }

        foreach ($read as $from) {
            $to = ($from === $client) ? $backend : $client;
            $chunk = @fread($from, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($from)) {
                    $open = false;
                    break;
                }
                continue;
            }
            $written = 0;
            $len = strlen($chunk);
            while ($written < $len) {
                $n = @fwrite($to, substr($chunk, $written));
                if ($n === false || $n === 0) {
                    $open = false;
                    break 2;
                }
                $written += $n;
            }
        }
    }

    @fclose($backend);
    @fclose($client);
    exit(0);
}
