<?php
include_once("analyticstracking.php");
require_once 'jsonRPCClient.php';

define("BIP9_TOPBITS_VERSION", 0x20000000);
define("CSV_SEGWIT_BLOCK_VERSION", 0x20000003);
define("CSV_BLOCK_VERSION", 0x20000001);
define("SEGWIT_BLOCK_VERSION", 0x20000002);
define("BLOCK_SIGNAL_INTERVAL",  8064);
define("SEGWIT_SIGNAL_START", 1145088);
define("SEGWIT_PERIOD_START", 142);
define("BLOCK_RETARGET_INTERVAL", 2016);

$litecoin = new jsonRPCClient('http://user:pass@127.0.0.1:9332/');
$blockCount = $litecoin->getblockcount();
$blockChainInfo = $litecoin->getblockchaininfo(); 

$activeSFs = GetSoftforks($blockChainInfo["softforks"], 1); 
$activeBIP9SFs = GetBIP9Softforks($blockChainInfo["bip9_softforks"], "active");
$activeSFs = array_merge($activeSFs, $activeBIP9SFs);

$pendingSFs = GetSoftforks($blockChainInfo["softforks"], 0); 
$pendingBIP9SFs = GetBIP9Softforks($blockChainInfo["bip9_softforks"], "defined|started");
$pendingSFs = array_merge($pendingSFs, $pendingBIP9SFs);

$segwitInfo = $blockChainInfo["bip9_softforks"]["segwit"]; 
$segwitActive = ($segwitInfo["status"] == "active") ? true : false; 

$blocksPerDay = (60 / 2.5) * 24;
$nextRetargetBlock = GetNextRetarget($blockCount) * BLOCK_RETARGET_INTERVAL;
$nextSignalPeriodBlock = GetNextSignalPeriod($blockCount) * BLOCK_SIGNAL_INTERVAL;
$signalPeriodStart = $nextSignalPeriodBlock - BLOCK_SIGNAL_INTERVAL;
$blocksSincePeriodStart = $blockCount - $signalPeriodStart;
$activationPeriod = ((int)GetNextSignalPeriod($blockCount) - SEGWIT_PERIOD_START);
$blockETA = ($nextRetargetBlock - $blockCount) / $blocksPerDay * 24 * 60 * 60;

$mem = new Memcached();
$mem->addServer("127.0.0.1", 11211) or die("Unable to connect to Memcached.");

if ($blocksSincePeriodStart == 0) {
	$mem->flush();
}

$blockHeight = $mem->get("blockheight");
$versions = $mem->get("versions");

if (!$versions)
	$versions = array(BIP9_TOPBITS_VERSION, CSV_SEGWIT_BLOCK_VERSION, SEGWIT_BLOCK_VERSION);

$verbose = false;

if ($blockHeight) {
	if ($blockHeight != $blockCount) {
		for ($i = $blockCount; $i > $blockHeight; $i--) {
			$blockVer = GetBlockVersion($i, $litecoin);
			if ($verbose)
				echo 'New block. Height: ' . $i . ' with block version ' . $blockVer . '.<br>';

			if (!in_array($blockVer, $versions)) {
				array_push($versions, $blockVer);
			}
			HandleBlockVer($blockVer, $mem, $verbose);
		}
		$mem->set("blockheight", $blockCount);
	} 
} else {
	$mem->set('blockheight', $blockCount);
	for ($i = $blockCount - $blocksSincePeriodStart + 1; $i <= $blockCount; $i++) {
		$blockVer = GetBlockVersion($i, $litecoin);
		
		if (!in_array($blockVer, $versions)) {
			array_push($versions, $blockVer);
		}
		HandleBlockVer($blockVer, $mem, $verbose);
	}
	$mem->set('versions', $versions);
}

if ($verbose)
	GetBlockRangeSummary($versions, $mem);

$segwitBlocks = GetBlockVersionCounter(CSV_SEGWIT_BLOCK_VERSION, $mem) + GetBlockVersionCounter(SEGWIT_BLOCK_VERSION, $mem);
$segwitPercentage = number_format($segwitBlocks / $blocksSincePeriodStart * 100 / 1, 2);
$bip9Blocks = GetBIP9Support($versions, $mem);
$csvBlocks = GetCSVSupport($versions, $mem);

