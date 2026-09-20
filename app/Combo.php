<?php
declare(strict_types=1);

function offer_component_definitions(array|int $offer): array {
    if(is_int($offer)){
        $q=db()->prepare('SELECT * FROM offers WHERE id=?');
        $q->execute([$offer]);
        $offer=$q->fetch() ?: [];
    }
    if(empty($offer['id'])) return [];

    $primaryType=($offer['fulfillment_type']??'days')==='digital' ? 'digital' : 'physical';
    $cq=db()->prepare('SELECT name FROM categories WHERE id=?');$cq->execute([(int)$offer['category_id']]);$primaryCategoryName=(string)($cq->fetchColumn()?:'');
    $components=[[
        'source_component_id'=>null,
        'category_id'=>(int)$offer['category_id'],
        'category_name'=>$primaryCategoryName,
        'title'=>(string)$offer['title'],
        'component_type'=>$primaryType,
        'compensation'=>(float)$offer['compensation'],
        'duration_days'=>$offer['duration_days']!==null?(int)$offer['duration_days']:null,
        'required'=>1,
        'sort_order'=>0,
        'is_primary'=>true,
    ]];

    $q=db()->prepare("SELECT oc.*,c.name category_name FROM offer_components oc JOIN categories c ON c.id=oc.category_id WHERE oc.offer_id=? AND oc.active=1 ORDER BY oc.sort_order,oc.id");
    $q->execute([(int)$offer['id']]);
    foreach($q->fetchAll() as $row){
        $components[]=[
            'source_component_id'=>(int)$row['id'],
            'category_id'=>(int)$row['category_id'],
            'category_name'=>(string)$row['category_name'],
            'title'=>(string)$row['title'],
            'component_type'=>(string)$row['component_type'],
            'compensation'=>(float)$row['compensation'],
            'duration_days'=>$row['duration_days']!==null?(int)$row['duration_days']:null,
            'required'=>(int)$row['required'],
            'sort_order'=>(int)$row['sort_order'],
            'is_primary'=>false,
        ];
    }
    return $components;
}

function offer_is_pure_digital(array|int $offer): bool {
    $components=offer_component_definitions($offer);
    if(!$components) return false;
    foreach($components as $component){
        if(($component['component_type']??'physical')!=='digital') return false;
    }
    return true;
}

function offer_component_extra_total(array|int $offer): float {
    $total=0.0;
    foreach(offer_component_definitions($offer) as $component){
        if(empty($component['is_primary'])) $total+=(float)$component['compensation'];
    }
    return round($total,2);
}

function offer_blocked_category_ids(array|int $offer): array {
    $ids=[];
    foreach(offer_component_definitions($offer) as $component) $ids[]=(int)$component['category_id'];
    return array_values(array_unique(array_filter($ids)));
}

function seller_has_category_conflict(int $sellerId, array $categoryIds): bool {
    $categoryIds=array_values(array_unique(array_map('intval',$categoryIds)));
    if(!$categoryIds) return false;

    $ph=implode(',',array_fill(0,count($categoryIds),'?'));
    $active=['precheck','running','shipping','review','payout'];
    $aph=implode(',',array_fill(0,count($active),'?'));

    $sql="SELECT COUNT(*) FROM orders o
          JOIN offers f ON f.id=o.offer_id
          LEFT JOIN order_components oc ON oc.order_id=o.id
          WHERE o.seller_id=?
            AND o.status IN ($aph)
            AND (f.category_id IN ($ph) OR oc.category_id IN ($ph))";
    $args=array_merge([$sellerId],$active,$categoryIds,$categoryIds);
    $q=db()->prepare($sql);
    $q->execute($args);
    return (int)$q->fetchColumn()>0;
}

function snapshot_order_components(int $orderId, array|int $offer): void {
    $defs=offer_component_definitions($offer);
    if(!$defs) return;

    $ins=db()->prepare("INSERT INTO order_components(order_id,source_component_id,category_id,title_snapshot,component_type,compensation_snapshot,duration_days,required,sort_order,status)
                       VALUES(?,?,?,?,?,?,?,?,?,'preparation')");
    foreach($defs as $component){
        $ins->execute([
            $orderId,
            $component['source_component_id'],
            $component['category_id'],
            $component['title'],
            $component['component_type'],
            $component['compensation'],
            $component['duration_days'],
            $component['required'],
            $component['sort_order'],
        ]);
    }
}

function order_components(int $orderId): array {
    $q=db()->prepare("SELECT oc.*,c.name category_name FROM order_components oc JOIN categories c ON c.id=oc.category_id WHERE oc.order_id=? ORDER BY oc.sort_order,oc.id");
    $q->execute([$orderId]);
    return $q->fetchAll();
}

function order_required_components_complete(int $orderId): bool {
    $q=db()->prepare("SELECT COUNT(*) FROM order_components WHERE order_id=? AND required=1 AND status<>'completed'");
    $q->execute([$orderId]);
    return (int)$q->fetchColumn()===0;
}


function order_precheck_component_progress(int $orderId, ?int $runId=null): array {
    $runId=$runId ?? current_run_id($orderId);
    $components=order_components($orderId);
    $baseRules=offer_evidence_rules($orderId);
    $rows=[];$requiredTotal=0;$acceptedTotal=0;$pendingTotal=0;

    foreach($components as $component){
        if(($component['component_type']??'physical')!=='physical') continue;
        $isPrimary=empty($component['source_component_id']);
        $required=$isPrimary
            ? max(1,(int)$baseRules['precheck_required_count'])
            : ((int)$component['required']===1 ? 1 : 0);

        $sql="SELECT
                SUM(status='accepted') accepted_count,
                SUM(status='submitted') pending_count,
                SUM(status='rejected') rejected_count
              FROM evidences
              WHERE order_id=? AND order_run_id<=>? AND evidence_type='precheck'
                AND ";
        $args=[$orderId,$runId];
        if($isPrimary){
            $sql.="(order_component_id=? OR order_component_id IS NULL)";
            $args[]=$component['id'];
        }else{
            $sql.="order_component_id=?";
            $args[]=$component['id'];
        }
        $q=db()->prepare($sql);$q->execute($args);$stats=$q->fetch()?:[];

        $accepted=(int)($stats['accepted_count']??0);
        $pending=(int)($stats['pending_count']??0);
        $rejected=(int)($stats['rejected_count']??0);
        $requiredTotal+=$required;
        $acceptedTotal+=min($required,$accepted);
        $pendingTotal+=$pending;

        $rows[(int)$component['id']]=[
            'component'=>$component,
            'required'=>$required,
            'accepted'=>$accepted,
            'pending'=>$pending,
            'rejected'=>$rejected,
            'complete'=>$required===0 || $accepted >= $required,
        ];
    }

    return [
        'components'=>$rows,
        'required_total'=>$requiredTotal,
        'accepted_total'=>$acceptedTotal,
        'pending_total'=>$pendingTotal,
        'complete'=>$requiredTotal===0 || ($acceptedTotal >= $requiredTotal && $pendingTotal===0),
    ];
}
