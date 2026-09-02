<?php
require __DIR__ . '/config/config.php';
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name'             => APP_NAME . ' — ' . APP_TAGLINE,
    'short_name'       => APP_NAME,
    'description'      => 'Fair pickleball social queue, DUPR calibration and Reclub game log.',
    'start_url'        => './index.php',
    'scope'            => './',
    'display'          => 'standalone',
    'background_color' => '#05100b',
    'theme_color'      => '#05100b',
    'orientation'      => 'any',
    'icons'            => [
        ['src' => 'assets/brand/logo-96.png',  'sizes' => '96x96',   'type' => 'image/png'],
        ['src' => 'assets/brand/logo-180.png', 'sizes' => '180x180', 'type' => 'image/png'],
        ['src' => 'assets/brand/logo-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
