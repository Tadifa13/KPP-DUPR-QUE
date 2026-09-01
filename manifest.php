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
    'background_color' => '#071c15',
    'theme_color'      => '#0a2d20',
    'orientation'      => 'any',
    'icons'            => [[
        'src'     => 'assets/favicon.svg',
        'sizes'   => 'any',
        'type'    => 'image/svg+xml',
        'purpose' => 'any maskable',
    ]],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
