<?php
$pwd = realpath( dirname( __FILE__ ) ).'/..';
$f_settings=$pwd.'/etc/settings';


function arg_array($args){
	$ret=array('script_name'=>$args[0]);
	unset($args[0]);

	if(!count($args)) return $ret;

	foreach($args as $av){
		$av=explode('=',$av);
		$ret[trim(reset($av))]=trim(end($av));
	}
	return $ret;
}

function read_settings($file){
	$settings=array();

	$file=file_get_contents($file);
	$file=explode("\n",$file);

	//store vars & disregard comments
	foreach($file as $fv){
		if (strpos($fv, '.')===0&&strpos($fv, '=')!==FALSE){
			$fv=explode('=',$fv);
			if(strpos($fv[1],'/')===0){
				$fv[1] = rtrim($fv[1], '/');
			}
			if($fv[0] == '.ssh'){
				$settings['.ssh'][$fv[1]]=$fv[2];
			}
			else{
				$settings[$fv[0]]=$fv[1];
			}
		}
	}
	return $settings;
}

function read_list(){
	$list=array();

	$file=file_get_contents('/etc/passwd');
	$file=explode("\n",$file);

	foreach($file as $fv){
		if(strpos($fv,'/home/build_')!==FALSE || strpos($fv,'/home/live_')!==FALSE){
			$fv=explode(':',$fv);
			if(strpos($fv[4],'|')!==FALSE){
				$config=explode('|',$fv[4]);
				$list[$fv[0]]=array('domain'=>$config[0],'backups'=>$config[1],'email'=>$config[2]);
			}
		}
	}
	return $list;
}

function toggle_backups($name){
	$list=read_list();
	if(isset($list[$name])){
		$list[$name]['backups']=abs($list[$name]['backups']-=1);
		`usermod -c "{$list[$name]['domain']}|{$list[$name]['backups']}|{$list[$name]['email']}" {$name}`;
	}

	$changed = `grep -c "{$list[$name]['domain']}|{$list[$name]['backups']}|{$list[$name]['email']}" /etc/passwd`;
	if($changed) return TRUE;

	return FALSE;
}

function toggle_active($name){
	global $f_settings;

	$keep='';
	$settings=read_settings($f_settings);
	$list=read_list();

	$return = FALSE;

	if(isset($list[$name])){
		$webserver = strcmp($settings['.web'],'nginx'===0)?'nginx':'apache2';
		$confsuffix = strcmp($settings['.web'],'nginx'===0)?'':'.conf';

		$enconf = "/etc/{$webserver}/sites-enabled/{$name}{$confsuffix}";
		$avconf = "/etc/{$webserver}/sites-available/{$name}{$confsuffix}";

		if(is_file($enconf)){
			$keep.=`rm -rf {$enconf}`;
			$return = is_link($enconf)?FALSE:TRUE;
		}
		else {
			$keep.=`ln -s {$avconf} {$enconf}`;
			$return = is_link($enconf)?TRUE:FALSE;
		}

		$keep.=`service {$webserver} restart`;

	}
	return $return;
}

function generate_password($length = 9, $add_dashes = false, $available_sets = 'lud'){
	$sets = array();
	if(strpos($available_sets, 'l') !== false)
		$sets[] = 'abcdefghjkmnpqrstuvwxyz';
	if(strpos($available_sets, 'u') !== false)
		$sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
	if(strpos($available_sets, 'd') !== false)
		$sets[] = '23456789';
	if(strpos($available_sets, 's') !== false)
		$sets[] = '!@#$%&*?';

	$all = '';
	$password = '';
	foreach($sets as $set)
	{
		$password .= $set[array_rand(str_split($set))];
		$all .= $set;
	}

	$all = str_split($all);
	for($i = 0; $i < $length - count($sets); $i++)
		$password .= $all[array_rand($all)];

	$password = str_shuffle($password);

	if(!$add_dashes)
		return $password;

	$dash_len = floor(sqrt($length));
	$dash_str = '';
	while(strlen($password) > $dash_len)
	{
		$dash_str .= substr($password, 0, $dash_len) . '-';
		$password = substr($password, $dash_len);
	}
	$dash_str .= $password;
	return $dash_str;
}

