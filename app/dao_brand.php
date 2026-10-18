<?php
declare(strict_types=1);
/** Собственные значки «Дао». Ресурсы Орды остаются только основой интерфейса. */
function dao_icon(string $name): string {
    $paths=[
        'eye'=>'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12"/><circle cx="12" cy="12" r="3"/>',
        'heart'=>'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>',
        'mail'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'stories'=>'<rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'draft'=>'<path d="m15 4 5 5M4 20l5-1L20 8a2 2 0 0 0-5-5L4 14zM4 20h16"/>',
        'published'=>'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'telegram'=>'<path d="m3 11 18-7-4 17-6-6-4 3 1-6 9-5-6 8z"/>',
        'settings'=>'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
        'backup'=>'<path d="M4 10a8 8 0 1 1 1 8M4 4v6h6M12 8v5l3 2"/>',
        'site'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18"/>',
    ];
    if ($name==='logo') return '<span class="icon svg-icon dao-mark" aria-hidden="true"><img src="/assets/brand/dao-admin.svg?v=2" width="36" height="36" alt=""></span>';
    return '<span class="icon svg-icon dao-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['stories']).'</svg></span>';
}
function dao_brand_head(bool $admin=false): string {
    $prefix='/assets/brand/'.($admin?'dao-admin':'dialogue');
    return '<meta name="theme-color" content="'.($admin?'#6750a4':'#b97934').'"><link rel="icon" type="image/svg+xml" href="'.$prefix.'.svg?v=2"><link rel="icon" type="image/png" sizes="32x32" href="'.$prefix.'-32.png?v=2"><link rel="shortcut icon" href="'.$prefix.'.ico?v=2"><link rel="apple-touch-icon" sizes="180x180" href="'.$prefix.'-180.png?v=2"><link rel="manifest" href="'.($admin?'/admin.webmanifest':'/site.webmanifest').'?v=2">';
}
