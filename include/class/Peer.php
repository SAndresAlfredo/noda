<?php


class Peer
{

	public const PRELOAD_LIST = REMOTE_PEERS_LIST_URL;
	public const MINIMUM_PEERS_REQUIRED = 2;

	static function getCount($live=false) {
		global $db;
		$sql="select count(*) as cnt from peers where reserve=0 AND blacklisted < ".DB::unixTimeStamp();
		if($live) {
			$sql.=" AND ping >".DB::unixTimeStamp()."-86400";
		}
		$row = $db->row($sql);
		return $row['cnt'];
	}

	static function getCountAll() {
		global $db;
		$sql="select count(*) as cnt from peers";
		$row = $db->row($sql);
		return $row['cnt'];
	}

	static function getAll() {
		global $db;
		$sql="select * from peers";
		$rows = $db->run($sql);
		return $rows;
	}

	static function getActive($limit=100) {
		global $db;
		$sql="select * from peers WHERE  blacklisted < ".DB::unixTimeStamp()." AND reserve=0  ORDER by ".DB::random()." LIMIT :limit";
		$rows = $db->run($sql, ["limit"=>$limit]);
		return $rows;
	}

	static function delete($id) {
		global $db;
		$sql="delete from peers where id=:id";
		$db->run($sql, ["id"=>$id]);
	}

	static function validate($peer) {

		$bad_peers = ["127.", "localhost", "10.", "192.168.","172.16.","172.17.",
			"172.18.","172.19.","172.20.","172.21.","172.22.","172.23.","172.24.",
			"172.25.","172.26.","172.27.","172.28.","172.29.","172.30.","172.31."];

		$hostname = filter_var($peer, FILTER_SANITIZE_URL);

		$tpeer=str_replace(["https://","http://","//"], "", $hostname);
		foreach ($bad_peers as $bp) {
			if (strpos($tpeer, $bp)===0 && !DEVELOPMENT) {
				_log("bad peer: ", $peer);
				return false;
			}
		}

		if (!filter_var($hostname, FILTER_VALIDATE_URL) && !DEVELOPMENT) {
			return false;
		}

		return true;
	}

	static function validateIp($ip) {
		if(!DEVELOPMENT) {
			$ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
		}
		return $ip;
	}

	static function getInitialPeers() {
		global $_config;
		$arrContextOptions=array(
			"ssl"=>array(
				"verify_peer"=>!DEVELOPMENT,
				"verify_peer_name"=>!DEVELOPMENT,
			),
		);
		$list = file_get_contents(self::PRELOAD_LIST, false, stream_context_create($arrContextOptions));
		$list = explode(PHP_EOL, $list);
		$peerList = [];
		foreach ($list as $item) {
			if(strlen(trim($item))>0) {
				$peerList[]=$item;
			}
		}
		$initial_peer_list = $_config['initial_peer_list'];
		return array_unique(array_merge($peerList, $initial_peer_list));
	}

	static function deleteAll() {
		global $db;
		$db->run('delete from peers');
	}

	static function insert($ip,$hostname,$reserve) {
		global $db;
		if($db->isSqlite()) {
			$row = $db->run("select * from peers where ip=:ip",[":ip" => $ip]);
			if($row) {
				$db->run("update peers set hostname=:hostname where ip=:ip", [":ip" => $ip, ":hostname" => $hostname]);
			} else {
				$res = $db->run(
					"INSERT INTO peers 
					    (hostname, reserve, ping, ip) 
					    values  (:hostname, :reserve, ".DB::unixTimeStamp().", :ip) ",
					[":ip" => $ip, ":hostname" => $hostname, ":reserve" => $reserve]
				);
			}
		} else {
			$res = $db->run(
				"INSERT ignore INTO peers 
				    (hostname, reserve, ping, ip) 
				    values  (:hostname, :reserve, ".DB::unixTimeStamp().", :ip) 
					ON DUPLICATE KEY UPDATE hostname=:hostname2",
				[":ip" => $ip, ":hostname2" => $hostname, ":hostname" => $hostname, ":reserve" => $reserve]
			);
		}
		return $res;
	}

	static function getInfo() {
		global $_config;
		$appsHashFile = Nodeutil::getAppsHashFile();
		$appsHash = @file_get_contents($appsHashFile);
		return [
			"height" => Block::getHeight(),
			"appshash" => $appsHash,
			"score"=>$_config['node_score']
		];
	}

