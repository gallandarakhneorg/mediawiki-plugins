<?php

class SvnUtil {

	private static $cache = array();

	private static function joinUrl() {
		$params = func_get_args();
		$nparams = func_num_args();
		$t = '';
		for($i=0; $i<$nparams; $i++) {
			$p = preg_replace('/\\/+$/', '', $params[$i]);
			if ($p) {
				if ($t) $t .= '/';
				$t .= $p;
			}
		}
		return $t; 
	}

	private static function auth($project)
	{
		global $wgSvnRepoAuth;
		$login = '';
		$pwd = '';
		if (array_key_exists($project, $wgSvnRepoAuth)) {
			$s = $wgSvnRepoAuth[$project];
			if ($s && preg_match("/^\\s*([^\\s]+)(?:\\s+(.*?))\\s*\$/", $s, $matches)) {
				$login = $matches[1];
				$pwd = $matches[2];
			}
		}
		return array('login' => $login, 'password' => $pwd);
	}

	private static function runSvn($repository, $verbose=false, $login='', $password='', $svnOptions='')
	{
		if ($verbose) {
			$verbose = ' -v';
		}
		if ($login) {
			$auth = " --no-auth-cache --username $login --password '$password'";
		}
		else {
			$auth = '';
		}
		$cmd = escapeshellcmd("svn log --non-interactive --stop-on-copy$verbose$auth $svnOptions $repository");

		$handle = popen($cmd, 'r');
		if ($handle) {
			$content = '';
			while (!feof($handle)) {
				$read = fread($handle, 2096);
				if ($read) {
					$content .= $read;
				}
			}
			pclose($handle);
			return $content;
		}

		return '';
	}

	public static function getRawContent($project, $subdir='trunk', $startTag=0, $endTag=-1) {
		global $wgSvnRepoPaths;
		$cachekey = "$project@@@$subdir";
		if (array_key_exists($cachekey, self::$cache)) {
			return self::$cache[$cachekey];
		}
		$content = '';
		if (array_key_exists($project, $wgSvnRepoPaths)) {
			$url = SvnUtil::joinUrl($wgSvnRepoPaths[$project],$subdir);
			if ($url) {
				$auth = SvnUtil::auth($project);
				$svnOptions = '';
				if ($startTag>0) {
					if ($endTag>=$startTag) {
						$svnOptions = "--revision $startTag:$endTag";
					}
					else {
						$svnOptions = "--revision $startTag:HEAD";
					}
				}
				elseif ($endTag>0) {
					$svnOptions = "--revision 1:$endTag";
				}
				$content = SvnUtil::runSvn($url, true, $auth['login'], $auth['password'], $svnOptions);
				self::$cache[$cachekey] = $content;
			}
		}
		return $content;
	}

	private static function getChildren($content, $root)
	{
		$children = array();
		foreach(preg_split("/[\n\r]/", $content) as $line) {
			if (preg_match("/^   [ADMR] \\/\\Q$root\\E\\/(.+?)\\s*\$/", $line, $matches)) {
				$line = preg_replace("/\\s*\\(from .*\\)\\s*\$/", '', $matches[1]);
				if (preg_match("/^([^\\/]+)/", $line, $matches)) {
					$children[$matches[1]] = null;
				}
			}
		}
		return array_keys($children);
	}

	public static function getTaggedVersions($project)
	{
		$content = SvnUtil::getRawContent($project, 'tags');
		return SvnUtil::getChildren($content, 'tags');
	}

	public static function getBranches($project)
	{
		$content = SvnUtil::getRawContent($project, 'branches');
		return SvnUtil::getChildren($content, 'branches');
	}

	private static function getRevision($content, $wantarray=true)
	{
		$minRev = 0;
		$maxRev = 0;
		$waitRev = false;
		foreach(preg_split("/[\n\r]/", $content) as $line) {
			if ($waitRev) {
				if (preg_match("/^r([0-9]+)\\s*\\|/", $line, $matches)) {
					$r = intval($matches[1]);
					if ($minRev<=0 || $r<$minRev) $minRev = $r;
					if ($maxRev<=0 || $r>$maxRev) $maxRev = $r;
				}
				else {
					$waitRev = false;
				}
			}
			elseif (preg_match("/^\\Q--------------\\E/", $line)) {
				$waitRev = true;
			}
		}
		if ($wantarray) {
			return array($minRev,$maxRev);
		}
		else {
			return $minRev;
		}
	}

	public static function getTaggedRevision($project, $tag, $wantarray=true)
	{
		$content = SvnUtil::getRawContent($project, 'tags/'.$tag);
		return SvnUtil::getRevision($content, $wantarray);
	}

	public static function getBranchRevision($project, $branch, $wantarray=true)
	{
		$content = SvnUtil::getRawContent($project, 'branches/'.$branch);
		return SvnUtil::getRevision($content, $wantarray);
	}

	public static function getTrunkRevision($project, $wantarray=true)
	{
		$content = SvnUtil::getRawContent($project, 'trunk');
		return SvnUtil::getRevision($content, $wantarray);
	}

