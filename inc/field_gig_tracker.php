<?php
declare(strict_types=1);
require_once __DIR__.'/field_gig_tracker_parse.php';
function gig_ready(PDO $pdo):void{
 $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);field_tracker_staging($pdo);
 foreach(['field_vehicles','field_vehicle_events','field_rideshare_shifts','field_rideshare_breaks','field_gig_tracker_imports','field_gig_tracker_claims']as $table){$r=field_tracker_rows($pdo,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);if(count($r)!==1||strtoupper($r[0]['ENGINE'])!=='INNODB')throw new RuntimeException('Run the Gig tracker staging migration. Required InnoDB table missing: '.$table);}
 foreach(['field_gig_tracker_imports'=>['outing_id','shift_id'],'field_gig_tracker_claims'=>['source_id']]as $table=>$cols){$unique=[];foreach(field_tracker_rows($pdo,"SHOW INDEX FROM `{$table}`")as $i)if((int)$i['Non_unique']===0)$unique[$i['Key_name']][]=$i['Column_name'];foreach($cols as $c)if(!in_array([$c],$unique,true))throw new RuntimeException('Required tracker uniqueness constraint missing.');}
}
/** Same 90-day observed-fuel inputs and pure calculator as existing OPS; no automatic schema calls. */
function gig_vehicle(PDO $pdo,int $id,bool $lock=false):array{
 $r=field_tracker_rows($pdo,'SELECT * FROM field_vehicles WHERE vehicle_id=?'.($lock?' FOR UPDATE':''),[$id]);if(count($r)!==1||empty($r[0]['active']))throw new InvalidArgumentException('Choose an active vehicle.');$v=$r[0];
 $fuel=field_tracker_rows($pdo,"SELECT SUM(amount) AS fuel_amount,SUM(gallons) AS fuel_gallons FROM field_vehicle_events WHERE vehicle_id=? AND event_type='FUEL' AND deleted_at IS NULL AND event_date>=DATE_SUB(CURDATE(),INTERVAL 90 DAY) AND gallons IS NOT NULL AND gallons>0 AND amount>0",[$id])[0];
 $price=(float)($fuel['fuel_amount']??0)>0&&(float)($fuel['fuel_gallons']??0)>0?round((float)$fuel['fuel_amount']/(float)$fuel['fuel_gallons'],4):null;$model=field_vehicle_cost_model_from_row($v,$price);
 if(!isset($model['all_in_cpm'])||!is_finite((float)$model['all_in_cpm'])||$model['all_in_cpm']<=0)throw new InvalidArgumentException('Vehicle needs a valid cost model.');return ['vehicle'=>$v,'model'=>$model,'fingerprint'=>hash('sha256',field_tracker_json([$v,$model]))];
}
function gig_conflicts(PDO $pdo,array $p,int $vid):array{
 $errors=[];if(field_tracker_rows($pdo,'SELECT import_id FROM field_gig_tracker_imports WHERE outing_id=?',[$p['id']]))$errors[]='This outing was already imported. Re-exporting cannot create another shift.';
 foreach(array_chunk(array_keys($p['claims']),200)as $ids)if(field_tracker_rows($pdo,'SELECT source_id FROM field_gig_tracker_claims WHERE source_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)){$errors[]='One or more rides/events already belong to an import.';break;}
 $z=new DateTimeZone($p['timezone']);$a=field_tracker_datetime($p['start'],$z);$b=field_tracker_datetime($p['home'],$z);
 if(field_tracker_rows($pdo,'SELECT shift_id FROM field_rideshare_shifts WHERE vehicle_id=? AND ((started_at < ? AND DATE_ADD(ended_at,INTERVAL deadhead_minutes MINUTE)>?) OR ((started_at IS NULL OR ended_at IS NULL) AND shift_date BETWEEN ? AND ?)) LIMIT 1',[$vid,$b,$a,substr($a,0,10),substr($b,0,10)]))$errors[]='This vehicle already has an overlapping shift, or an untimed shift on this date. Review it in Gig Work; this pilot creates new shifts only.';return $errors;
}
/** Ticket is server-session data, never a browser-supplied object. */
function gig_apply(PDO $pdo,array $ticket,array $input,int $user):int{
 gig_ready($pdo);if($user<=0||($ticket['user_id']??null)!==$user||($ticket['expires']??0)<time())throw new InvalidArgumentException('Preview expired or belongs to another user.');
 $p=gig_parse($ticket['raw'],$ticket['timezone']);$vid=(int)$ticket['vehicle_id'];$own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT gig_tracker_apply');
 try{
  $c=gig_vehicle($pdo,$vid,true);if(!hash_equals($ticket['fingerprint'],$c['fingerprint']))throw new InvalidArgumentException('Vehicle or cost model changed. Preview again.');$errors=gig_conflicts($pdo,$p,$vid);if($errors)throw new InvalidArgumentException(implode(' ',$errors));
  $v=gig_values($p,$input,(float)$c['model']['all_in_cpm'],$vid,$user);
  $pdo->prepare('INSERT INTO field_gig_tracker_imports (outing_id,vehicle_id,content_sha256,raw_json,metrics_json,review_reason,applied_by,timezone_name) VALUES (?,?,?,?,?,?,?,?)')->execute([$p['id'],$vid,hash('sha256',$ticket['raw']),$ticket['raw'],field_tracker_json(['tracking'=>$p,'closing_note'=>$p['closing_note'],'saved_values'=>$v]),trim($input['review_reason']),$user,$p['timezone']]);$iid=(int)$pdo->lastInsertId();
  $claim=$pdo->prepare('INSERT INTO field_gig_tracker_claims (source_id,source_kind,import_id) VALUES (?,?,?)');foreach($p['claims']as $uuid=>$kind)$claim->execute([$uuid,$kind,$iid]);
  $pdo->prepare('INSERT INTO field_rideshare_shifts ('.implode(',',array_keys($v)).') VALUES ('.implode(',',array_fill(0,count($v),'?')).')')->execute(array_values($v));$sid=(int)$pdo->lastInsertId();
  $pdo->prepare('UPDATE field_gig_tracker_imports SET shift_id=? WHERE import_id=?')->execute([$sid,$iid]);
  $st=$pdo->prepare('INSERT INTO field_rideshare_breaks (shift_id,break_position,started_at,ended_at,break_minutes) VALUES (?,?,?,?,?)');foreach(field_rideshare_normalize_breaks($p['break_input'],$v['started_at'],$v['ended_at'])as $i=>$b)$st->execute([$sid,$i+1,$b['started_at'],$b['ended_at'],$b['break_minutes']]);
  if($own)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT gig_tracker_apply');return $sid;
 }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();elseif(!$own&&$pdo->inTransaction()){$pdo->exec('ROLLBACK TO SAVEPOINT gig_tracker_apply');$pdo->exec('RELEASE SAVEPOINT gig_tracker_apply');}throw $e;}
}