function set_ssh_key($name,$ssh){
	global $f_settings;
	$settings=read_settings($f_settings);

	$keys=$settings['.ssh'];
	$authorized_keys = '';
	if($ssh == 'all'){
		foreach($keys as $fk => $fv)
			$authorized_keys .= "{$settings['.ssh'][$fk]}\n";
	}
	elseif($ssh = 'none'){
		$authorized_keys = '';
	}
	else{
		if(strpos($ssh, ',')){
			$ssh=explode(',',$ssh);

			foreach($ssh as $fv)
				if(strlen($fv)>=2&&isset($settings['.ssh'][$fv]))
					$authorized_keys .= "{$settings['.ssh'][$fv]}\n";
		}
		else {
			if(isset($settings['.ssh'][$ssh]))
				$authorized_keys .= "{$settings['.ssh'][$ssh]}\n";
		}
	}

	$sites=read_list();
	if($name=="all"){
		foreach($sites as $nk => $nv){
			set_ssh_key($nk,$ssh);
		}
		return;
	}else{
		if(isset($sites[$name])){
			$keep=`mkdir -p /home/{$name}/.ssh`;
			$keep.=`touch /home/{$name}/.ssh/authorized_keys > /dev/null 2>&1`;
			$keep.=`chattr -i /home/{$name}/.ssh/authorized_keys`;
			file_put_contents("/home/{$name}/.ssh/authorized_keys", $authorized_keys);
			$keep.=`chown {$name}:{$name} /home/{$name}/.ssh/authorized_keys`;
			$keep.=`chmod 400 /home/{$name}/.ssh/authorized_keys`;
			$keep.=`chattr +i /home/{$name}/.ssh/authorized_keys`;
		}
	}
}

function site_is_check($name){
	global $f_settings;
	$settings=read_settings($f_settings);
	$list=read_list();

	if(strpos(file_get_contents('/etc/passwd'),$name.':')===FALSE){
		return FALSE;
	}else{
		return TRUE;
	}
}

function site_add($type,$name,$domain,$email,$backups=1,$ssl=1,$ssh){
	global $pwd, $f_settings;
	$settings=read_settings($f_settings);

	$name=$type.'_'.str_replace('.', '_', $name);

	//check if user exists
	if(site_is_check($name)){
		echo die("It appears that this site already exists!\n");
		return FALSE;
	}

	//create system account with ID over 2000.
	$keep=`useradd -K UID_MIN=9000 -K UID_MAX=10000 -c "{$domain}|{$backups}|{$email}" -s /bin/bash -m {$name}`;

	//check to see if user was created
	if(!site_is_check($name)){
		return FALSE;
	}

	$uid=trim(`grep {$name} /etc/passwd |awk -F ':' '{print \$3}'`);

	//create folders
	$keep.=`mkdir -p /home/{$name}/www`;

	//create mysql db
	$keep.=`mysql --execute="CREATE DATABASE {$name};"`;

	//set mysql pass
	$mysql_pass=generate_password(24);

	//create mysql account
	$keep.=`mysql --execute="GRANT ALL PRIVILEGES ON {$name}.* To '{$name}'@'localhost' IDENTIFIED BY '{$mysql_pass}';"`;

	//set mysql autologin file
	file_put_contents("/home/{$name}/.my.cnf", "[client]\nuser={$name}\npassword={$mysql_pass}\n");
	$keep.=`chmod 640 /home/{$name}/.my.cnf`;

	//add php-fpm pool
	$keep.=`cp {$pwd}/etc/templates/phpfpm.template /etc/php/{$settings['.php']}/fpm/pool.d/{$name}.conf`;
	$keep.=`sed -i 's/!USERNAME!/{$name}/g' /etc/php/{$settings['.php']}/fpm/pool.d/{$name}.conf`;
	$keep.=`sed -i 's/!PORT!/{$uid}/g' /etc/php/{$settings['.php']}/fpm/pool.d/{$name}.conf`;

	//add nginx or apache2
	if($settings['.web'] == 'nginx'){
	    $keep.=`cp {$pwd}/etc/templates/nginx.template /etc/nginx/sites-available/{$name}`;
	    $keep.=`sed -i 's/!USERNAME!/{$name}/g' /etc/nginx/sites-available/{$name}`;
	    $keep.=`sed -i 's/!DOMAIN!/{$domain}/g' /etc/nginx/sites-available/{$name}`;
	    $keep.=`sed -i 's/!PORT!/{$uid}/g' /etc/nginx/sites-available/{$name}`;
	    $keep.=`mkdir -p /etc/nginx/sites-locked`;
	    $keep.=`touch /etc/nginx/sites-locked/{$name}`;
	}
	else{
	    $keep.=`cp {$pwd}/etc/templates/apache2.template /etc/apache2/sites-available/{$name}.conf`;
	    $keep.=`sed -i 's/!USERNAME!/{$name}/g' /etc/apache2/sites-available/{$name}.conf`;
	    $keep.=`sed -i 's/!DOMAIN!/{$domain}/g' /etc/apache2/sites-available/{$name}.conf`;
	    $keep.=`sed -i 's/!PORT!/{$uid}/g' /etc/apache2/sites-available/{$name}.conf`;
	    $keep.=`mkdir -p /etc/apache2/sites-locked`;
	    $keep.=`touch /etc/apache2/sites-locked/{$name}`;
	    $keep.=`touch /etc/apache2/sites-locked/{$name}_passwd`;
	}

    //create locking template
    $keep.=`cp {$pwd}/etc/templates/site-lock.template {$pwd}/etc/sites-locked/{$name}.conf`;
    $keep.=`sed -i 's/!USERNAME!/{$name}/g' {$pwd}/etc/sites-locked/{$name}.conf`;

    //create password template
    if($settings['.web'] == 'nginx'){
    	$passwd = 'auth_basic "Restricted Content";
        auth_basic_user_file /etc/nginx/.htpasswd;';
        file_put_contents("/etc/nginx/sites-locked/{$name}_passwd", $passwd);
    }
    else{
    	$passwd = 'AuthType Basic
        AuthName "Restricted Content"
        AuthUserFile /etc/apache2/.htpasswd
        Require valid-user';
    	file_put_contents("/etc/apache2/sites-locked/{$name}_passwd", $passwd);
    }

	//set privileges
	$keep.=`chown -R {$name}:{$name} /home/{$name}`;

	//set ssh key
	set_ssh_key($name,$ssh);

	//enable nginx or apache2
	if($settings['.web'] == 'nginx'){
		$keep.=`ln -s /etc/nginx/sites-available/{$name} /etc/nginx/sites-enabled/{$name}`;
	}
	else{
		$keep.=`ln -s /etc/apache2/sites-available/{$name}.conf /etc/apache2/sites-enabled/{$name}.conf`;
	}

	//restart php server
	$keep.=`service php{$settings['.php']}-fpm restart`;

	//restart nginx or apache2 server
	if($settings['.web'] == 'nginx'){
		$keep.=`service nginx restart`;
	}
	else{
		$keep.=`service apache2 restart`;
	}

	//generate and enable ssl certificate, if user did not request it to be disabled
	if($ssl){
		generate_ssl($name, $email, $domain);
	}

	return TRUE;
}

