<?php

/********* ENSURE DEPENDENCIES
 */

function jiraIssueListSorter($a,$b,$ascendent=true)
{
	if (preg_match("/^([^-]+)\\-([0-9]+)\$/", $a->key, $matches)) {
		$name1 = $matches[1];
		$nb1 = intval($matches[2]);
	}
	else {
		return -1;
	}
	if (preg_match("/^([^-]+)\\-([0-9]+)\$/", $b->key, $matches)) {
                $name2 = $matches[1];
                $nb2 = intval($matches[2]);
        }
        else {
                return 1;
        }
	$cmp = strcasecmp($name1,$name2);
	if ($cmp!=0) return $ascendent ? $cmp : -$cmp;
	return $ascendent ? ($nb1 - $nb2) : ($nb2 - $nb1);
}

class ExtJiraIssueList
{
	var $dependencies = array('CollapsableText');
	var $dependencyCheck;

	function __construct()
	{
		$this->dependencyCheck = false;
		if ( function_exists( 'wfLoadExtensionMessages' ) ) {
			wfLoadExtensionMessages( 'JiraIssueList' );
		}
	}

	/** Ensure the given extension was defined.
	 * @return the local file of the extension or null
	 */
	protected function ensureExtensionLoaded($name)
	{
		global $wgExtensionCredits;
		foreach($wgExtensionCredits['parserhook'] as $hook) {
			if ($name == $hook['name']) {
				return $hook['path'];
			}
		}
		return null; 
	}

	protected function ensureExtensionsLoaded() {
		if (!$this->dependencyCheck) {
			foreach($this->dependencies as $name) {
				if (!$this->ensureExtensionLoaded($name)) {
					throw new MWException(
					"MediaWiki extension '$name' is mandatory".
					" and must be enabled");
				}
			}
			$this->dependencyCheck = true;
		}
	}

