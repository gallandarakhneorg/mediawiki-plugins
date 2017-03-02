<?php

require_once( dirname(__FILE__) . '/SvnUtil.php');

define("SVNCHANGES_PROTECT_PREFIX", "%%%%SVNCHANGESPROTECT%");
define("SVNCHANGES_PROTECT_SUFFIX", "%TCETORPSEGNAHCNVS%%%%");

class ExtSubversionChanges_ProtectCallBack
{
	var $protected;
	var $text;
	function __construct()
	{
		$this->protected = array();
	}
	function protect($matches)
	{
		global $svnChangesProtectionPrefix;
		global $svnChangesProtectionSuffix;
		$this->protected[] = $matches[1];
		return	SVNCHANGES_PROTECT_PREFIX.
			(count($this->protected)-1).
			SVNCHANGES_PROTECT_SUFFIX;
	}
	function unprotect($matches)
	{
		if (isset($this->protected[intval($matches[1])])) {
			$v = $this->protected[intval($matches[1])];
			return $v;
		}
		return '';
	}
}

class ExtSubversionChanges
{

	function __construct()
	{
		if ( function_exists( 'wfLoadExtensionMessages' ) ) {
			wfLoadExtensionMessages( 'SubversionChanges' );
		}
	}

	public function clearState($parser)
	{
		return true;
	}

	/**
	 * Get the marker regex. Cached.
	 */
	protected function getMarkerRegex( $parser )
	{
		if ( isset( $parser->pf_markerRegex ) ) {
			return $parser->pf_markerRegex;
		}

		wfProfileIn( __METHOD__ );

		$prefix = preg_quote( $parser->uniqPrefix(), '/' );

		// The first line represents Parser from release 1.12 forward.
		// subsequent lines are hacks to accomodate old Mediawiki versions.
		if ( defined('Parser::MARKER_SUFFIX') )
			$suffix = preg_quote( Parser::MARKER_SUFFIX, '/' );
		elseif ( isset($parser->mMarkerSuffix) )
			$suffix = preg_quote( $parser->mMarkerSuffix, '/' );
		elseif ( defined('MW_PARSER_VERSION') && 
				strcmp( MW_PARSER_VERSION, '1.6.1' ) > 0 )
			$suffix = "QINU\x07";
		else $suffix = 'QINU';
		
		$parser->pf_markerRegex = '/' .$prefix. '(?:(?!' .$suffix. ').)*' . $suffix . '/us';

		wfProfileOut( __METHOD__ );
		return $parser->pf_markerRegex;
	}

	// Removes unique markers from passed parameters, used by string functions.
	private function killMarkers ( $parser, $text )
	{
		return preg_replace( $this->getMarkerRegex( $parser ), '' , $text );
	}

	// Generates error message.  Called when no value available.
	private function noValueError() {
		global $wgContLang;
		wfLoadExtensionMessages( 'MavenRepository' );
		return '<strong class="error">' . 
			wfMsgExt( 'svnchanges_no_value',
				array( 'escape', 'parsemag', 'content' ) ) .
			'</strong>';
	}

	private function protectWiki($text)
	{
		$prots = new ExtSubversionChanges_ProtectCallBack();
		$patterns = array(
			"/(\\[.*?\\])/",
			"/((?:https?|ftp|mailto|ssh|sftp|smb|telnet)\\:[^\\s]*)/");
		foreach($patterns as $p) {
			$text = preg_replace_callback(
					$p, 
					array($prots, 'protect'),
					$text);
		}
		$prots->text = $text;
		return $prots;
	}

	private function unprotectWiki($prots)
	{
		global $svnChangesProtectionPrefix;
                global $svnChangesProtectionSuffix;
		$prots->text = preg_replace_callback(
			"/\\Q".
			SVNCHANGES_PROTECT_PREFIX.
			"\\E([0-9]+)\\Q".
			SVNCHANGES_PROTECT_SUFFIX.
			"\\E/",
			array($prots, 'unprotect'),
			$prots->text);
		$prots->protected = null;
		return $prots->text;
	}

	/**
	 * {{#svnchanges:project:branchName}}
	 * 
	 * If branchName is version number, tagged version of the specified version is parsed.
	 * If branchName is empty or equals to 'trunk', trunk branch is parsed.
         * In other cases, the branch named 'branchName' is parsed.
	 * 
	 * Reports group identifier from the given module identifier.
	 */
	public function runSvnChanges( $parser, $project = '', $branchName='trunk')
	{
		global $wgSvnRepoJiraProjectIds;
		global $wgSvnRepoShowJiraIssueState;
		wfProfileIn( __METHOD__ );

		# You can disable the cache, but, remember that
		# this'll hit svn everytime the page is read.
		# not exactly that smart resaving an article 
		# will automatically pull down the fresh status 
		# of the svn content. 
		#$parser->disableCache();
		
		# Force this page to be rendered after a delay.
		# Pass the number of seconds after which the
		# page must be rendered again.
		# 21600 seconds = 6 hours
		$parser->getOutput()->setCacheTime(time()+21600); // old version style
		#$parser->getOutput()->updateCacheExpiry(21600); // new version style

		$tagRevisions = array();
		$taggedVersions = SvnUtil::getTaggedVersions($project);
		if ($taggedVersions) {
			$revision = 0;
			foreach($taggedVersions as $tag) {
				$r = SvnUtil::getTaggedRevision($project, $tag, false);
				if ($r>$revision) $revision = $r;
				$tagRevisions[$tag] = $r;
			}
		}
		else {
			$revision = 1;
		}

		$jiraProjects = array(strtoupper($project) => null);
		if (isset($wgSvnRepoJiraProjectIds) && is_array($wgSvnRepoJiraProjectIds)) {
			foreach($wgSvnRepoJiraProjectIds as $id) {
				$jiraProjects[strtoupper($id)] = null;
			}
		}
		$jiraProjects = array_keys($jiraProjects);

		if (isset($tagRevisions[$branchName])) {
			$lastRevision = $tagRevisions[$branchName];
			$firstRevision = -1;
			foreach($tagRevisions as $tag => $r) {
				if ($tag!=$branchName &&
				    $r>$firstRevision &&
				    $r<=$lastRevision) {
					$firstRevision = $r;
				}
			}
			if ($firstRevision<1) $firstRevision = $lastRevision;
		}
		else {
			$firstRevision = $revision;
			$lastRevision = -1;
		}

		$changes = SvnUtil::getChanges($project, $firstRevision, $lastRevision, $jiraProjects, true);

		if (!$changes) {
			wfProfileOut( __METHOD__ );
			return self::noValueError();
		}

		if ($wgSvnRepoShowJiraIssueState) {
			// Protect [] and URL
			$prot = $this->protectWiki($changes[1]);
			try {
				$prot->text = $parser->recursiveTagParse($prot->text);
			}
			catch(Exception $e) {
				throw new FatalError($e);
			}
			$changes[1] = $this->unprotectWiki($prot);
		}

		if ($changes[0]>0) {
			$text = "'''".date("r", $changes[0]).":'''\n".$changes[1];
		}
		else {
			$text = $changes[1];
		}

		wfProfileOut( __METHOD__ );
		return $text;
	}

}

?>