$segwitSignalling = ($blockCount >= SEGWIT_SIGNAL_START) ? true : false;
$segwitStatus = $segwitInfo["status"];
$displayText = "The Segregated Witness (segwit) soft fork will start signalling on block number " . $nextRetargetBlock . ".";
if ($segwitSignalling) {
	$displayText = "The Segregated Witness (segwit) soft fork has started signalling! <br/><br/> Ask your pool to support segwit if it isn't already doing so.";
}

function HandleBlockVer($blockVer, $mem, $verbose) {
	$result = $mem->get($blockVer);
	if (!$result) {
		if ($verbose)
			echo 'Creating new blockver: ' . $blockVer . '<br>';
		$mem->set($blockVer, 1);
	} else {
		if ($verbose)
			echo 'Setting block verion: ' . $blockVer . ' count value to: ' . $result . '<br>';
		$mem->set($blockVer, $result+1);
	}
}

function GetNextRetargetETA($time) {
	$timeNew = strtotime('+' . $time . ' second', time());
	$now = new DateTime();
	$futureDate = new DateTime();
	$futureDate = DateTime::createFromFormat('U', $timeNew);
	$interval = $futureDate->diff($now);
	return $interval->format("%a days, %h hours, %i minutes");
}

function GetNextRetarget($block) {
	$iterations = 0;
	for ($i = 0; $i < $block; $i += BLOCK_RETARGET_INTERVAL) {
		$iterations++;
	}
	if (($iterations * BLOCK_RETARGET_INTERVAL) == $block) {
		$iterations++;
	}
	return $iterations;
}

function GetNextSignalPeriod($block) {
	$iterations = 0;
	for ($i = 0; $i < $block; $i += BLOCK_SIGNAL_INTERVAL) {
		$iterations++;
	}
	if (($iterations * BLOCK_SIGNAL_INTERVAL) == $block) {
		$iterations++;
	}
	return $iterations;
}

function GetBIP9Support($versions, $memcache) {
	$totalBlocks = 0;
	foreach ($versions as $version) {
		if ($version >= 536870912) {
			$totalBlocks += GetBlockVersionCounter($version, $memcache);
		}
	}
	return $totalBlocks;
}

function GetCSVSupport($versions, $memcache) {
	$totalBlocks = 0;
	foreach ($versions as $version) {
		if ($version == CSV_BLOCK_VERSION || $version == CSV_SEGWIT_BLOCK_VERSION) {
			$totalBlocks += GetBlockVersionCounter($version, $memcache);
		}
	}
	return $totalBlocks;
}

function GetBlockRangeSummary($versions, $memcache) {
	$totalBlocks = 0;
	foreach ($versions as $version) {
		$counter = GetBlockVersionCounter($version, $memcache);
		if ($counter == 0) {
			continue;
		}
		echo $counter . ' version ' . dechex($version) . ' blocks. <br>';
		$totalBlocks += $counter;
	}
	echo $totalBlocks . ' total blocks. <br>';
}

function GetBlockVersion($blockNum, $rpc) {
	$blockhash = $rpc->getblockhash($blockNum);
	$block = $rpc->getblock($blockhash);
	return $block['version'];
}

function GetBIP9Softforks($softforks, $active) {
	$result = array();
	while ($softfork = current($softforks)) {
		$key = key($softforks);
		if (strpos($active, '|') !== false) {
			$status = explode("|", $active);
			foreach ($status as $s) {
				if ($softfork["status"] == $s) {
					array_push($result, $key);
				}
			}
		}
		else 
		{
			if ($softfork["status"] == $active) {
				array_push($result, $key);
			}
		}
		next($softforks);
	}
	return $result;
}

function GetSoftforks($softforks, $active) {
	$result = array();
	foreach ($softforks as $softfork) {
		if ($softfork["enforce"]["status"] == $active) {
			array_push($result, $softfork["id"]);
		}	
	}
	return $result;
}

function FormatDate($timestamp) {
	return date('m/d/Y H:i:s', $timestamp);
}