function generate_ssl($name, $email, $domain){
	global $pwd, $f_settings;
	$settings=read_settings($f_settings);

	$keep=`/usr/bin/letsencrypt certonly --non-interactive --webroot --webroot-path /home/{$name}/www/ --renew-by-default --email {$email} --text --agree-tos -d {$domain} -d www.{$domain}`;

	// add SSL certificate to nginx or apache2
	if($settings['.web'] == 'nginx'){
		$keep.=`sed -i 's/#ssl#//g' /etc/nginx/sites-available/{$name}`;
		$keep.=`service nginx restart`;
	}
	else{
		$keep.=`sed -i 's/#ssl#//g' /etc/apache2/sites-available/{$name}.conf`;
		$keep.=`service apache2 restart`;
	}
}

function enable_ssl($name){
	$list=read_list();

	if(isset($list[$name])){
		generate_ssl($name, $list[$name]['email'], $list[$name]['domain']);
	}

	$sslexists = is_dir('/etc/letsencrypt/live/'.$list[$name]['domain']);
	if($sslexists === TRUE) return TRUE;

	return FALSE;
}

function toggle_ssl($name){
	global $f_settings;

	$keep='';
	$settings=read_settings($f_settings);
	$list=read_list();

	if(isset($list[$name])){
		$webserver = strcmp($settings['.web'],'nginx'===0)?'nginx':'apache2';
		$confsuffix = strcmp($settings['.web'],'nginx'===0)?'':'.conf';

		$avconf = "/etc/{$webserver}/sites-available/{$name}{$confsuffix}";
		$isdeactivated = `grep -c "#ssl#" /etc/nginx/sites-available/live_golans`;
		$sslexists = is_dir('/etc/letsencrypt/live/'.$list[$name]['domain']);

		if(is_file($avconf) && $sslexists === TRUE){
			if($isdeactivated){
				$keep.=`sed -i 's/#ssl#//g' {$avconf}`;
			}
			else {
				if($webserver == 'nginx'){
					$keep.=`sed -i '/ssl_/ s=^=#ssl#=' {$avconf}`;
					$keep.=`sed -i '/return 301 https/ s=^=#ssl#=' {$avconf}`;
				}
				else {
					$keep.=`sed -i '/Rewrite/ s=^=#ssl#=' {$avconf}`;
					$keep.=`sed -i '/SSL/ s=^=#ssl#=' {$avconf}`;
				}
			}
			$keep.=`service {$webserver} restart`;
			return TRUE;
		}
	}
	return FALSE;
}

