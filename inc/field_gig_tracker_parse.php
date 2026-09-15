<?php
declare(strict_types=1);
require_once __DIR__.'/field_tracker_import.php';
require_once __DIR__.'/field_rideshare.php';

/** Parse completed outings without accessing the database. */
function gig_parse(string $raw,string $timezone):array {
 if(strlen($raw)>FIELD_TRACKER_MAX_BYTES || $raw==='')throw new InvalidArgumentException('Choose a completed JSON export, maximum 4 MiB.');
 $r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($r)||!in_array($r['schema']??'', ['mmit.ride-tracker.v1','mmit.work-tracker.v2'],true))throw new InvalidArgumentException('Unsupported export version.');
 $s=$r['shift']??[];$zone=new DateTimeZone($timezone);
 if(!in_array($s['platform']??'', ['LYFT','UBER'],true))throw new InvalidArgumentException('Choose Lyft or Uber. FieldNation uses its own importer.');
 $closingNote=field_tracker_closing_note($s['closingNote']??null);
 $id=field_tracker_uuid($s['id']??null);$start=field_tracker_epoch($s['startedAtEpochMs']??null);$off=field_tracker_epoch($s['wentOfflineAtEpochMs']??null);$home=field_tracker_epoch($s['homeArrivedAtEpochMs']??null);
 if($off<=$start||$home<$off||$home-$start>172800000||field_tracker_epoch($r['exportedAtEpochMs']??null)<$home)throw new InvalidArgumentException('Complete the shift and home arrival (maximum 48 hours).');
 if($r['schema']==='mmit.work-tracker.v2'&&field_tracker_epoch($s['completedAtEpochMs']??null)!==$home)throw new InvalidArgumentException('Completion and home arrival disagree.');
 if(!in_array($s['queueModeAtEnd']??'', ['MANUAL','AUTO'],true))throw new InvalidArgumentException('Unknown queue mode.');
 if($zone->getOffset(new DateTimeImmutable('@'.intdiv($start,1000)))!==$zone->getOffset(new DateTimeImmutable('@'.intdiv($home,1000))))throw new InvalidArgumentException('Daylight-saving transition requires manual review in this pilot.');
 $a=field_tracker_number($s['startOdometer']??null,'start odometer',0,9999999);$b=field_tracker_number($s['endOdometer']??null,'home odometer',$a,9999999);
 if($b-$a>3000||abs($a-round($a,1))>.00001||abs($b-round($b,1))>.00001)throw new InvalidArgumentException('Use odometers with at most one decimal; maximum 3,000-mile outing.');
 $events=$r['events']??null;$rides=$r['rides']??null;$gps=$r['locations']??null;
 foreach([[$events,5000],[$rides,500],[$gps,25000]] as [$list,$max])if(!is_array($list)||!array_is_list($list)||count($list)>$max)throw new InvalidArgumentException('Malformed or oversized export list.');
 $claims=[$id=>'outing'];$byRide=[];$global=[];$breaks=[];$breakStart=null;$last=$start;$queueChanges=[];
 $rideTypes=['RIDE_ACCEPTED','RIDE_AUTO_QUEUED','NEXT_PICKUP_STARTED','PASSENGER_PICKED_UP','PASSENGER_DROPPED_OFF','QUEUE_DISAPPEARED','RIDE_CANCELLED'];
 $globalTypes=['SHIFT_STARTED','SHIFT_ENDED','HOME_ARRIVED','BREAK_STARTED','BREAK_ENDED'];
 foreach($events as $e){
  if(!is_array($e))throw new InvalidArgumentException('Invalid event.');
  $eid=field_tracker_uuid($e['id']??null);if(isset($claims[$eid]))throw new InvalidArgumentException('Duplicate source ID.');$claims[$eid]='event';
  $t=field_tracker_epoch($e['occurredAtEpochMs']??null);$type=$e['type']??'';
  if($t<$last||$t>$home||($type!=='HOME_ARRIVED'&&$t>$off))throw new InvalidArgumentException('Events are out of order or outside the shift.');$last=$t;
  if(in_array($type,$rideTypes,true)){$rid=field_tracker_uuid($e['rideId']??null);$byRide[$rid][$type][]=$t;}
  elseif($type==='QUEUE_MODE_CHANGED'){
   if(!in_array($e['payload']['mode']??'', ['MANUAL','AUTO'],true))throw new InvalidArgumentException('Unknown queue mode change.');$queueChanges[]=$e;
  }elseif(in_array($type,$globalTypes,true)){
   if(!empty($e['rideId']))throw new InvalidArgumentException('Unexpected ride reference on shift event.');$global[$type][]=$t;
  }else throw new InvalidArgumentException('Unknown event type.');
  if($type==='BREAK_STARTED'){if($breakStart!==null)throw new InvalidArgumentException('Overlapping breaks.');$breakStart=$t;}
  if($type==='BREAK_ENDED'||($type==='SHIFT_ENDED'&&$breakStart!==null)){
   if($breakStart===null||$t<=$breakStart)throw new InvalidArgumentException('Unmatched break.');$breaks[]=[$breakStart,$t];$breakStart=null;
  }
 }
 foreach(['SHIFT_STARTED'=>$start,'SHIFT_ENDED'=>$off,'HOME_ARRIVED'=>$home]as $type=>$t)if(($global[$type]??[])!==[$t])throw new InvalidArgumentException('Shift metadata and events disagree.');
 if($breakStart!==null)throw new InvalidArgumentException('Unfinished break.');
 $segments=[];$seen=[];$completed=0;$lost=0;$bookedMs=0;$passMs=0;$modes=['MANUAL_ACCEPT'=>0,'AUTO_QUEUE'=>0];
 $eq=static function(array $es,string $type,?int $t):void{if(($es[$type]??[])!==($t===null?[]:[$t]))throw new InvalidArgumentException('Ride metadata disagrees with '.$type.'.');};
 foreach($rides as $i=>$ride){
  $rid=field_tracker_uuid($ride['id']??null);if(isset($claims[$rid])||($ride['sequence']??null)!==$i+1)throw new InvalidArgumentException('Duplicate or out-of-sequence ride.');$claims[$rid]='ride';$seen[$rid]=true;
  $mode=$ride['acquisitionMode']??'';if(!isset($modes[$mode]))throw new InvalidArgumentException('Unknown ride acquisition.');$modes[$mode]++;
  $q=field_tracker_epoch($ride['queuedAtEpochMs']??null);if($q<$start||$q>$off)throw new InvalidArgumentException('Ride queued outside shift.');$es=$byRide[$rid]??[];
  $acquire=$mode==='AUTO_QUEUE'?'RIDE_AUTO_QUEUED':'RIDE_ACCEPTED';if($q===$start&&isset($es['RIDE_ACCEPTED']))$acquire='RIDE_ACCEPTED';
  $eq($es,$acquire,$q);$eq($es,$acquire==='RIDE_ACCEPTED'?'RIDE_AUTO_QUEUED':'RIDE_ACCEPTED',null);
  if(($ride['state']??'')==='LOST'){
   foreach(['trackingStartedAtEpochMs','pickupAtEpochMs','dropoffAtEpochMs']as $key)if(isset($ride[$key]))throw new InvalidArgumentException('Lost pending ride has driving timestamps.');
   foreach(['NEXT_PICKUP_STARTED','PASSENGER_PICKED_UP','PASSENGER_DROPPED_OFF']as $type)$eq($es,$type,null);
   if(count($es['QUEUE_DISAPPEARED']??[])!==1||$es['QUEUE_DISAPPEARED'][0]<$q)throw new InvalidArgumentException('Lost ride lacks a valid disappearance event.');$lost++;continue;
  }
  if(($ride['state']??'')==='CANCELLED'){
   foreach(['pickupAtEpochMs','dropoffAtEpochMs']as $key)if(isset($ride[$key]))throw new InvalidArgumentException('Cancelled ride has passenger timestamps.');
   foreach(['PASSENGER_PICKED_UP','PASSENGER_DROPPED_OFF','QUEUE_DISAPPEARED']as $type)$eq($es,$type,null);
   if(count($es['RIDE_CANCELLED']??[])!==1||$es['RIDE_CANCELLED'][0]<$q)throw new InvalidArgumentException('Cancelled ride lacks a valid cancellation event.');
   continue;
  }
  if(($ride['state']??'')!=='COMPLETED')throw new InvalidArgumentException('Resolve all active and pending rides first.');
  $x=field_tracker_epoch($ride['trackingStartedAtEpochMs']??null);$y=field_tracker_epoch($ride['pickupAtEpochMs']??null);$z=field_tracker_epoch($ride['dropoffAtEpochMs']??null);
  if($x<$q||$y<$x||$z<$y||$z>$off)throw new InvalidArgumentException('Invalid ride interval.');
  $eq($es,'NEXT_PICKUP_STARTED',$x === $q ? null : $x);$eq($es,'PASSENGER_PICKED_UP',$y);$eq($es,'PASSENGER_DROPPED_OFF',$z);$eq($es,'QUEUE_DISAPPEARED',null);
  $segments[]=['id'=>$rid,'start'=>$x,'pickup'=>$y,'end'=>$z];$bookedMs+=$z-$x;$passMs+=$z-$y;$completed++;
 }
 if(array_diff_key($byRide,$seen))throw new InvalidArgumentException('Unknown ride reference.');
 usort($segments,fn($x,$y)=>$x['start']<=>$y['start']);$last=$start;
 foreach($segments as $v){if($v['start']<$last)throw new InvalidArgumentException('Overlapping rides; pending queue time must not be counted twice.');$last=$v['end'];foreach($breaks as [$x,$y])if($v['start']<$y&&$v['end']>$x)throw new InvalidArgumentException('Ride overlaps break.');}
 $breakInput=['break_started_at'=>[],'break_ended_at'=>[]];$breakMinutes=0;
 foreach($breaks as [$x,$y]){$m=(int)round((intdiv($y,1000)-intdiv($x,1000))/60);if($m<1)throw new InvalidArgumentException('OPS requires breaks of at least 30 seconds; review this outing manually.');$breakMinutes+=$m;$breakInput['break_started_at'][]=field_tracker_datetime($x,$zone);$breakInput['break_ended_at'][]=field_tracker_datetime($y,$zone);}
 $online=max(0,(int)round((intdiv($off,1000)-intdiv($start,1000))/60)-$breakMinutes);$booked=min($online,(int)round($bookedMs/60000));$pass=min($booked,(int)round($passMs/60000));
 // Classify fixes from the validated timeline, not untrusted phase labels. Never bridge boundaries or gaps.
 $measured=['pickup'=>null,'passenger'=>null,'home'=>null];$coverage=array_fill_keys(array_keys($measured),0.0);$prior=null;$lastGps=0;
 $bounds=[$start,$off,$home];foreach($segments as $v)array_push($bounds,$v['start'],$v['pickup'],$v['end']);foreach($breaks as [$x,$y])array_push($bounds,$x,$y);
 foreach($gps as $point){
  $t=field_tracker_epoch($point['occurredAtEpochMs']??null);$lat=field_tracker_number($point['latitude']??null,'GPS latitude',-90,90);$lon=field_tracker_number($point['longitude']??null,'GPS longitude',-180,180);$acc=field_tracker_number($point['accuracyMeters']??null,'GPS accuracy',0,100000);
  if($t<$lastGps)throw new InvalidArgumentException('GPS is out of order.');$lastGps=$t;$bucket=null;$key=null;
  if($t>=$off&&$t<=$home){$bucket='home';$key='home';}else foreach($segments as $v)if($t>=$v['start']&&$t<$v['end']){$bucket=$t<$v['pickup']?'pickup':'passenger';$key=$v['id'].':'.$bucket;break;}
  if($bucket===null||$acc>50){$prior=null;continue;}
  if($prior&&$prior['key']===$key){$dt=($t-$prior['t'])/1000;$cross=false;foreach($bounds as $bound)if($bound>$prior['t']&&$bound<=$t){$cross=true;break;}
   $h=sin(deg2rad($lat-$prior['lat'])/2)**2+cos(deg2rad($lat))*cos(deg2rad($prior['lat']))*sin(deg2rad($lon-$prior['lon'])/2)**2;$mi=7917.5226*asin(sqrt(min(1,max(0,$h))));
   if(!$cross&&$dt>0&&$dt<=30&&$mi/$dt*3600<=100){$measured[$bucket]=($measured[$bucket]??0)+$mi;$coverage[$bucket]+=$dt;}
  }$prior=['t'=>$t,'lat'=>$lat,'lon'=>$lon,'key'=>$key];
 }
 $warnings=['Booked time includes active travel to pickup plus passenger time. Pending queue time is excluded.','GPS segments may be incomplete. Gaps, poor fixes and phase boundaries are excluded; review mileage rather than treating unavailable as zero.','Enter reviewed return-home miles. The offline odometer will be derived from the home odometer so return miles are counted once.'];
 if($home-$start<300000||$a===$b)$warnings[]='Short or zero-mile outing: confirm an intentional test.';
 foreach($segments as $v)if($v['end']-$v['pickup']<30000){$warnings[]='A passenger ride is under 30 seconds. Check for missed pickup/drop-off taps.';break;}
 return ['id'=>$id,'platform'=>$s['platform'],'timezone'=>$timezone,'start'=>$start,'offline'=>$off,'home'=>$home,'start_odometer'=>$a,'home_odometer'=>$b,'total_miles'=>round($b-$a,1),'online_minutes'=>$online,'deadhead_minutes'=>(int)round((intdiv($home,1000)-intdiv($off,1000))/60),'booked_minutes'=>$booked,'passenger_minutes'=>$pass,'break_minutes'=>$breakMinutes,'break_input'=>$breakInput,'completed_rides'=>$completed,'lost_rides'=>$lost,'acquisition'=>$modes,'queue_changes'=>$queueChanges,'gps'=>$measured,'coverage_seconds'=>$coverage,'claims'=>$claims,'warnings'=>$warnings,'closing_note'=>$closingNote];
}

