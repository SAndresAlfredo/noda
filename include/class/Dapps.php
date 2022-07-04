<?php

class Dapps extends Daemon
{

	// Daemon config
	static $name = "dapps";
	static $title = "Dapps";

	static $max_run_time = (DEVELOPMENT ? 10 : 60) * 60;
	static $run_interval = 30;

	static function isLocal($dapps_id) {
		global $_config;
		return self::isEnabled() && Account::getAddress($_config['dapps_public_key'])==$dapps_id;
	}

	static function calcDappsHash($dapps_id) {
		$cmd = "cd ".self::getDappsDir()." && tar -cf - $dapps_id --owner=0 --group=0 --sort=name --mode=744 --mtime='2020-01-01 00:00:00 UTC' | sha256sum";
		$res = shell_exec($cmd);
		$arr = explode(" ", $res);
		$appsHash = trim($arr[0]);
		_log("Executing calcAppsHash appsHash=$appsHash", 5);
		return $appsHash;
	}

	static function buildDappsArchive($dapps_id) {
		$res = shell_exec("ps uax | grep 'tar -czf tmp/dapps.tar.gz dapps/$dapps_id' | grep -v grep");
		_log("Dapps: check buildDappsArchive res=$res", 5);
		if($res) {
			_log("Dapps: buildDappsArchive running", 5);
			return false;
		} else {
			$cmd = "cd ".ROOT." && rm tmp/dapps.tar.gz";
			_log("Dapps: Delete old archive $cmd", 5);
			@shell_exec($cmd);
			$cmd = "cd ".ROOT." && tar -czf tmp/dapps.tar.gz dapps/$dapps_id --owner=0 --group=0 --sort=name --mode=744 --mtime='2020-01-01 00:00:00 UTC'";
			_log("Dapps: buildDappsArchive call process $cmd", 5);
			shell_exec($cmd);
			if (php_sapi_name() == 'cli') {
				$cmd = "cd ".ROOT." && chmod 777 tmp/dapps.tar.gz";
				_log("Dapps: cli set chmod $cmd", 5);
				@shell_exec($cmd);
			}
			return true;
		}
	}

	static function process($force = false) {
		global $_config, $db;
		_log("Dapps: start process" , 5);
		$dapps_public_key = $_config['dapps_public_key'];
		$dapps_id = Account::getAddress($dapps_public_key);
		$dapps_root_dir = self::getDappsDir();
		if(!file_exists($dapps_root_dir)) {
			_log("Dapps: dapps root folder $dapps_root_dir does not exists");
			if (php_sapi_name() == 'cli') {
				_log("Dapps: create root folder $dapps_root_dir and set permissions");
				@mkdir($dapps_root_dir);
				@chown($dapps_root_dir, "www-data");
				@chgrp($dapps_root_dir, "www-data");
			}
			return;
		}

		$dapps_folder = self::getDappsDir() . "/$dapps_id";
		if(!file_exists($dapps_folder)) {
			_log("Dapps: dapps folder $dapps_folder does not exists");
			if (php_sapi_name() == 'cli') {
				@mkdir($dapps_folder, 0777, true);
			}
			return;
		}

		$public_key = Account::publicKey($dapps_id);
		if(!$public_key) {
			_log("Dapps: Dapps $dapps_id - public key not found");
			return;
		}

		$saved_dapps_hash = $db->getConfig('dapps_hash');
		_log("Dapps: hash from db = $saved_dapps_hash", 5);
		$dapps_hash = self::calcDappsHash($dapps_id);
		$archive_built = file_exists(ROOT  . "/tmp/dapps.tar.gz");
		_log("Dapps: exists archive file = $archive_built", 5);
		if($saved_dapps_hash != $dapps_hash || $force || !$archive_built) {
			$db->setConfig("dapps_hash", $dapps_hash);
			_log("Dapps: trigger propagate");
			self::buildDappsArchive($dapps_id);
			$dir = ROOT . "/cli";
			_log("Dapps: Propagating dapps",5);
			$res = shell_exec("ps uax | grep '$dir/propagate.php dapps local' | grep -v grep");
			if($res) {
				_log("Dapps: propagate dapps running",5);
			} else {
				$cmd = "php $dir/propagate.php dapps local > /dev/null 2>&1  &";
				_log("Dapps: propagate dapps start process $cmd",5);
				system($cmd);
			}
		} else {
			_log("Dapps: not changed dapps", 5);
		}
	}