function site_del($name, $backups=0){
	global $pwd, $f_settings;
	$settings=read_settings($f_settings);

	//check if exists
	if(!site_is_check($name)){
		return FALSE;
	}

	//get domain name
	$list=read_list();
	$domain=$list[$name]['domain'];

	//if we couldn't get domain then FAIL
	if(!strlen($domain)) return false;

	//remove immutable flag
	$keep=`find /home/{$name} -type f -exec chattr -i {} \;`;
	$keep=`find /home/{$name} -type d -exec chattr -i {} \;`;

	//delete linux user & associated files
	$keep=`/usr/sbin/userdel -f -r {$name} > /dev/null 2>&1`;
	if(strpos($keep,'is currently used by process')!==FALSE){
		echo "Site could not be deleted because it is used by some process!\n";
	}

	//delete mysql user & db
	$keep.=`mysql --execute="DROP DATABASE IF EXISTS {$name};"`;

	$keep.=`mysql --execute="DROP USER '{$name}'@'localhost';"`;

	//delete nginx or apache2 site
	if($settings['.web'] == 'nginx'){
		$keep.=`rm -rf /etc/nginx/sites-available/{$name}`;
		$keep.=`rm -rf /etc/nginx/sites-enabled/{$name}`;
		$keep.=`rm -rf /etc/nginx/sites-locked/{$name}`;
		$keep.=`rm -rf /etc/nginx/sites-locked/{$name}_passwd`;
	}
	else{
		$keep.=`rm -rf /etc/apache2/sites-available/{$name}.conf`;
		$keep.=`rm -rf /etc/apache2/sites-enabled/{$name}.conf`;
		$keep.=`rm -rf /etc/apache2/sites-locked/{$name}`;
		$keep.=`rm -rf /etc/apache2/sites-locked/{$name}_passwd`;
	}

	//delete php site & locking config
	$keep.=`rm -rf /etc/php/{$settings['.php']}/fpm/pool.d/{$name}.conf`;
	$keep.=`rm -rf {$pwd}/etc/sites-locked/{$name}.conf`;

	//delete logs from nginx or apache2
	if($settings['.web'] == 'nginx'){
		$keep.=`rm -rf /var/log/nginx/access-{$name}.*`;
		$keep.=`rm -rf /var/log/nginx/error-{$name}.*`;
	}
	else{
		$keep.=`rm -rf /var/log/apache2/access-{$name}.*`;
		$keep.=`rm -rf /var/log/apache2/error-{$name}.*`;
	}

	//restart php server
	$keep.=`service php{$settings['.php']}-fpm restart`;

	//restart nginx or apache2 server
	if($settings['.web'] == 'nginx'){
		$keep.=`service nginx restart`;
	}
	else{
		$keep.=`service apache2 restart`;
	}

	//delete ssl
	$keep.=`rm -rf /etc/letsencrypt/renewal/{$domain}.conf`;
	$keep.=`rm -rf /etc/letsencrypt/live/{$domain}`;
	$keep.=`rm -rf /etc/letsencrypt/archive/{$domain}`;

	//delete backups if required
	if($backups){
		delete_site_backup($name);
	}

	if(site_is_check($name)){
		return FALSE;
	}

	return TRUE;
}

function list_sshkeys(){
	global $f_settings;
	$settings=read_settings($f_settings);

	$keys=$settings['.ssh'];
	echo "SSH key name\n";
	echo "-----------------------------------------------------------------------------------\n";
	foreach($keys as $fk => $fv){
		echo "- {$fk}\n";
	}
}

function list_sites(){
	global $f_settings;
	$settings=read_settings($f_settings);

	$list=read_list();
	ksort($list);
	$a2ensites='/etc/'.($settings['.web'] == 'nginx'?'nginx':'apache2').'/sites-enabled/';
	$a2ensites_conf=($settings['.web'] == 'nginx'?'':'.conf');

	echo "Name\t\t\t\tActive\tSSL\tBackup\tDomain\n";
	foreach($list as $lk => $lv){
		$t=4-floor(strlen($lk)/8);
		$tt='';
		for($i=0;$i<$t;$i++){
			$tt.="\t";
		}

		echo "-----------------------------------------------------------------------------------\n";
		echo "{$lk}{$tt}".(is_file($a2ensites.$lk.$a2ensites_conf)?"Yes":"No")."\t".(is_dir('/etc/letsencrypt/live/'.$lv['domain'])?"Yes":"No")."\t".($lv['backups']?"Yes":"No")."\t{$lv['domain']}\n";
	}
}

function delete_site_backup($name){
	global $f_settings;
	$settings=read_settings($f_settings);

	$keep=`rm -rf {$settings['.backup_path']}/{$name}-daily_*`;
	$keep.=`rm -rf {$settings['.backup_path']}/{$name}-user_*`;
	$keep.=`rm -rf {$settings['.backup_path']}/{$name}-weekly_*`;
}

?>
