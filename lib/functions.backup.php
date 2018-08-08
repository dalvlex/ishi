<?php

$backup_types=array('daily'=>1,'weekly'=>1,'user'=>1);

function backup_site($name="ALL",$type="ALL"){
	global $f_settings, $backup_types;
	$settings=read_settings($f_settings);
	$list=read_list($settings['.store_sites']);
	
	if($name=="ALL"){
		if($type=="ALL"){
			foreach($list as $nk => $nv){
				foreach($backup_types as $bk => $bv){
					if(!backup_site($nk,$bk)){
						echo "No backup for $nk\n";
						//return FALSE;
					}
				}
			}
		}
		elseif(isset($backup_types[$type])){
			foreach($list as $nk => $nv){
				if(!backup_site($nk,$type)){
					echo "No backup for $nk\n";
					//return FALSE;
				}
			}
		}
		return TRUE;
	}
	elseif($name!="ALL" && $type=="ALL"){
		foreach($backup_types as $bk => $bv){
			if(!backup_site($name,$bk)){
				echo "No backup for $nk\n";
				//return FALSE;
			}
		}
		return TRUE;
	}

	//incorect site name & happens only on manual
	if(!isset($list[$name])||!is_array($list[$name])||!isset($backup_types[$type])){
		return FALSE;
	}

	//check if site backups are disabled for and and it wasn't a user request.
	if(!$list[$name]['backups']&&$type!='user'){
		//skip backup nicely
		echo "Backup skipped for $name\n";
		return TRUE;
	}

	$date=date("Ymd_His",time());

	$keep=`mysqldump {$name} > /tmp/database_dump.sql; chown -R {$name}:{$name} /tmp/database_dump.sql`;
	$keep=`tar czf {$settings['.backup_path']}/{$name}-{$type}_{$date}.tar.gz -C /home/{$name} www -C /tmp database_dump.sql`;
	$keep=`rm -rf /tmp/database_dump.sql`;

	if(is_file("{$settings['.backup_path']}/{$name}-{$type}_{$date}.tar.gz")){
		write_backups('add',$name,$type,$date);

		return TRUE;
	}

	return FALSE;
}

function list_backups($name="ALL"){
	global $f_settings;
	$settings=read_settings($f_settings);
	$file=$settings['.store_backups'];

	$list=array();

	$file=file_get_contents($file);
	$file=explode("\n",$file);

	foreach($file as $fv){
		if(strpos($fv,':')!==FALSE){
			$fv=explode(':',$fv);
			$list[$fv[0]][$fv[1]][$fv[2]]=$fv[3];
		}
	}
	if($name!="ALL"&&isset($list[$name])){
		return array($name=>$list[$name]);
	}
	elseif($name=="ALL"){
		return $list;
	}
	else{
		return FALSE;
	}
}

function write_backups($action,$name,$type,$date){
	$list=list_backups();

	if($action=="add"){
		$new_backup="{$name}-{$type}_{$date}.tar.gz";
		if(isset($list[$name][$type][$date]))  unset($list[$name][$type][$date]);

		$list[$name][$type][$date]=$new_backup;
	}
	elseif($action=="del"){
		if(isset($list[$name][$type][$date])) unset($list[$name][$type][$date]);
	}
	
	$file='';
	foreach($list as $nk => $nv){
		foreach($nv as $tk => $tv){
			foreach($tv as $dk => $dv){
				$file.="{$nk}:{$tk}:{$dk}:{$nk}-{$tk}_{$dk}.tar.gz\n";
			}
		}
	}

	global $f_settings;
	$settings=read_settings($f_settings);

	return file_put_contents($settings['.store_backups'],$file);
}

function backup_rotate($site="ALL"){
	if($site=="ALL"){
		$list=list_backups();
		foreach($list as $nk => $nv){
			backup_rotate($nk);
		}
	}
	else{
		global $f_settings;
		$settings=read_settings($f_settings);

		$c=array();
		$c['daily']=$settings['.keep_daily'];
		$c['weekly']=$settings['.keep_weekly'];
		$c['user']=$settings['.keep_user'];

		$list=list_backups($site);
		foreach($c as $ck => $cv){
			if(isset($list[$site][$ck])&&is_array($list[$site][$ck])){
				while(count($list[$site][$ck])>$cv){
					$key=min(array_keys($list[$site][$ck]));
					$keep=`rm -rf {$settings['.backup_path']}/{$list[$site][$ck][$key]}`;
					write_backups('del',$site,$ck,$key);
					unset($list[$site][$ck][$key]);
				}
			}
		}//fend
	}
	return TRUE;
}

function list_backups_nice($name="ALL"){
	$list=list_backups($name);

	echo "Name\t\t\t\tType\t\t\tArchive\n";
	if(!isset($list)||!is_array($list)) return FALSE;

	foreach($list as $nk => $nv){
		echo "----------------------------------------------------------------------------------------------------------------\n";
		foreach($nv as $tk => $tv){
			foreach($tv as $dk => $dv){
				$t=4-floor(strlen($nk)/8);
				$tt='';
				for($i=0;$i<$t;$i++){
					$tt.="\t";
				}
				$t2=4-floor(strlen($tk)/4);
				$tt2='';
				for($i=0;$i<$t2;$i++){
					$tt2.="\t";
				}
				echo "{$nk}{$tt}{$tk}{$tt2}{$dv}\n";
			}
		}
	}
}

function restore_from($name,$file){
	global $f_settings;
	$settings=read_settings($f_settings);

	//check if we have a backup with that file name
	$isit=`grep -c '{$file}' {$settings['.store_backups']}`;
	if(!$isit){
		return FALSE;
	}

	//delete current www and extact new one
	if(file_exists("{$settings['.backup_path']}/{$file}")&&is_file("{$settings['.backup_path']}/{$file}")){
		$keep=`rm -rf /home/{$name}/www`;
	}else{
		return FALSE;
	}
	$keep.=`tar xzf {$settings['.backup_path']}/{$file} -C /home/{$name}/`;

	//empty database and load new one
	$keep.=`mysql --execute="DROP DATABASE IF EXISTS {$name};"`;
	$keep.=`mysql --execute="CREATE DATABASE {$name};"`;
	$keep.=`mysql -h localhost {$name} < /home/{$name}/database_dump.sql`;
	$keep.=`rm -rf /home/{$name}/database_dump.sql`;

	return TRUE;
}

?>