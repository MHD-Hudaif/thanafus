<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

function e($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$event = $musabaqa_pdo->query("SELECT * FROM musabaqa_events ORDER BY (status='active') DESC,id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$programs = [];
if($event){
    $stmt=$musabaqa_pdo->prepare("
        SELECT id,title,location,start_time,status
        FROM musabaqa_programs
        WHERE event_id=?
        ORDER BY COALESCE(start_time,created_at),id
    ");
    $stmt->execute([$event['id']]);
    $programs=$stmt->fetchAll(PDO::FETCH_ASSOC);
}

$rankMap=[];
try{
    $r=$musabaqa_pdo->query("
        SELECT pe.program_id,pe.final_rank,t.team_name,t.short_name,t.team_color
        FROM musabaqa_program_entries pe
        INNER JOIN musabaqa_teams t ON t.id=pe.team_id
        WHERE pe.final_rank IN (1,2,3)
    ");
    foreach($r->fetchAll(PDO::FETCH_ASSOC) as $row){
        $rankMap[(int)$row['program_id']][(int)$row['final_rank']]=$row;
    }
}catch(Throwable $e){}

$pages=[]; $current=[]; $count=0; $prev=null;
foreach($programs as $p){
    $date=date('Y-m-d',strtotime($p['start_time']));
    if($count===0 || $date!==$prev){
        $current[]=['type'=>'date','label'=>date('l, M j, Y',strtotime($p['start_time']))];
        $prev=$date;
    }
    $current[]=['type'=>'program','data'=>$p];
    $count++;
    if($count>=9){ $pages[]=$current; $current=[]; $count=0; $prev=null; }
}
if($current) $pages[]=$current;
?>
<!DOCTYPE html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="schedules.css">
<title>TV Schedule</title>
</head><body>
<div class="schedule-app" id="scheduleApp">
<header class="schedule-header">
<div><h1 class="schedule-title"><?=e($event['title']??'Schedule')?></h1></div>
<div class="schedule-clock" id="scheduleClock"></div>
</header>
<div class="schedule-table-shell">
<table class="schedule-table">
<thead><tr><th>TIME</th><th>PROGRAM</th><th>VENUE</th><th>RESULTS</th></tr></thead></table>
<div id="pageContainer">
<?php foreach($pages as $pi=>$page): ?>
<table class="schedule-table schedule-page<?= $pi===0?' active':'' ?>">
<tbody>
<?php foreach($page as $row): ?>
<?php if($row['type']==='date'): ?>
<tr class="schedule-date-row"><td colspan="4"><?=e($row['label'])?></td></tr>
<?php else:
$p=$row['data']; $ranks=$rankMap[$p['id']]??[]; ?>
<tr class="schedule-row" data-start-time="<?=e($p['start_time'])?>">
<td class="schedule-time-cell"><?=date('h:i A',strtotime($p['start_time']))?></td>
<td class="schedule-program-cell"><?=e($p['title'])?></td>
<td class="schedule-venue-cell"><?=e($p['location']?:'—')?></td>
<td>
<?php if($ranks): ?><div class="result-pills">
<?php foreach([1,2,3] as $rk): if(isset($ranks[$rk])): ?>
<span class="rank-pill rank-<?=$rk?>" style="background:<?=$ranks[$rk]['team_color']?>"><?=$rk?><?=($rk==1?'st':($rk==2?'nd':'rd'))?> <?=e($ranks[$rk]['team_name'])?></span>
<?php endif; endforeach; ?>
</div><?php else: ?>—<?php endif; ?>
</td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody></table>
<?php endforeach; ?>
</div></div></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="schedules.js"></script>
</body></html>