<?php
require_once dirname(__DIR__).'/include/init.inc.php';

$hostname = $argv[1];
if(empty($hostname)) {
	_log("PeerSync: Empty hostname");
	exit;
}


$height1 = Block::getHeight();
$url = $hostname."/peer.php?q=currentBlock";
$peer_block = peer_post($url);
$start_height = min($height1, $peer_block['block']['height']);

$min_height = 1;
$max_height = $start_height;

$height=$start_height;
_log("PeerCheck: start check blocks with $hostname from height $height");
$peer = Peer::findByHostname($hostname);
while(true) {
	_log("PeerCheck: check height $height");
	$block = Block::export("", $height);
	if(!$block) {
		break;
	}

	$url = $hostname."/peer.php?q=getBlock";
	$peer_block = peer_post($url, ["height"=>$height]);

	if ($peer_block) {
		$blocks_ok = $peer_block['id'] == $block['id'];
		if ($blocks_ok) {
			_log("PeerCheck: we have same block");
			$min_height = $height;
		} else {
			_log("PeerCheck: we DO NOT HAVE SAME BLOCK");
			$invalid_height = $height;
			$max_height = $height;
		}
		if(abs($max_height - $min_height) <=1 ) {
			break;
		}
		$height =intval(($max_height - $min_height) / 2 + $min_height);
	} else {
		_log("PeerCheck: no block from peer");
		sleep(1);
	}
}

_log("Invalid height = $invalid_height");

$block = Block::export("",$invalid_height);
$url = $hostname."/peer.php?q=getBlock";
$peer_block = peer_post($url, ["height"=>$invalid_height]);

_log("my block: ".print_r($block,1));
_log("peer block: ".print_r($peer_block,1));

$res = NodeSync::compareBlocks($block, $peer_block);
$winner = null;
if($res>0) {
	_log("My block is winner");
	$my_winner = true;
	$winner = $block;
} else if ($res<0) {
	_log("Other block is winner");
	$my_winner = false;
	$winner = $peer_block;
} else {
	_log("Blocks are actually same");
}

if($winner) {
	_log("Check other peers block");
	$peers = Peer::findPeers(false, null);
	$count = count($peers);
	$total = 0;
	$failed = 0;
	$valid = 0;
	$invalid = 0;
	$counts = [];
	foreach ($peers as $ix => $peer1) {
		$total++;
		$hostname = $peer1['hostname'];
		$url = $hostname . "/peer.php?q=getBlock";
		$peer_block = peer_post($url, ["height" => $invalid_height]);
		_log("Read block from peer " . ($ix + 1) . "/$count $hostname id=".$peer_block['id']. " elapsed=".$peer_block['elapsed']);
		if (!$peer_block) {
			$failed++;
			continue;
		}
		$counts[$peer_block['id']]++;
	}

	_log("counts=".print_r($counts, 1));
	foreach ($counts as $block_id => $cnt) {
		$perc = $cnt / ($total - $failed);
		_log("block_id=$block_id perc = $perc");
		if($perc > 2/3) {
			if($block_id == $block['id']) {
				_log("My block is still winner");
				Peer::blacklist($peer['id'], "Invalid block $invalid_height");
				$url = $peer['hostname'] . "/peer.php?q=deepCheck";
				$res = peer_post($url, [], 5, $err );
				_log("Requested deep check res=".json_encode($res));
				break;
			} else if ($block_id == $peer_block['id']) {
				_log("My chain is wrong - deleting up to height $invalid_height");
				Block::delete($invalid_height);
				break;
			}
		} else {
			_log("Consensys not reached");
		}
	}
}
_log("PeerCheck: end");