	static function propagate($id) {
		global $_config, $db;
		_log("Dapps: called propagate for $id", 5);
		$dapps_public_key = $_config['dapps_public_key'];
		$dapps_private_key = $_config['dapps_private_key'];
		$dapps_id = Account::getAddress($dapps_public_key);
		$dapps_hash = self::calcDappsHash($dapps_id);
		if($id === "local") {
			//start propagate to each peer
			$peers = Peer::getPeersForSync();
			if(count($peers)==0) {
				_log("Dapps: No peers to propagate", 5);
			} else {
				_log("Dapps: Found ".count($peers)." to propagate", 5);
				foreach ($peers as $peer) {
					self::propagateToPeer($peer);
				}
			}
		} else {
			//propagate to single peer
			$peer = base64_decode($id);
			_log("Dapps: propagating dapps to $peer pid=".getmypid(), 5);
			$url = $peer."/peer.php?q=updateDapps";
			$dapps_signature = ec_sign($dapps_hash, $dapps_private_key);
			$data = [
				"dapps_id"=>$dapps_id,
				"dapps_hash"=>$dapps_hash,
				"dapps_signature"=>$dapps_signature,
			];
			$res = peer_post($url, $data, 30, $err);
			_log("Dapps: Propagating to peer: ".$peer." data=".http_build_query($data)." res=".json_encode($res). " err=$err", $err ? 0 : 5);
		}
	}

	private static function propagateToPeer($peer) {
		$hostname = $peer['hostname'];
		$peer = base64_encode($hostname);
		$dir = ROOT."/cli";
		$res = shell_exec("ps uax | grep '$dir/propagate.php dapps $peer' | grep -v grep");
		if(!$res) {
			$cmd = "php $dir/propagate.php dapps $peer > /dev/null 2>&1  &";
			_log("Dapps: exec propagate to $hostname cmd: $cmd", 5);
			system($cmd);
		}
	}

	static function render() {

		require_once ROOT . "/include/dapps.functions.php";
		if(php_sapi_name() === 'cli') {
			return;
		}

		$url = $_GET['url'];
		if(substr($url, 0, 1)=='/') {
			$url = substr($url, 1);
		}
		$arr = explode("/", $url);
		$dapps_id = $arr[0];
		$dapps_dir = Dapps::getDappsDir();
		if(!file_exists($dapps_dir ."/". $dapps_id)) {
			_log("Dapps: Does not exists $dapps_id");
			Dapps::downloadDapps($dapps_id);
			return;
		}

		_log("Dapps: Start render dapps page $dapps_id", 5);

		$url_info = parse_url($url);
		$file = $url_info['path'];

		$file = $dapps_dir . "/" . $file;

		if(!file_exists($file)) {
			_log("Dapps: File $file not exists");
			if(!Dapps::isLocal($dapps_id)) {
				Dapps::downloadDapps($dapps_id);
			}
			return;
		}

		if(is_dir($file)) {
			_log("Dapps: File $file is dir", 5);
			$files = scandir($file);
			_log("Dapps: Files in dir ".json_encode($files), 5);
			foreach ($files as $dir_file) {
				if($dir_file == "index.html") {
					$file = $file . "/" . $dir_file;
					break;
				}
				if($dir_file == "index.php") {
					$file = $file . "/" . $dir_file;
					break;
				}
			}
		}

		if(!is_file($file)) {
			_log("Dapps: Entry $file does not exists", 5);
			return;
		}

		$file_type = mime_content_type($file);
		$file_info = pathinfo($file);
		$ext = $file_info['extension'];
		_log("Dapps: Resolve file $file content-type:" . $file_type." ext=$ext", 5);


		if($file_type != 'text/x-php' && $ext!="php") {
			_log("Dapps: file is not php: render it directly", 5);
			ob_end_clean();
			header("Content-Type: ".$file_type);
			readfile($file);
			exit;
		}


		_log("Dapps: Starting session", 5);
		$tmp_dir = ROOT."/tmp/dapps";
		@mkdir($tmp_dir);
		$sessions_dir = $tmp_dir."/sessions";
		@mkdir($sessions_dir);
		session_save_path($sessions_dir);
		session_start();
		ob_start();
		$session_id = session_id();
		_log("Dapps: Getting session_id=$session_id", 5);

		$query = $url_info['query'];
		$server_args = "";
		$_SERVER['PHP_SELF_BASE']=$url_info['path'];

		$request_uri = $_SERVER['REQUEST_URI'];
		if(substr($request_uri, 0, strlen("/dapps/")) == "/dapps/") {
			$_SERVER['REWRITE_URL'] = 1;
			$url = substr($request_uri, strlen("/dapps/" . $dapps_id));
		} else {
			$_SERVER['REWRITE_URL'] = 0;
			$url = substr($request_uri, strlen("/dapps.php?url=" . $dapps_id));
		}
		$_SERVER['DAPPS_URL']=$url;
		$_SERVER['DAPPS_NETWORK']=NETWORK;

		foreach ($_SERVER as $key=>$val) {
			$server_args.=" $key='$val' ";
		}
		$post_data = base64_encode(json_encode($_POST));
		$session_data = base64_encode(json_encode($_SESSION));

		parse_str($query, $parsed);
		foreach ($_GET as $key=>$val) {
			$parsed[$key]=$val;
		}
		$get_data = base64_encode(json_encode($parsed));

		$functions_file = ROOT . "/include/dapps.functions.php";

		$dapps_config_file = "";
		$dapps_local = 0;
		if(self::isLocal($dapps_id)) {
			$dapps_config_file = ROOT . "/config/dapps.config.inc.php";
			$dapps_local = 1;
		}

		$cmd = "$server_args GET_DATA=$get_data POST_DATA=$post_data SESSION_ID=$session_id SESSION_DATA=$session_data " .
			" DAPPS_ID=$dapps_id DAPPS_LOCAL=$dapps_local " .
			" php -d disable_functions=exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source,set_time_limit,ini_set" .
			" -d open_basedir=" . $dapps_dir . "/$dapps_id:".$tmp_dir.":".$functions_file.":".$dapps_config_file .
			" -d max_execution_time=5 -d memory_limit=128M " .
			" -d auto_prepend_file=$functions_file $file 2>&1";
		_log("Dapps: Executing dapps file cmd=$cmd", 5);

		session_write_close();

		$res = exec ($cmd, $output2);
//		_log("Dapps: Parsing output ". json_encode($output2), 5);

		ob_end_clean();
		ob_start();
		header("X-Dapps-Id: $dapps_id");

		$out = implode(PHP_EOL, $output2);
//		_log("Dapps: Parsing output $out", 5);

		if(strpos($out, "action:")===0) {
			self::processAction($out, $dapps_id);
		}

		_log("Dapps: Writing out", 5);
		echo $out;
		exit;

	}