	static function blacklist($id, $reason = '') {
		global $db;
		_log("Blacklist peer $id reason=$reason");
		$db->run(
			"UPDATE peers SET fails=fails+1, blacklisted=".DB::unixTimeStamp()."+((fails+1)*3600), 
				blacklist_reason=:blacklist_reason WHERE id=:id",
			[":id" => $id, ':blacklist_reason'=>$reason]
		);
	}

	static function blacklistStuck($id, $reason = '') {
		global $db;
		_log("Blacklist peer stuck $id reason=$reason");
		$db->run(
			"UPDATE peers SET stuckfail=stuckfail+1, blacklisted=".DB::unixTimeStamp()."+7200,
			    blacklist_reason=:reason WHERE id=:id",
			[":id" => $id, ":reason" => $reason]
		);
	}

	static function blacklistBroken($host, $reason = '') {
		global $db;
		_log("Blacklist peer broken $host reason=$reason");
		$db->run("UPDATE peers SET blacklisted=".DB::unixTimeStamp()."+1800 
			blacklist_reason=:reason WHERE hostname=:host LIMIT 1",[':host'=>$host, ':reason'=>$reason]);
	}

	static function getPeers() {
		global $db;
		return $db->run("SELECT ip,hostname FROM peers WHERE blacklisted<".DB::unixTimeStamp()." ORDER by ".DB::random());
	}

	static function getPeersForPropagate($linear) {
		global $db;
		// broadcasting to all peers
		$ewhr = "";
		// boradcasting to only certain peers
		if ($linear == true) {
			$ewhr = " ORDER by ".DB::random()." LIMIT 5";
		}
		$r = $db->run("SELECT * FROM peers WHERE blacklisted < ".DB::unixTimeStamp()." AND reserve=0 $ewhr");
		return $r;
	}

	static function findByIp($ip) {
		global $db;
		$x = $db->row(
			"SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<".DB::unixTimeStamp()." AND ip=:ip",
			[":ip" => $ip]
		);
		return $x;
	}

	static function reserve($res) {
		global $db;
		$db->run("UPDATE peers SET reserve=0 WHERE reserve=1 AND blacklisted<".DB::unixTimeStamp()." LIMIT $res");
	}

	static function getReserved($limit) {
		global $db;
		return $db->run("SELECT * FROM peers WHERE blacklisted<".DB::unixTimeStamp()." and reserve=1 LIMIT :limit", [":limit"=>$limit]);
	}

	static function cleanBlacklist() {
		global $db;
		$db->run("UPDATE peers SET blacklisted=0, fails=0, stuckfail=0");
	}

	static function deleteDeadPeers() {
		global $db;
		$db->run("DELETE from peers WHERE fails>100 OR stuckfail>100");
	}

	static function clearStuck($id) {
		global $db;
		$db->run("UPDATE peers SET stuckfail=0 WHERE id=:id", [":id" => $id]);
	}

	static function clearFails($id) {
		global $db;
		$db->run("UPDATE peers SET fails=0 WHERE id=:id", [":id" => $id]);
	}

	static function getSingle($hostname, $ip) {
		global $db;
		return $db->single(
			"SELECT COUNT(1) FROM peers WHERE hostname=:hostname AND ip=:ip",
			[":hostname" => $hostname, ":ip" => $ip]
		);
	}

	static function deleteByIp($ip) {
		global $db;
		$db->run("DELETE FROM peers WHERE ip=:ip", [":ip" => $ip]);
	}

	static function getByIp($ip) {
		global $db;
		return $db->row("SELECT * FROM peers WHERE ip=:ip", [":ip" => $ip]);
	}

	static function updateInfo($id, $info) {
		global $db;
		$db->run("UPDATE peers SET height=:height, appshash=:appshash, score=:score WHERE id=:id",
			[":id" => $id, ':height'=>$info['height'], ':appshash'=>$info['appshash'], ':score'=>$info['score']]);
	}

	static function storePing($url) {
		$info = parse_url($url);
		$hostname = $info['host'];
		global $db;
		_log("Updating ping for peer $hostname", 4);
		$db->run("update peers set ping = ".DB::unixTimeStamp()." where hostname like :hostname",
			[ ":hostname"=>"%$hostname%"]);

	}

	public static function findByHostname($hostName)
	{
		global $db;
		$x = $db->row(
			"SELECT id,hostname FROM peers WHERE reserve=0 AND blacklisted<".DB::unixTimeStamp()." AND hostname=:hostname",
			[":hostname" => $hostName]
		);
		return $x;
	}

}