function GetBlockVersionCounter($blockVer, $memcache) {
	return $memcache->get($blockVer);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="Litecoin Segregated Witness Website">
	<meta name="author" content="">
	<link rel="icon" href="favicon.ico">
	<title>Litecoin Segregated Witness Adoption Tracker</title>
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
	<link rel="stylesheet" href="css/flipclock.css">
	<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
	<script src="js/flipclock.js"></script>	
	<style type="text/css">
		.progress {height: 50px; margin-bottom: 0px; margin-top: 30px; }
		img { max-width:375px; height: auto; }
	</style>
</head>
<body>
	<div class="container">
		<div class="page-header" align="center">
			<h1>Is Segregated Witness Active? <b><?=$segwitActive ? "Yes!" : "No";?></b></h1>
		</div>
		<div align="center" style>
			<img src="../images/logo.png">
			<div class="progress">
				<div class="progress-bar progress-bar-info progress-bar-striped active" role="progressbar" aria-valuenow="<?=$segwitPercentage?>" aria-valuemin="0" aria-valuemax="100" style="width:<?=$segwitPercentage?>%"></div>
			</div>
			<b>
				<?php
				echo $segwitBlocks . ' out of ' . (BLOCK_SIGNAL_INTERVAL * .75) . ' blocks achieved!';
				?>
			</b>
		</div>
		<?php
		if (!$segwitSignalling)
		{ 	
			?> 
			<div class="flip-counter clock" style="display: flex; align-items: center; justify-content: center;"></div>
			<script type="text/javascript">
				var clock;
				$(document).ready(function() {
					clock = $('.clock').FlipClock(<?=$blockETA?>, {
						clockFace: 'DailyCounter',
						countdown: true
					});
				});
			</script>
			<br/>
			<?php 
		}
		?>
		<table class="table table-striped" style="margin-top: 30px">
			<tr><td><b>Activation period #<?=$activationPeriod;?> block range <?="(". BLOCK_SIGNAL_INTERVAL . ")"?></b></td><td align = "right"><?=$signalPeriodStart . " - " . $nextSignalPeriodBlock;?></td></tr>
			<tr><td><b>Current block height</b></td><td align = "right"><?=$blockCount;?></td></tr>
			<tr><td><b>Blocks mined since period start</b></td><td align = "right"><?=$blocksSincePeriodStart?></td></tr>
			<tr><td><b>Current activated soft forks</b></td><td align = "right"><?=implode(",", $activeSFs)?></td></tr>
			<tr><td><b>Current pending soft forks</b></td><td align = "right"><?=implode(",", $pendingSFs)?></td></tr>
			<tr><td><b>Next block retarget</b></td><td align = "right"><?=$nextRetargetBlock;?></td></tr>
			<tr><td><b>Blocks to mine until next retarget</b></td><td align = "right"><?=$nextRetargetBlock-$blockCount;?></td></tr>
			<tr><td><b>Next block retarget ETA</b></td><td align = "right"><?=GetNextRetargetETA($blockETA);?></td></tr>
			<tr><td><b>BIP9 miner support since activation period start</b></td><td align = "right"><?=$bip9Blocks . " (" .number_format(($bip9Blocks / $blocksSincePeriodStart * 100 / 1), 2) . "%)"; ?></td></tr>
			<tr><td><b>CSV miner support since activation period start</b></td><td align = "right"><?=$csvBlocks . " (" .number_format(($csvBlocks / $blocksSincePeriodStart * 100 / 1), 2) . "%)"; ?></td></tr>
			<tr><td><b>Segwit status </b></td><td align = "right"><?=$segwitStatus;?></td></tr>
			<tr><td><b>Segwit activation threshold </b></td><td align = "right">75%</td></tr>
			<tr><td><b>Segwit miner support</b></td><td align = "right"><?=$segwitBlocks . " (" . $segwitPercentage . "%)"; ?></td></tr>
			<tr><td><b>Segwit start time </b></td><td align = "right"><?=FormatDate($segwitInfo["startTime"]);?></td></tr>
			<tr><td><b>Segwit timeout time</b></td><td align = "right"><?=FormatDate($segwitInfo["timeout"]);?></td></tr>
		</table>
	</div>
	<div align="center">
		<h3>
			<?php
			echo $displayText;
			?>
		</h3>
		<br/>
		<img src="../images/litecoin.png" width="125px" height="125px">
	</div>
	<br/>
	<footer>
		<div class="container" align="center">
			Segwit logo designed by <a href="https://twitter.com/albertdrosphoto" rel="external" target="_blank">@albertdrosphoto</a>
		</div>
	</footer>
</body>
</html>