	static function processAction($out, $dapps_id) {
		global $_config;
		$str = str_replace("action:", "", $out);
		$actionObj = json_decode($str, true);
		if($actionObj['type']=="redirect") {
			header("location: " . $actionObj['url']);
			exit;
		}
		if($actionObj['type']=="dapps_request") {
			$dapps_id = $actionObj['dapps_id'];
			$url = $actionObj['url'];
			if(substr($url, 0, 1) != "/") {
				$url = "/" . $url;
			}
			//TODO: check my dapps
			$peer = Peer::findByDappsId($dapps_id);
			$url = $peer['hostname']."/dapps.php?url=" . $dapps_id . $url;
			header("location: $url");
			exit;
		}
		if($actionObj['type']=="dapps_exec" && self::isLocal($dapps_id)) {
			$code = $actionObj['code'];
			eval($code);
			exit;
		}
	}

	static function getDappsDir() {
		return ROOT . "/dapps";
	}

	static function updateDapps($data, $ip) {
		global $_config;
		$dapps_hash = $data['dapps_hash'];
		$dapps_id = $data['dapps_id'];
		$dapps_signature = $data['dapps_signature'];
		_log("Dapps: received update dapps dapps_id=$dapps_id dapps_hash=$dapps_hash dapps_signature=$dapps_signature");

		$dapps_root_dir = self::getDappsDir();
		if(!file_exists($dapps_root_dir)) {
			api_err("Dapps: Root dapps folder $dapps_root_dir does not exists",0);
		}

		$public_key = Account::publicKey($dapps_id);
		if(!$public_key) {
			api_err("Dapps: Dapps $dapps_id - public key not found");
		}

		$calc_dapps_hash = Dapps::calcDappsHash($dapps_id);

		if($calc_dapps_hash == $dapps_hash) {
			api_echo("Dapps: No need to update dapps $dapps_id",0);
		}

		_log("Dapps: check signature with public_key = $public_key",5);
		$res = Account::checkSignature($dapps_hash, $dapps_signature, $public_key);

		if(!$res) {
			api_err("Dapps: Dapps node signature not valid",0);
		}

		$peer = Peer::findByIp($ip);
		if(!$peer) {
			api_err("Dapps: Remote peer ip=$ip not found", 0);
		}

		if(!isset($_config['dapps_anonymous']) || !$_config['dapps_anonymous']) {
			Peer::updateDappsId($ip, $dapps_id);
		}

		_log("Dapps: Request from ip=$ip peer=".$peer['hostname'], 5);

		$link = $peer['hostname']."/dapps.php?download";
		_log("Dapps: Download dapps from $link");

		$arrContextOptions=array(
			"ssl"=>array(
				"verify_peer"=>!DEVELOPMENT,
				"verify_peer_name"=>!DEVELOPMENT,
			),
		);
		$local_file = ROOT . "/tmp/dapps.$dapps_id.tar.gz";
		$res = file_put_contents($local_file, fopen($link, "r", false,  stream_context_create($arrContextOptions)));
		if($res === false) {
			api_err("Dapps: Error downloading apps from remote server", 0);
		} else {
			$size = filesize($local_file);
			if(!$size) {
				api_err("Dapps: Downloaded empty file from remote server", 0);
			} else {
				_log("Dapps: Downloaded size $size file=$local_file", 5);
				$cmd = "cd ".self::getDappsDir()." && rm -rf $dapps_id";
				shell_exec($cmd);
				$cmd = "cd ".ROOT." && tar -xzf tmp/dapps.$dapps_id.tar.gz -C . --owner=0 --group=0 --mode=744 --mtime='2020-01-01 00:00:00 UTC'";
				shell_exec($cmd);
				$cmd = "cd ".self::getDappsDir()." && find $dapps_id -type f -exec touch {} +";
				shell_exec($cmd);
				$cmd = "cd ".self::getDappsDir()." && find $dapps_id -type d -exec touch {} +";
				shell_exec($cmd);
				if (php_sapi_name() == 'cli') {
					$cmd = "cd ".self::getDappsDir()." && chown -R www-data:www-data $dapps_id";
					shell_exec($cmd);
				}
				$new_dapps_hash = Dapps::calcDappsHash($dapps_id);
				_log("Dapps: new_dapps_hash=$new_dapps_hash", 5);
				if($new_dapps_hash != $dapps_hash) {
					api_err("Dapps: Error updating dapps $dapps_id new_dapps_hash=$new_dapps_hash dapps_hash=$dapps_hash", 0);
				} else {
					api_echo("Dapps: OK");
				}
			}
		}

	}

