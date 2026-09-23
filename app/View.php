<?php
declare(strict_types=1);

$currentPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$role=$user['role']??null;
$icon=static function(string $name): string {
    $paths=[
        'home'=>'<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10.5V20h14v-9.5"/><path d="M9 20v-6h6v6"/>',
        'check'=>'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'offers'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h6"/>',
        'orders'=>'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4.5V3h6v1.5M9 9h6M9 13h6M9 17h4"/>',
        'scent'=>'<path d="M12 3c2.5 3 4 5.2 4 7.3A4 4 0 0 1 8 10.3C8 8.2 9.5 6 12 3z"/><path d="M6 16c1.5-1.2 3.5-1.8 6-1.8s4.5.6 6 1.8M8 20h8"/>',
        'wallet'=>'<path d="M3 7h15a3 3 0 0 1 3 3v8H5a2 2 0 0 1-2-2z"/><path d="M3 7V5a2 2 0 0 1 2-2h12v4"/><path d="M16 12h5"/><circle cx="17" cy="12" r=".6" fill="currentColor" stroke="none"/>',
        'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'shipping'=>'<path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'logout'=>'<path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
        'more'=>'<circle cx="5" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.4" fill="currentColor" stroke="none"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7h.01"/>',
        'close'=>'<path d="m6 6 12 12M18 6 6 18"/>',
    ];
    return '<svg class="app-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'.($paths[$name]??$paths['more']).'</svg>';
};
$isActive=static function(string $href,bool $exact=false) use($currentPath): bool {
    if($exact)return $currentPath===$href;
    if($currentPath===$href||str_starts_with($currentPath,rtrim($href,'/').'/'))return true;
    $aliases=[
        '/admin/offers'=>['/admin/offer/'],
        '/admin/orders'=>['/admin/order/','/admin/evidence/'],
        '/admin/sellers'=>['/admin/seller/'],
        '/admin/wallets'=>['/admin/payout/'],
        '/seller/offers'=>['/seller/offer/'],
        '/seller/orders'=>['/seller/order/'],
        '/seller/wallet'=>['/seller/payout/'],
    ];
    foreach($aliases[$href]??[] as $prefix)if(str_starts_with($currentPath,$prefix))return true;
    return false;
};