function gig_number(array $input,string $key,int $decimals=2,float $min=0,float $max=100000):float{
 $s=$input[$key]??null;if(!is_string($s)||!preg_match('/^-?\d+(?:\.\d{1,'.$decimals.'})?$/D',trim($s)))throw new InvalidArgumentException('Enter a number for '.str_replace('_',' ',$key).'.');$n=(float)$s;if(!is_finite($n)||$n<$min||$n>$max)throw new InvalidArgumentException('Out-of-range '.str_replace('_',' ',$key).'.');return round($n,$decimals);
}
function gig_values(array $p,array $in,float $cpm,int $vehicle,int $user):array{
 $dead=gig_number($in,'deadhead_miles',1,0,$p['total_miles']);$book=gig_number($in,'booked_miles',2,0,round($p['total_miles']-$dead,2));$pass=gig_number($in,'passenger_miles',2,0,$book);
 if($p['offline']===$p['home']&&$dead>0)throw new InvalidArgumentException('No return interval was recorded.');
 $reason=$in['review_reason']??null;if(!is_string($reason)||trim($reason)===''||strlen($reason)>1000||str_contains($reason,"\0"))throw new InvalidArgumentException('Enter a review reason (maximum 1,000 bytes).');
 if(!in_array($in['payout_destination']??'', ['LYFT_DIRECT','PERSONAL_BANK','PNC','OTHER'],true))throw new InvalidArgumentException('Select a payout destination.');
 $z=new DateTimeZone($p['timezone']);$v=['vehicle_id'=>$vehicle,'platform'=>$p['platform'],'shift_date'=>substr(field_tracker_datetime($p['start'],$z),0,10),'started_at'=>field_tracker_datetime($p['start'],$z),'ended_at'=>field_tracker_datetime($p['offline'],$z),'odometer_start'=>$p['start_odometer'],'odometer_end'=>round($p['home_odometer']-$dead,1),'deadhead_miles'=>$dead,'deadhead_minutes'=>$p['deadhead_minutes'],'online_minutes'=>$p['online_minutes'],'booked_minutes'=>$p['booked_minutes'],'passenger_minutes'=>$p['passenger_minutes'],'booked_miles'=>$book,'passenger_miles'=>$pass,'vehicle_cpm_snapshot'=>round($cpm,4),'created_by_user_id'=>$user,'payout_destination'=>$in['payout_destination']];
 foreach(['base_ride_earnings','tips','bonuses','adjustments','toll_reimbursements','platform_fees','direct_trip_costs']as $key)$v[$key]=gig_number($in,$key,2,$key==='adjustments'?-100000:0);
 $v['notes']='Tracker '.$p['id'].'; '.$p['completed_rides'].' completed rides; '.$p['lost_rides'].' lost queued rides. Home odometer '.$p['home_odometer'].' at '.field_tracker_datetime($p['home'],$z).'. Offline odometer derived from reviewed return miles. Booked = pickup travel + passenger. '.trim($reason);
 $calc=field_rideshare_calculate($v);foreach(['business_miles','vehicle_cost','recognized_revenue','true_operating_profit']as $key)$v[$key]=$calc[$key];
 if(abs($calc['total_business_miles']-$p['total_miles'])>.001)throw new RuntimeException('Mileage reconciliation failed.');return $v;
}