	public static function download()
	{
		_log("Dapps: called download");
		if(!Dapps::isEnabled()) {
			exit;
		}

		$file = ROOT . "/tmp/dapps.tar.gz";
		if(!file_exists($file)) {
			_log("Dapps: File $file not exists");
			header("HTTP/1.0 404 Not Found");
			exit;
		}

		header('Content-Type: application/octet-stream');
		header("Content-Transfer-Encoding: Binary");
		header("Content-disposition: attachment; filename=\"" . basename($file) . "\"");
		readfile($file);
		exit;
	}

	public static function downloadDapps($dapps_id)
	{
		$peers = Peer::getPeersForSync();
		if(count($peers)==0) {
			_log("Dapps: No peers to update dapps $dapps_id");
		} else {
			_log("Dapps: Found ".count($peers)." to ask for update dapps $dapps_id", 5);
			foreach ($peers as $peer) {
				$peer = base64_encode($peer['hostname']);
				$dir = ROOT."/cli";
				$res = shell_exec("ps uax | grep '$dir/propagate.php dapps-update $peer $dapps_id' | grep -v grep");
				if(!$res) {
					$cmd = "php $dir/propagate.php dapps-update $peer $dapps_id > /dev/null 2>&1  &";
					_log("Dapps: exec propagate cmd: $cmd");
					system($cmd);
				}

			}
		}

	}

	public static function propagateDappsUpdate($hash, $id)
	{
		$hostname = base64_decode($hash);
		_log("Dapps: called propagate update apps id=$id to host=$hostname");
		$url = $hostname."/peer.php?q=checkDapps";
		$res = peer_post($url, ["dapps_id"=>$id], 30, $err);
		_log("Dapps: response $res err=$err");
	}

	static function checkDapps($dapps_id, $ip) {
		global $_config;
		_log("Dapps: received request to check dapps $dapps_id from peer $ip");
		if(!self::isEnabled()) {
			api_err("Dapps: this server is not hosting dapps");
		}
		$dapps_public_key = $_config['dapps_public_key'];
		$local_dapps_id = Account::getAddress($dapps_public_key);
		if($local_dapps_id != $dapps_id) {
			api_err("Dapps: this server is not host for dapps id = $dapps_id");
		}
		$peer = Peer::findByIp($ip);
		if(!$peer) {
			api_err("Dapps: can not find peer with ip=$ip");
		} else {
			_log("Dapps: propagate dapps to ".$peer['hostname']);
		}
		self::propagateToPeer($peer);
		api_echo("OK");
	}

}
