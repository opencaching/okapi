<?php

$rootpath = '../../../';
require_once($rootpath.'okapi/core.php');
OkapiErrorHandler::$treat_notices_as_errors = true;

$request = new OkapiRequest(array(
	'consumer'   => 'required',
	'token'      => 'optional',
));

# We declared Token to be 'optional' in the OkapiRequest above. This means,
# that OKAPI will pass on requests NOT signed with an Access Token. We may
# check if this requests is signed with: $request->token != null.


	// check requited prameters

	if($area!="0,0,0,0" || $point!="0,0" || $wp!="" || $owner!="" || $sqlmodifiedsience=!"")
	{




if ($dist!="0") {
$hdistance=" HAVING distance < ".$dist;
} else {$hdistance="";}



if ($point!="0,0") {
    $point=explode(",",$point);
    $lon=$point[1];
    $lat=$point[0];

$sqlcenter=" ROUND((".getSqlDistanceFormula($lat,$lon,0, 1)."),1) distance,";
    } else {$sqlcenter="0 distance,";}

if ($area!="0,0,0,0") {
  $abox = mb_split(',', $area);

  if (count($abox) != 4) exit;

  $lon_from = $abox[1];
  $lat_from = $abox[0];
  $lon_to = $abox[3];
  $lat_to = $abox[2];


  if ((abs($lon_from - $lon_to) > 2) || (abs($lat_from - $lat_to) > 2))
  {
		$lon_from = $lon_to;
		$lat_from = $lat_to;
  }
       // set area center to calculate distance from center in area
	$centerlat = ($lat_from + $lat_to)/2;
	$centerlon = ($lon_from + $lon_to)/2; 

$sqlcenter=" ROUND((".getSqlDistanceFormula($centerlat,$centerlon,0, 1)."),1) distance,";

$sql_where_area=" AND `caches`.`longitude`>='".$lon_from."' AND `caches`.`longitude`<='".$lon_to."' AND `caches`.`latitude`>='".$lat_from."' AND `caches`.`latitude`<='".$lat_to."'";


} else { $sql_where_area="";}



$sql="SELECT $sqlcenter
							`caches`.`cache_id` `cache_id`, 
							`caches`.`type` `type`,
							`cache_type`.`en` `type_name`,
							`caches`.`status` `status`,
							`cache_status`.`en` `status_name`,
							`caches`.`name` `cache_name`, 
							`caches`.`user_id` `owner_id`, 
							`user`.`username` `owner`, 
							`caches`.`size` `size`, 
							`cache_size`.`en` `size_name`, 
							`countries`.`pl` `country`, 
							`cache_location`.`adm3` `region`, 
							`caches`.`latitude` `latitude`,
							`caches`.`longitude` `longitude`,
							`caches`.`wp_oc` `wp_oc`, 
							ROUND((`caches`.`terrain`)/2,1) `terrain`, 
							ROUND((`caches`.`difficulty`)/2,1) `difficulty`, 
							`caches`.`search_time` `search_time`, 
							`caches`.`way_length` `way_length`, 
							`caches`.`topratings` `recommend`,
							`caches`.`score` `score`,
							`caches`.`founds` `founds`,
							`caches`.`notfounds` `not_founds`,
							`caches`.`notes` `comments`,
							`caches`.`date_created` `date_created`, 
							`caches`.`date_hidden` `date_hidden`,
							`caches`.`last_modified` `date_modified`
							$desc
					FROM caches,user,cache_type,cache_size,countries,cache_location,cache_status,cache_desc $tables
					WHERE caches.cache_id=cache_location.cache_id
					AND caches.cache_id=cache_desc.cache_id 
					AND cache_status.id=caches.status 
					AND caches.country=countries.short 
					AND caches.size=cache_size.id AND caches.type=cache_type.id 
					AND caches.user_id=user.user_id $sql_where $sql_where_area 
					$hdistance
					ORDER BY distance
					$limit";
					
//							(SELECT GROUP_CONCAT(attrib_id SEPARATOR ',') FROM caches_attributes WHERE caches_attributes.cache_id=caches.cache_id GROUP BY cache_id) attributes_id,
//							(SELECT GROUP_CONCAT(cache_attrib.text_long SEPARATOR ',') FROM caches_attributes,cache_attrib WHERE `cache_attrib`.`id`=`caches_attributes`.`attrib_id`
//						  AND `cache_attrib`.`language`='PL'
//						  AND `caches_attributes`.`cache_id`=caches.cache_id GROUP BY cache_id) attributes_names
 
$caches = $this->db->query($sql);

$all_caches='';

$num_rows=$caches->num_rows();

if ($num_rows!=0){
foreach ($caches->result() as $crow){
$cache_r=(array)$crow;
if ($desc!="")
$cache_r['desc']=cleanup_text($crow->desc);
$cache_r['cache_name']=cleanup_text($crow->cache_name);
$cache_r['score']=score2rating($crow->score);

	$time_hours = floor($crow->search_time);
	$time_min=($crow->search_time - $time_hours) * 60;
	$time_min = sprintf('%02d', round($time_min,1));
	$search_time=$time_hours . ':' . $time_min . ' h';

$cache_r['search_time']=$search_time;
$cache_r['way_length']=sprintf('%01.2f km', $crow->way_length);


//print_r($cache_r);

$log='';
$logs['logs']='';
$nrattrib='';
$attributes['attributes']='';

//Get cache attributes
$sqlattrib ="SELECT `caches_attributes`.`attrib_id` `id`, `cache_attrib`.`text_long` `name` FROM `caches_attributes`, `cache_attrib` WHERE `caches_attributes`.`cache_id`=".$crow->cache_id." AND `caches_attributes`.`attrib_id` = `cache_attrib`.`id` AND `cache_attrib`.`language` = 'PL' ORDER BY `caches_attributes`.`attrib_id`";
$query_attrib = $this->db->query($sqlattrib);

foreach ($query_attrib->result() as $row)
{
	$nrattrib['attribute id="'.$row->id.'"']['id']=$row->id;
	$nrattrib['attribute id="'.$row->id.'"']['name']=$row->name;
}
$attributes['attributes']=$nrattrib;
//Get cache pictures
$pic='';
$pictures['pictures']='';

$sqlpic ="SELECT `pictures`.`id`, `pictures`.`spoiler`, `pictures`.`title`, `pictures`.`url`, `pictures`.`thumb_url` FROM `pictures` WHERE `pictures`.`display`=1 AND `pictures`.`object_id`=".$crow->cache_id;
$query_pic = $this->db->query($sqlpic);

foreach ($query_pic->result() as $row)
{
	$pic['picture id="'.$row->id.'"']['id']=$row->id;	
	$pic['picture id="'.$row->id.'"']['spoiler']=$row->spoiler;
	$pic['picture id="'.$row->id.'"']['title']=cleanup_text($row->title);
	$pic['picture id="'.$row->id.'"']['url']=$row->url;
	$pic['picture id="'.$row->id.'"']['thumb_url']=$row->thumb_url;
}
$pictures['pictures']=$pic;

// Get cache logs
if ( $log_limit>"0"){
$rsLogs ="SELECT `cache_logs`.`id`, `cache_logs`.`type`, `cache_logs`.`date`, `cache_logs`.`text`, `user`.`username` `finder`, `cache_logs`.`user_id` `userid`, `log_types`.`en` `type_name` 
	    FROM `cache_logs`, `log_types`,`user` 
	    WHERE `log_types`.`id`=`cache_logs`.`type` AND `cache_logs`.`deleted`=0 AND `cache_logs`.`user_id`=`user`.`user_id` AND `cache_logs`.`cache_id`=".$crow->cache_id." ORDER BY `cache_logs`.`date` DESC, `cache_logs`.`id` DESC LIMIT $log_limit"; 

$query_logs = $this->db->query($rsLogs);

foreach ($query_logs->result() as $row)
{
	$log['log id="'.$row->id.'"']['id']=$row->id;
	$log['log id="'.$row->id.'"']['date']=$row->date;
	$log['log id="'.$row->id.'"']['finder']=cleanup_text($row->finder);
	$log['log id="'.$row->id.'"']['finder_id']=$row->userid;
	$log['log id="'.$row->id.'"']['type_id']=$row->type;
	$log['log id="'.$row->id.'"']['type_name']=$row->type_name;
	$log['log id="'.$row->id.'"']['text']=cleanup_text($row->text);
	}
	}
$logs['logs']=$log;


$cache=(array)array_merge((array)$cache_r,(array)$attributes,(array)$pictures,(array)$logs);

$all_caches[]=$cache;
}
}
return $all_caches;

}


		