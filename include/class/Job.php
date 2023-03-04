<?php

class Job
{

	static function runJobs() {
		$time = date("H:i");
		_log("Job: Run job at time ".$time, 4);

		$hour = date("H");
		$min = date("i");

		if($hour == "02" && $min == "30") {
			Nodeutil::runSingleProcess("php ".ROOT."/cli/util.php check-accounts");
		}
		if($min == "15") {
			Nodeutil::runSingleProcess("php ".ROOT."/cli/util.php recalculate-masternodes");
		}
        Sync::checkLongRunning();
        Dapps::checkLongRunning();
        NodeMiner::checkLongRunning();
        Masternode::checkLongRunning();
	}



}
