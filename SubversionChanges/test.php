<?php

require_once( dirname(__FILE__).'/SvnUtil.php');

global $wgSvnRepoAuth;
$wgSvnRepoAuth = array(
		'janus' => 'sgalland cgcgandalf7502',
);

global $wgSvnRepoPaths;
$wgSvnRepoPaths = array( 
		'janus' => 'https://devmas-set.utbm.fr/repository/janus',
);

$taggedVersions = SvnUtil::getTaggedVersions('janus');
var_dump($taggedVersions);

$branches = SvnUtil::getBranches('janus');
var_dump($branches);

$trev = SvnUtil::getTaggedRevision('janus', 'janus-0.1');
var_dump($trev);

$trev = SvnUtil::getTaggedRevision('janus', 'janus-0.2');
var_dump($trev);

$trev = SvnUtil::getBranchRevision('janus', 'stephane-recast-0.2');
var_dump($trev);

$trev = SvnUtil::getTrunkRevision('janus');
var_dump($trev);

$changes = SvnUtil::getChanges('janus', 0, -1, array('JANUS', 'JASIM'));
var_dump($changes);

?>