	private function forceTempCache($parser)
        {
                # Force this page to be rendered after a delay.
                # Pass the number of seconds after which the
                # page must be rendered again.
                # 21600 seconds = 6 hours
                $parser->getOutput()->setCacheTime(time()+21600); // old version style
                #$parser->getOutput()->updateCacheExpiry(21600); // new version style

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

        // Generates Error message
	private function error($message)
	{
		return "<strong class=\"error\">$message</strong>"; 
	}

	private function wikiUrl($url, $text='', $title='')
        {
                if (!$text) {
                        if (!$title) $text = $title;
                        else $text = $url;
                }
                if (!$title) $title = $url;
                return "<a href=\"".trim($url)."\" title=\"".
                           htmlspecialchars(trim($title)).
                           "\" class=\"external text\" ".
                           "rel=\"nofollow\">".trim($text)."</a>";

        }

	/** Expand <jiraissuelist ...> project_name,project_name... </jiraissuelist>
	 *
	 * search="text" : restrict issues to whose matching the text.
	 *
	 * max="number" : restrict of number of outputs to the given number.
	 *
	 * offset="number" : start to display issues form the given offset.
	 *
	 * ascendent="true|false" : sort the issues.
         */
        public function expandJiraIssueList( $input='', $argv='', $parser=null )
        {
		global $jiraHost, $jiraUser, $jiraPass;

		wfProfileIn( __METHOD__ );

		$this->ensureExtensionsLoaded();

		$this->forceTempCache($parser);

		$jiraUrl = "$jiraHost/rpc/soap/jirasoapservice-v2?wsdl";

		try {
                        $jiraSoap = new SoapClient($jiraUrl);
                        $auth = $jiraSoap->login($jiraUser,$jiraPass);

			$out = '';

			$projectNames = array_map('strtoupper',
				array_map('trim',
				preg_split("/\\s*,\\s*/",$input)));

			$searchText = $argv['search'];
			if (!isset($searchText) || !$searchText) {
				$searchText = array();
				foreach(range('a','z') as $l) {
					$searchText[] = "$l*";
				}
				$searchText = implode(' || ', $searchText);
			}
			$max = intval($argv['max']);
			if ($max<=0) $max = 100000; 
			$offset = intval($argv['offset']);
			if ($offset<=0) $offset = 0;
			$ascendent = strtolower(trim($argv['ascendent']));
			if ($ascendent != 'true' && $ascendent != 'false')
				$ascendent = true;
			else
				$ascendent = ($ascendent != 'false'); 

			$issues = $jiraSoap->getIssuesFromTextSearchWithProject($auth, $projectNames, $searchText, $max);	

			$types = $jiraSoap->getIssueTypes($auth);
			$statuses = $jiraSoap->getStatuses($auth);
			$priorities = $jiraSoap->getPriorities($auth);

			$out = $this->formatTable($issues,$types,$statuses,$priorities, $ascendent,$parser);

			$jiraSoap->logout($jiraUser);
		}
                catch(Exception $e) {
                        throw new MWException($e->getMessage());
                }

		wfProfileOut( __METHOD__ );
		return $out;
	}

	private function formatTable($issues,$types,$statuses,$priorities,$ascendent=true,$parser=null)
	{
		$out = '';//"<p>".count($issues)." issues</p>";
		$out .= "<table class=\"frametable\">";
		$out .= "<thead><tr>";
		$out .= "<th>T</th><th>Key</th><th>Summary</th><th>Pr</th><th>Status</th>";
		$out .= "</tr></thead><tbody>";

		if ($ascendent) {
			$sortFct = create_function('$a,$b',
                                'return jiraIssueListSorter($a,$b,true);');
		}
		else {
			$sortFct = create_function('$a,$b',
                                'return jiraIssueListSorter($a,$b,false);');
		}

		uasort($issues, $sortFct); 

		foreach($issues as $issue) {
			$style = $this->isClosedIssue($issue)
				? 'closedissue'
				: 'openedissue';

			$summary = $issue->summary;
			$description = $issue->description;

			if ($parser) {
				$issueDescription = "<collapsetext>";
				$issueDescription .= $summary;
				$issueDescription .= "<collapse>";
				$issueDescription .= $description;
				$issueDescription .= "</collapsetext>";
				$issueDescription =
					$parser->recursiveTagParse($issueDescription);
			}
			else {
				$issueDescription = $summary;
			}

			$out .= "<tr class=\"$style\">";
			$out .= "<td class=\"$style\"><img src=\"".
				$this->getIssueTypeIcon($issue).
				"\" title=\"".
				$this->getIssueTypeLabel($issue,$types).
				"\"></td>";
			$out .= "<td class=\"$style\">".
				$this->wikiUrl(
					$this->getIssueURL($issue),
					$this->getIssueLabel($issue),
					$this->getIssueURL($issue)).
				"</td>";
			$out .= "<td class=\"$style\">".
				$issueDescription.
				"</td>";
			$out .= "<td class=\"$style\"><img src=\"".
				$this->getIssuePriorityIcon($issue).
                                "\" title=\"".
				$this->getIssuePriorityLabel($issue,$priorities).
				"\" alt=\"".
				$this->getIssuePriorityLabel($issue,$priorities).
				"\"></td>";
			$out .= "<td class=\"$style\"><img src=\"".
				$this->getIssueStatusIcon($issue).
                                "\" title=\"".
				$this->getIssueStatusLabel($issue,$statuses).
				"\" alt=\"".
				$this->getIssueStatusLabel($issue,$statuses).
				"\"></td>";
			$out .= "</tr>";
		}

		$out .= "</tbody></table>";
		return $out;
	}

	private function getIssueURL($issue)
	{
		global $jiraHost;
		return "$jiraHost/browse/".$issue->key;
	}

	private function isClosedIssue($issue)
	{
		return $issue->status == 5 || $issue->status==6;
	}

	private function getIssueStatusIcon($issue)
	{
		global $jiraHost;
		$url = "$jiraHost/images/icons/status_";
		switch($issue->status){
                case 1:
                        $url .= 'open';
                        break;
                case 3:
                        $url .= 'inprogress';
                        break;
                case 4:
                        $url .= 'reopened';
                        break;
                case 5:
                        $url .= 'resolved';
                        break;
                case 6:
                        $url .= 'closed';
                        break;
                default:
			$url .= $issue->status;
                        break;
        	}
		$url .= '.gif';
		return $url;

	}

	private function getIssueStatusLabel($issue,$statuses)
        {
		foreach($statuses as $status) {
			if ($status->id == $issue->status) {
				return preg_replace("/\\s+/s", "&nbsp;", $status->name);
			}
		}
		return '-';
        }

	private function getIssueTypeIcon($issue)
	{
		global $jiraHost;
                $url = "$jiraHost/images/icons/";
		switch($issue->type) {
		case 1:
                        $url .= 'bug';
                        break;
		case 2:
			$url .= 'newfeature';
			break;
		case 3:
			$url .= 'task';
			break;
		case 4:
			$url .= 'improvement';
			break;
		default:
			$url .= $issue->type;
			break;
		}
		$url .= ".gif";
		return $url;
	}

	private function getIssueTypeLabel($issue,$types)
        {
                foreach($types as $type) {
                        if ($type->id == $issue->type) {
                                return preg_replace("/\\s+/s", "&nbsp;", $type->name);
                        }
                }
                return '-';
        }

	private function getIssueLabel($issue)
        {
                $issuelabel = $issue->key;
                switch($issue->status){
		case 5:
                        $issuelabel = $issuelabel;//"<s>$issuelabel</s>";
                        break;
                case 6:
                        $issuelabel = $issuelabel;//"<s>$issuelabel</s>";
                        break;
                default:
                        break;
                }
                return $issuelabel;
        }

	private function getIssuePriorityIcon($issue)
	{
		global $jiraHost;
                $url = "$jiraHost/images/icons/priority_";
		switch($issue->priority){
                case 1:
                        $url .= 'blocker';
                        break;
                case 2:
                        $url .= 'critical';
                        break;
                case 3:
                        $url .= 'major';
                        break;
                case 4:
                        $url .= 'minor';
                        break;
                case 5:
                        $url .= 'trivial';
                        break;
                default:
                        $url .= $issue->priority;
                        break;
	        }
		$url .= ".gif";
		return $url;
	}

	private function getIssuePriorityLabel($issue,$priorities)
        {
                foreach($priorities as $priority) {
                        if ($priority->id == $issue->priority) {
                                return preg_replace("/\\s+/s", "&nbsp;", $priority->name);
                        }
                }
                return '-';
        }

}

?>
