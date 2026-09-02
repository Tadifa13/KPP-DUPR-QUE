<?php
/**
 * Serves the local TLS certificate (public half only) so a device on the LAN
 * can download and install it. Never serves the private key.
 */
require __DIR__ . '/config/config.php';
$cert = DATA_DIR . '/tls/cert.pem';
if (!is_readable($cert)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("No local certificate has been issued. Start the app with ./serve.sh --https\n");
}
header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="kamrynne-que-local.crt"');
header('X-Content-Type-Options: nosniff');
readfile($cert);
