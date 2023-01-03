<?php
require_once dirname(__DIR__)."/apps.inc.php";
define("PAGE", true);
define("APP_NAME", "Explorer");

$peers = Peer::getAll();

global $db;
$sql="select p.height, count(distinct p.block_id) as block_cnt
from peers p
group by p.height, p.block_id
having block_cnt > 1
order by p.height desc";
$forked_peers = $db->run($sql);

$sql="select p.height, count(p.id) as peer_cnt
from peers p
where p.blacklisted < UNIX_TIMESTAMP()
group by p.height
order by p.height desc;";
$peers_by_height = $db->run($sql);

$sql="select p.version, count(p.id) as peer_cnt
from peers p
where p.blacklisted < UNIX_TIMESTAMP()
group by p.version
order by p.version desc";
$peers_by_version = $db->run($sql);
?>

<?php
require_once __DIR__. '/../common/include/top.php';
?>

<ol class="breadcrumb m-0 ps-0 h4">
    <li class="breadcrumb-item"><a href="/apps/explorer">Explorer</a></li>
    <li class="breadcrumb-item active">Peers</li>
</ol>

<h3>Peers <span class="float-end badge bg-primary"><?php echo count($peers) ?></span> </h3>

<div class="table-responsive">
    <table class="table table-sm table-striped">
        <thead class="table-light">
            <tr>
                <th>Hostname</th>
                <th>Ip</th>
                <th>Ping</th>
                <th>Height</th>
                <th>Version</th>
                <?php if(FEATURE_APPS) { ?>
                    <th>Apps hash</th>
                <?php } ?>
                <th>Score</th>
            </tr>
        </thead>
        <tbody>
            <?php
                $blacklisted_cnt = 0;
                foreach($peers as $peer) {
                $blacklisted = $peer['blacklisted'] > time();
                if($blacklisted) {
	                $blacklisted_cnt++;
                    if(!isset($_GET['show_all'])) {
                    continue;
                }
                }
                $color = '';
                $latest_version = version_compare($peer['version'], VERSION.".".BUILD_VERSION) >= 0;
                $blocked_version = version_compare($peer['version'], MIN_VERSION) < 0;
                $color = $latest_version ? 'success' : ($blocked_version ? 'danger' : '');
                ?>
                <tr class="<?php if($blacklisted) { ?>bg-danger<?php } ?>">
                    <td>
                        <a href="/apps/explorer/peer.php?id=<?php echo $peer['id'] ?>"><?php echo $peer['hostname'] ?></a>
                        <a href="<?php echo $peer['hostname'] ?>" target="_blank" class="float-end"
                           data-bs-toggle="tooltip" data-bs-placement="top" title="Open in new window">
                            <span class="fa fa-external-link-alt"></span>
                        </a>
                    </td>
                    <td><?php echo $peer['ip'] ?></td>
                    <td><?php echo display_date($peer['ping']) ?></td>
                    <td><?php echo $peer['height'] ?></td>
                    <td>
                        <span class="<?php if (!empty($color)) { ?>text-<?php echo $color ?><?php } ?>"><?php echo $peer['version'] ?></span>
                    </td>
	                <?php if(FEATURE_APPS) { ?>
                        <td class="">
                            <?php if($peer['appshash']) { ?>
                                <?php echo truncate_hash($peer['appshash']) ?>
                                <div class="app-hash">
                                    <?php echo hashimg($peer['appshash'], "Apps hash: ". $peer['appshash']) ?>
                                </div>
                            <?php } ?>
                        </td>
	                <?php } ?>
                    <td>
                        <?php if ($peer['score']) { ?>
                            <div class="ns">
                                <div class="progress progress-lg node-score me-1">
                                    <div class="progress-bar bg-<?php echo ($peer['score'] < MIN_NODE_SCORE / 2 ? 'danger' : ($peer['score'] < MIN_NODE_SCORE ? 'warning' : 'success')) ?>" role="progressbar" style="width: <?php echo $peer['score'] ?>%;" aria-valuenow="<?php echo $peer['score'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                            <?php echo round($peer['score'],2) ?>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<?php if ($blacklisted_cnt> 0) { ?>
    <div><?php echo $blacklisted_cnt ?> blacklisted</div>
<?php } ?>
<div>Node score: <?php echo round($_config['node_score'],2); ?>%</div>

<hr/>
<div class="row">
    <div class="col-4">
        <h4>Forked peers</h4>
        <table class="table table-sm table-striped">
            <thead class="table-light">
            <tr>
                <th>Height</th>
                <th>Different Blocks</th>
            </tr>
            </thead>
            <tbody>
			<?php foreach ($forked_peers as $forked_peer) { ?>
                <tr>
                    <td><?php echo $forked_peer['height'] ?></td>
                    <td><?php echo $forked_peer['block_count'] ?></td>
                </tr>
			<?php } ?>
            </tbody>
        </table>
    </div>
    <div class="col-4">
        <h4>Peers by height</h4>
        <table class="table table-sm table-striped">
            <thead class="table-light">
            <tr>
                <th>Height</th>
                <th>Peers</th>
            </tr>
            </thead>
            <tbody>
			<?php foreach ($peers_by_height as $peer) { ?>
                <tr>
                    <td><?php echo $peer['height'] ?></td>
                    <td><?php echo $peer['peer_cnt'] ?></td>
                </tr>
			<?php } ?>
            </tbody>
        </table>
    </div>
    <div class="col-4">
        <h4>Peers by version</h4>
        <table class="table table-sm table-striped">
            <thead class="table-light">
            <tr>
                <th>Version</th>
                <th>Peers</th>
            </tr>
            </thead>
            <tbody>
			<?php foreach ($peers_by_version as $peer) { ?>
                <tr>
                    <td><?php echo $peer['version'] ?></td>
                    <td><?php echo $peer['peer_cnt'] ?></td>
                </tr>
			<?php } ?>
            </tbody>
        </table>
    </div>
</div>


<?php
require_once __DIR__ . '/../common/include/bottom.php';
?>
<style>
    .app-hash, .ns {
        display:inline-block;
    }
</style>