	public static function getChangeDetails($project, $startTag=0, $endTag=-1, $buglabel='')
	{
		$changes = array();
		$content = SvnUtil::getRawContent($project, 'trunk', $startTag, $endTag);
		$entryLine = -1;

		$revision = null;
		$fulldate = null;
		$files = array();
		$logmessage = '';

		foreach(preg_split("/[\n\r]/", $content) as $line) {
			if (preg_match("/^\\Q--------------\\E/", $line)) {
				if ($revision) {
					$changes[] = array(
						'revision' => $revision,
						'date' => $fulldate,
						'files' => $files,
						'message' => SvnUtil::formatLogMessage($logmessage, $buglabel),
					);
				}
				$revision = null;
				$fulldate = null;
				$files = array();
				$logmessage = '';
				$entryLine = 0;
			}
			elseif ($entryLine == 0) {
				// Revision | user | date | line_count
				if (preg_match("/^r([0-9]+)\\s*\\|\\s*([^\\s\\|]+)\\s*\\|\\s*(.*?)\\s*\\|\\s*(.*?)\\s*\$/", $line, $matches)) {
					$revision = $matches[1];
					$fulldate = $matches[3];
					if (preg_match("/^\\s*([0-9]+)\\-([0-9]+)\\-([0-9]+)\\s+([0-9]+)\\:([0-9]+)\\:([0-9]+)/", $fulldate, $matches)) {
						$year = $matches[1];
						$month = $matches[2];
						$day = $matches[3];
						$hour = $matches[4];
						$minute = $matches[5];
						$second = $matches[6];
						$fulldate = mktime($hour, $minute, $second, $month, $day, $year);
					}
					$entryLine = 1;
				}
				else {
					$entryLine = -1;
				}
			}
			elseif ($entryLine == 1) {
				// "Changed paths"
				if (preg_match("/^\\s*Changed\\s+paths:/i", $line)) {
					$entryLine = 2;
				}
				else {
					$entryLine = -1;
				}
			}
			elseif ($entryLine == 2) {
				// Changed filename
				if (preg_match("/^\\s+([ADMR])\\s+(\\/.*?)(?:\\s+\\(from\\s+(.*?)\\s*\\))?\\s*\$/i", $line, $matches)) {
					$files[$matches[2]] = array(
						'filename' => $matches[2],
						'mode' => $matches[1],
					);
					if (isset($matches[3])) {
						$files[$matches[2]]['from'] = $matches[3];
					}
					$entryLine = 2;
				}
				else {
					$entryLine = 3;
				}
			}
			elseif ($entryLine == 3) {
				// Log message
				$logmessage .= trim($line)."\n";
			}
		}
		if ($revision) {
			$changes[] = array(
				'revision' => $revision,
				'date' => $fulldate,
				'files' => $files,
				'message' => SvnUtil::formatLogMessage($logmessage, $buglabel),
			);
		}
		return $changes;
	}

	public static function formatLogMessage($txt, $buglabel='')
	{
		global $wgSvnRepoShowJiraIssueState;
		global $wgSvnRepoJiraIssueLink;
		$parts = preg_split("/^\\s*\\*\\s*/m", $txt);
		$txt = '';
		foreach($parts as $part) {
			$part = trim($part);
			if ($part) {
				if (preg_match("/^\\s*\\-\\s*/m", $part)) {
					$first = preg_match("/^\\s*\\-\\s*/s", $part);
					$subparts = preg_split("/^\\s*\\-\\s*/m", trim($part));
					if (!$first) {
						$subpart = trim(preg_replace("/[\n\r]+/s", ' ', $subparts[0]));
						if ($subpart) $txt .= "* ".preg_replace("/[ \t]+/s", ' ', $subpart)."\n";
						array_shift($subparts);
						$indent = '*';
					}
					else {
						$indent = '';
					}
					foreach($subparts as $subpart) {
						$subpart = trim(preg_replace("/[\n\r]+/s", ' ', $subpart));
						if ($subpart) $txt .= "*$indent ".preg_replace("/[ \t]+/s", ' ', $subpart)."\n";
					}
				}
				else {			
					$part = trim(preg_replace("/[\n\r]+/s", ' ', $part));
					if ($part) $txt .= "* ".preg_replace("/[ \t]+/s", ' ', $part)."\n";
				}
			}
		}
		if ($buglabel) {
			if ($wgSvnRepoShowJiraIssueState) {
				$lissue = '<jira>';
				$rissue = '</jira>';
			}
			elseif ($wgSvnRepoJiraIssueLink) {
				$lissue = "[[$wgSvnRepoJiraIssueLink";
				if (preg_match("/[^\\/]$/", $wgSvnRepoJiraIssueLink)) {
					$lissue .= '/';
				}
				$lissue .= '\\1 ';
				$rissue = ']]';
			}
			else {
                               $lissue = '[';
                               $rissue = ']';
			}
			if (is_array($buglabel)) {
				foreach($buglabel as $label) {
					$txt = preg_replace("/(?:\\[|(?<!\\w))(\\Q$label-\\E[0-9]+)(?:\\]|(?!\\w))/s", "$lissue\\1$rissue", $txt);
				}
			}
			else {
			}
		}
		return $txt;
	}

	public static function getChanges($project, $startTag=0, $endTag=-1, $buglabel='', $wantarray=false)
	{
		$details = SvnUtil::getChangeDetails($project, $startTag, $endTag, $buglabel);
		if ($details) {
			$changemsg = array();
			$date = 0;
			foreach($details as $detail) {
				if ($detail['date'] > $date) $date = $detail['date'];
				$lower = strtolower($detail['message']);
				if (!array_key_exists($lower, $changemsg)) {
					$changemsg[$lower] = $detail['message'];
				}
			}
			$changemsg = implode('', array_values($changemsg));
			if ($wantarray) {
				return array( $date, $changemsg);
			}
			return $changemsg;
		}
		if ($wantarray) {
			return array( 0, '');
		}
		return '';
	}

}

?>
