<?php
declare(strict_types=1);

/**
 * Seller administration list.
 * The detailed seller record and lifecycle actions live in OperationsRoutes.php.
 */

if ($path==='/admin/verkaeuferinnen' && $method==='GET') {
    require_admin();

    $status=(string)($_GET['status']??'all');
    if(!in_array($status,['all','active','deleted'],true)) $status='all';

    $sql="SELECT s.*,COUNT(o.id) orders_count
          FROM sellers s
          LEFT JOIN orders o ON o.seller_id=s.id";
    if($status==='active') $sql.=" WHERE s.deleted_at IS NULL";
    elseif($status==='deleted') $sql.=" WHERE s.deleted_at IS NOT NULL";
    $sql.=" GROUP BY s.id ORDER BY s.created_at DESC";

    $rows=db()->query($sql)->fetchAll();

    ob_start();?>
    <div class="dashboard-head">
      <div>
        <div class="eyebrow">Administration</div>
        <h1>Verkäuferinnen</h1>
        <p class="meta">Aktive und anonymisierte Konten mit vollständiger Verkäuferinnenakte.</p>
      </div>
      <div class="actions">
        <a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen?status=all'))?>">Alle</a>
        <a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen?status=active'))?>">Aktiv</a>
        <a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen?status=deleted'))?>">Anonymisiert</a>
      </div>
    </div>

    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>E-Mail</th><th>Status</th><th>Verifiziert</th><th>Aufträge</th><th></th></tr></thead>
      <tbody>
      <?php foreach($rows as $r):?>
        <tr>
          <td><?=e($r['first_name'].' '.$r['last_name'])?></td>
          <td><?=e($r['email'])?></td>
          <td><span class="badge <?=$r['deleted_at']?'bad':''?>"><?=e($r['deleted_at']?'Anonymisiert':'Aktiv')?></span></td>
          <td><?=$r['email_verified_at']?'Ja':'Nein'?></td>
          <td><?=e($r['orders_count'])?></td>
          <td><a href="<?=e(url('/admin/verkaeuferin/'.$r['id']))?>">Akte</a></td>
        </tr>
      <?php endforeach;?>
      <?php if(!$rows):?><tr><td colspan="6">Keine Konten in dieser Auswahl.</td></tr><?php endif;?>
      </tbody>
    </table></div>
    <?php render('Verkäuferinnen',ob_get_clean());exit;
}