$navItems=[];
$bottomItems=[];
if($user){
    if($role==='admin'){
        $navItems=[
            ['href'=>'/admin','label'=>'Heute','icon'=>'home','exact'=>true,'group'=>'Arbeit'],
            ['href'=>'/admin/reviews','label'=>'Prüfcenter','icon'=>'check','group'=>'Arbeit'],
            ['href'=>'/admin/offers','label'=>'Angebote','icon'=>'offers','group'=>'Verwaltung'],
            ['href'=>'/admin/orders','label'=>'Aufträge','icon'=>'orders','group'=>'Verwaltung'],
            ['href'=>'/admin/scent-requests','label'=>'Duftproben','icon'=>'scent','group'=>'Verwaltung'],
            ['href'=>'/admin/wallets','label'=>'Wallets','icon'=>'wallet','group'=>'Verwaltung'],
            ['href'=>'/admin/sellers','label'=>'Verkäuferinnen','icon'=>'users','group'=>'Verwaltung'],
            ['href'=>'/admin/notifications','label'=>'Mitteilungen','icon'=>'bell','badge'=>$notificationUnread,'group'=>'System'],
            ['href'=>'/admin/settings','label'=>'Versandadresse','icon'=>'shipping','group'=>'System'],
            ['href'=>'/admin/cron','label'=>'Reminder Engine','icon'=>'clock','group'=>'System'],
        ];
        $bottomItems=[
            ['/admin','Heute','home',true],
            ['/admin/reviews','Prüfen','check',false],
            ['/admin/orders','Aufträge','orders',false],
            ['/admin/wallets','Wallets','wallet',false],
        ];
    }else{
        $navItems=[
            ['href'=>'/seller','label'=>'Übersicht','icon'=>'home','exact'=>true,'group'=>'Portal'],
            ['href'=>'/seller/orders','label'=>'Aufträge','icon'=>'orders','group'=>'Portal'],
            ['href'=>'/seller/offers','label'=>'Angebote','icon'=>'offers','group'=>'Portal'],
            ['href'=>'/seller/scent-requests','label'=>'Duftproben','icon'=>'scent','group'=>'Portal'],
            ['href'=>'/seller/wallet','label'=>'Wallet','icon'=>'wallet','group'=>'Portal'],
            ['href'=>'/seller/notifications','label'=>'Mitteilungen','icon'=>'bell','badge'=>$notificationUnread,'group'=>'Portal'],
        ];
        $bottomItems=[
            ['/seller','Übersicht','home',true],
            ['/seller/orders','Aufträge','orders',false],
            ['/seller/offers','Angebote','offers',false],
            ['/seller/wallet','Wallet','wallet',false],
        ];
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f172a">
<title><?=e($title)?> · <?=e(app_config('app.name','Auftragsportal'))?></title>
<link rel="stylesheet" href="<?=e(url('/assets/app.css'))?>">
</head>
<body class="<?= $user?'app-authenticated role-'.e((string)$role):'public-layout' ?>">

<?php if(!$user):?>
<header class="topbar public-topbar"><a class="brand" href="<?=e(url('/'))?>"><span class="brand-mark">A</span><span><?=e(app_config('app.name','Auftragsportal'))?></span></a></header>
<?php else:?>
<div class="app-frame">
    <aside class="app-sidebar" aria-label="Hauptnavigation">
        <a class="sidebar-brand" href="<?=e(url($role==='admin'?'/admin':'/seller'))?>">
            <span class="brand-mark">A</span>
            <span><strong><?=e(app_config('app.name','Auftragsportal'))?></strong><small><?=e($role==='admin'?'Verwaltung':'Verkäuferinnenportal')?></small></span>
        </a>
        <nav class="sidebar-nav">
            <?php $lastGroup=null;foreach($navItems as $item):?>
                <?php if($item['group']!==$lastGroup):$lastGroup=$item['group'];?><span class="sidebar-group"><?=e($lastGroup)?></span><?php endif;?>
                <a class="sidebar-link <?=$isActive($item['href'],$item['exact']??false)?'active':''?>" href="<?=e(url($item['href']))?>">
                    <?=$icon($item['icon'])?><span><?=e($item['label'])?></span>
                    <?php if(!empty($item['badge'])):?><b class="nav-badge"><?=e($item['badge'])?></b><?php endif;?>
                </a>
            <?php endforeach;?>
        </nav>
        <div class="sidebar-footer">
            <a class="sidebar-link" href="<?=e(url('/logout'))?>"><?=$icon('logout')?><span>Abmelden</span></a>
            <small>Private Vermittlungsplattform</small>
        </div>
    </aside>

    <div class="app-workspace">
        <header class="mobile-app-header">
            <a class="mobile-brand" href="<?=e(url($role==='admin'?'/admin':'/seller'))?>"><span class="brand-mark">A</span><strong><?=e(app_config('app.name','Auftragsportal'))?></strong></a>
            <a class="mobile-notification-link" href="<?=e(url($role==='admin'?'/admin/notifications':'/seller/notifications'))?>" aria-label="Mitteilungen">
                <?=$icon('bell')?><?php if($notificationUnread):?><b><?=e($notificationUnread)?></b><?php endif;?>
            </a>
        </header>
<?php endif;?>

<?php if($impersonator):?><div class="impersonation-bar"><span>Adminansicht · Verkäuferinnenkonto geöffnet</span><form method="post" action="<?=e(url('/impersonation/stop'))?>"><?=csrf_field()?><button class="btn">Zurück zur Verwaltung</button></form></div><?php endif;?>
<?php foreach($flashes as [$type,$message]):?><div class="flash <?=e($type)?>"><?=e($message)?></div><?php endforeach;?>

<main class="<?= $user?'app-main':'' ?>"><?= $content ?></main>
<footer>Private Vermittlungsplattform · Geschützte Nachweise · <?=date('Y')?></footer>

<?php if($user):?>
        <nav class="mobile-bottom-nav" aria-label="Mobile Hauptnavigation">
            <?php foreach($bottomItems as [$href,$label,$iconName,$exact]):?>
                <a class="<?=$isActive($href,$exact)?'active':''?>" href="<?=e(url($href))?>"><?=$icon($iconName)?><span><?=e($label)?></span></a>
            <?php endforeach;?>
            <button type="button" data-mobile-menu-open aria-controls="mobile-more-menu" aria-expanded="false"><?=$icon('more')?><span>Mehr</span></button>
        </nav>

        <div class="mobile-menu-backdrop" data-mobile-menu-close hidden></div>
        <aside class="mobile-more-menu" id="mobile-more-menu" aria-hidden="true">
            <div class="mobile-more-head"><div><strong>Navigation</strong><span><?=e($role==='admin'?'Verwaltung':'Verkäuferinnenportal')?></span></div><button type="button" data-mobile-menu-close aria-label="Menü schließen"><?=$icon('close')?></button></div>
            <nav>
                <?php foreach($navItems as $item):?>
                    <a class="<?=$isActive($item['href'],$item['exact']??false)?'active':''?>" href="<?=e(url($item['href']))?>"><?=$icon($item['icon'])?><span><?=e($item['label'])?></span><?php if(!empty($item['badge'])):?><b class="nav-badge"><?=e($item['badge'])?></b><?php endif;?></a>
                <?php endforeach;?>
                <a href="<?=e(url('/logout'))?>"><?=$icon('logout')?><span>Abmelden</span></a>
            </nav>
        </aside>
    </div>
</div>
<?php endif;?>

<script src="<?=e(url('/assets/app.js'))?>"></script>
</body></html>
