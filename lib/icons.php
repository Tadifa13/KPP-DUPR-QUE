<?php
/**
 * Icon set — inline SVG, hand-authored, no dependency.
 *
 * Icon packages all ship over npm or a CDN, and this app must keep working with
 * no internet, under a `default-src 'self'` policy. So the set is drawn here:
 * one 24x24 grid, 1.75 stroke, round caps and joins, `currentColor` throughout,
 * so every icon inherits colour and sits on the text baseline.
 *
 * Inline rather than a sprite sheet: these pages are server-rendered and small,
 * and inlining avoids a second request and any flash of missing icon.
 *
 *   echo icon('trophy');
 *   echo icon('plus', 16, 'is-muted');
 */

function icon_paths(): array
{
    return [
        // ---- navigation -------------------------------------------------
        'court'     => '<rect x="2.5" y="4.5" width="19" height="15" rx="1.5"/><path d="M12 4.5v15M2.5 12h19"/>',
        'users'     => '<circle cx="9" cy="8" r="3.25"/><path d="M2.75 19.5a6.25 6.25 0 0 1 12.5 0"/><path d="M16.5 5.2a3.25 3.25 0 0 1 0 5.6"/><path d="M18 14.4a6.25 6.25 0 0 1 3.25 5.1"/>',
        'trophy'    => '<path d="M7 4.5h10v4a5 5 0 0 1-10 0z"/><path d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11"/><path d="M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11"/><path d="M12 13.5v3M8.5 19.5h7M10 16.5h4"/>',
        'qr'        => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20.5 14v3M14 20.5h3M20.5 20.5h.01"/>',
        'clipboard' => '<path d="M9 4.5H7.5A1.5 1.5 0 0 0 6 6v13.5A1.5 1.5 0 0 0 7.5 21h9a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H15"/><rect x="9" y="3" width="6" height="3.2" rx="1"/><path d="M9 11h6M9 15h4"/>',
        'clock'     => '<circle cx="12" cy="12" r="8.75"/><path d="M12 7v5.2l3.3 2"/>',

        // ---- actions ----------------------------------------------------
        'plus'      => '<path d="M12 5.5v13M5.5 12h13"/>',
        'minus'     => '<path d="M5.5 12h13"/>',
        'check'     => '<path d="M4.5 12.6 9.5 17.5 19.5 6.8"/>',
        'x'         => '<path d="M6 6l12 12M18 6 6 18"/>',
        'arrow-up'  => '<path d="M12 19.5v-15M5.75 10.75 12 4.5l6.25 6.25"/>',
        'chevron'   => '<path d="M9 5.5 15.5 12 9 18.5"/>',
        'play'      => '<path d="M7.5 5.2v13.6a.7.7 0 0 0 1.07.6l10.7-6.8a.7.7 0 0 0 0-1.2L8.57 4.6a.7.7 0 0 0-1.07.6z"/>',
        'pause'     => '<rect x="7" y="5" width="3.6" height="14" rx="1.2"/><rect x="13.4" y="5" width="3.6" height="14" rx="1.2"/>',
        'rotate'    => '<path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20.5 4v4.2h-4.2"/>',
        'share'     => '<path d="M12 15.5V4M8.2 7.4 12 3.6l3.8 3.8"/><path d="M5.5 13v6a1.5 1.5 0 0 0 1.5 1.5h10a1.5 1.5 0 0 0 1.5-1.5v-6"/>',
        'download'  => '<path d="M12 4v11.5M8.2 12l3.8 3.8 3.8-3.8"/><path d="M5.5 19.5h13"/>',
        'printer'   => '<path d="M7 9V4.5h10V9"/><rect x="3.5" y="9" width="17" height="7" rx="1.5"/><path d="M7 14h10v5.5H7z"/>',
        'user-plus' => '<circle cx="9.5" cy="8" r="3.25"/><path d="M3.25 19.5a6.25 6.25 0 0 1 12.5 0"/><path d="M18.5 8v5M16 10.5h5"/>',

        // ---- status ------------------------------------------------------
        'eye'       => '<path d="M2.5 12s3.5-6.25 9.5-6.25S21.5 12 21.5 12 18 18.25 12 18.25 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.75"/>',
        'lock'      => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.25 10.5V7.75a3.75 3.75 0 0 1 7.5 0v2.75"/>',
        'wifi-off'  => '<path d="M3 3l18 18"/><path d="M6.2 10.2a11 11 0 0 1 3.1-1.9M14.9 8.4a11 11 0 0 1 3 1.8"/><path d="M9.2 13.6a6.4 6.4 0 0 1 5.7 0"/><path d="M12 17.8h.01"/>',
        'signal'    => '<path d="M4.5 11.2a10.5 10.5 0 0 1 15 0"/><path d="M8 14.4a6 6 0 0 1 8 0"/><path d="M12 17.7h.01"/>',
        'flame'     => '<path d="M12 3.2s5.4 4 5.4 9.1a5.4 5.4 0 0 1-10.8 0c0-2 1-3.5 1-3.5s.6 1.6 1.8 1.6c1.6 0 1.1-4.6 2.6-7.2z"/>',
        'target'    => '<circle cx="12" cy="12" r="8.75"/><circle cx="12" cy="12" r="4.75"/><circle cx="12" cy="12" r="1"/>',
        'shield'    => '<path d="M12 3.2 4.8 6v5.4c0 4.3 3 8 7.2 9.4 4.2-1.4 7.2-5.1 7.2-9.4V6z"/><path d="M9.2 12.2 11.3 14.3 15 10.6"/>',
        'sparkle'   => '<path d="M12 3.5 13.7 9l5.5 1.7-5.5 1.7L12 18l-1.7-5.6L4.8 10.7 10.3 9z"/><path d="M18.5 15.5l.7 2.1 2.1.7-2.1.7-.7 2.1-.7-2.1-2.1-.7 2.1-.7z"/>',
        'logout'    => '<path d="M14.5 8V5.5A1.5 1.5 0 0 0 13 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H13a1.5 1.5 0 0 0 1.5-1.5V16"/><path d="M9.5 12h11M17.2 8.7 20.5 12l-3.3 3.3"/>',
    ];
}

/**
 * Render an icon.
 *
 * Decorative by default (aria-hidden) — the surrounding control carries the
 * label. Pass $label to make it a standalone labelled graphic.
 */
function icon(string $name, int $size = 20, string $class = '', string $label = ''): string
{
    $paths = icon_paths();
    if (!isset($paths[$name])) {
        return '';
    }

    $a11y = $label !== ''
        ? 'role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"'
        : 'aria-hidden="true" focusable="false"';

    return '<svg class="ico ' . htmlspecialchars($class, ENT_QUOTES) . '" '
        . 'width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" '
        . 'fill="none" stroke="currentColor" stroke-width="1.75" '
        . 'stroke-linecap="round" stroke-linejoin="round" ' . $a11y . '>'
        . $paths[$name]
        . '</svg>';
}
