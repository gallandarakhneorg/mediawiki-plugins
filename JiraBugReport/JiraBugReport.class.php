<?php

require_once(dirname(__FILE__).'/recaptchalib.php');

class ExtJiraBugReport
{

	var $recaptcha_error;

	function __construct()
	{
		if ( function_exists( 'wfLoadExtensionMessages' ) ) {
			wfLoadExtensionMessages( 'JiraBugReport' );
		}
	}

	public function clearState($parser)
	{
		$this->recaptcha_error = null;
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

	private function getParam($name,$returned='')
	{
		global $_POST;
		global $_GET;
		$value = '';
		if (isset($_GET[$name]) && $_GET[$name]) {
			$value = $_GET[$name];
		}
		if (isset($_POST[$name]) && $_POST[$name]) {
                        $value = $_POST[$name];
                }
		if ($value && $returned) {
			return preg_replace("/\\$1/s", "$value", $returned);
		}
		$value = trim($value);
		return $value;
	}

	private function isParam($name)
	{
		global $_POST;
		global $_GET;
		if (isset($_POST[$name])) return true;
		if (isset($_GET[$name])) return true;
		return false;
	}

	 /** Expand <jirabugreport>project_name</jirabugreport>
         */
        public function expandJiraBugReport( $input='', $argv='', $parser=null )
        {
		global $jiraHost, $jiraUser, $jiraPass;
		global $_SESSION;
		global $wgSessionStarted;
		wfProfileIn( __METHOD__ );

		if ($parser) $parser->disableCache();

		$jiraUrl = "$jiraHost/rpc/soap/jirasoapservice-v2?wsdl";

        	try {
                	$jiraSoap = new SoapClient($jiraUrl);
                	$auth = $jiraSoap->login($jiraUser,$jiraPass);

			if (!$this->isParam('recaptcha_response_field')) {
                        	//User has not yet been presented
				//with Captcha, show the widget.
				$sessionCode = false;
				$rightCode = false;
			}
			else {
				$sessionCode = true;
				$rightCode = $this->passCaptcha();
			}

			if ($sessionCode) {
				if (!$rightCode) {
					$errormessage = wfMsgExt(
					'jirabugreport_invalid_anti_robot',
                	               	array( 'escape', 'parsemag',
						'content' ) );
				}
				elseif (!$this->getParam('issueSummary')) {
					$errormessage = wfMsgExt(
                                        'jirabugreport_missed_summary',
                                        array( 'escape', 'parsemag',
                                                'content' ) );
				}
				elseif (!$this->getParam('issueDescription')) {
                                        $errormessage = wfMsgExt(
                                        'jirabugreport_missed_description',
                                        array( 'escape', 'parsemag',
                                                'content' ) );
                                }
				elseif (!$this->getParam('issueReporterName')) {
                                        $errormessage = wfMsgExt(
                                        'jirabugreport_missed_reporter_name',
                                        array( 'escape', 'parsemag',
                                                'content' ) );
                                }
				elseif (!$this->getParam('issueReporterEmail')) {
                                        $errormessage = wfMsgExt(
                                        'jirabugreport_missed_reporter_email',
                                        array( 'escape', 'parsemag',
                                                'content' ) );
                                }
				elseif (!preg_match("/^[^a-zA_Z0-9.-]+[@][a-zA-Z0-9.-]+\$/s", $this->getParam('issueReporterEmail'))) {
                                        $errormessage = wfMsgExt(
                                        'jirabugreport_invalid_email',
                                        array( 'escape', 'parsemag',
                                                'content' ) );
                                }
			}

			if ((!$rightCode) || ($errormessage)) {
				$out = $this->getBugReportForm($jiraSoap,$auth,$input,$argv,$parser, $errormessage);
			}
			else {
				$out = $this->registerBugReport($jiraSoap,$auth, $input,$argv,$parser);
			}

			$jiraSoap->logout($jiraUser);
        	}
        	catch(Exception $e) {
                	throw new MWException($e->getMessage());
        	}

		wfProfileOut( __METHOD__ );
		return $out;
	}

	private function registerBugReport($jiraSoap, $jiraAuth, $input='', $argv='', $parser=null)
	{
		$projectName = strtoupper(trim($input));

		$text = $this->getParam('issueDescription');
		$env = $this->getParam('issueEnvironment');
		if ($env) {
			$text .= "\n\n---------------------- ENVIRONMENT\n\n";
			$text .= $env;
		}

		$text .= "\n\n---------------------- REPORTER\n\n";
                $text .= "Name: ".$this->getParam('issueReporterName');
		$text .= "Email: ".$this->getParam('issueReporterEmail');
	
		 $jissue = array(
                        'project' => $projectName,
                        'type' => $this->getParam('issueType'),
                        'summary' => $this->getParam('issueSummary'),
                        'description' => $text,
                        'priority' => $this->getParam('issuePriority'),
                );

		$component = $this->getParam('issueComponent');
		if (preg_match("/^([^\\/]+)\\/(.*)\$/s", $component, $matches)) {
			$jissue['components'][] = array(
				'id' => $matches[1],
				'name' => $matches[2],
			);
		}

		$newIssue = $jiraSoap->createIssue($jiraAuth, $jissue);
		if ($newIssue) {
			$out = wfMsgExt(
                                        'jirabugreport_issue_submitted',
						array( 'escape', 'parsemag',
							'content' ),
					$newIssue->key );
		}
		else {
			$out = wfMsgExt(
                                        'jirabugreport_issue_not_submitted',
                                        array( 'escape', 'parsemag',
                                                'content' ) );
		}
		return $out;
	}	

	private function getBugReportForm($jiraSoap, $jiraAuth, $input='', $argv='', $parser=null, $errormessage='')
	{
		global $wgJiraBugReportHowToPage;
		global $wgJiraBugReportHelpIcon;
		global $_SERVER;
		global $recaptcha_public_key;

		$issue = array(
                        'type' => $this->getParam('issueType'),
                        'summary' => $this->getParam('issueSummary'),
                        'priority' => $this->getParam('issuePriority'),
                        'components' => $this->getParam('issueComponent'),
                        'version' => $this->getParam('issueVersion'),
                        'environment' => $this->getParam('issueEnvironment'),
                        'description' => $this->getParam('issueDescription'),
                );

                //mg_die($issue);
	
		$projectName = strtoupper(trim($input));
	
		$url = $_SERVER['REQUEST_URI'];
		$out = '';

		if ($errormessage) {
			$out .= "<center>".
				$this->error($errormessage).
				"</center>";
		}

		$out .= "<p><form method=\"post\" action=\"$url\">";
		$out .= "<table><tr><td style=\"vertical-align: top;\">*&nbsp;";
		$out .= wfMsgExt( 'jirabugreport_reporter_name',
                                array( 'escape', 'parsemag', 'content' ) );
		$svalue = $this->getParam('issueReporterName');
                if ($svalue) $svalue = " value=\"".
                        str_replace('"', '\\"', $svalue)."\"";
		$out .= "</td><td><input name=\"issueReporterName\" size=50";
		$out .= "$svalue></td></tr>";
		$out .= "<tr><td style=\"vertical-align: top;\">*&nbsp;";
                $out .= wfMsgExt( 'jirabugreport_reporter_email',
                                array( 'escape', 'parsemag', 'content' ) );
                $svalue = $this->getParam('issueReporterEmail');
                if ($svalue) $svalue = " value=\"".
                        str_replace('"', '\\"', $svalue)."\"";
                $out .= "</td><td><input name=\"issueReporterEmail\" size=50";
                $out .= "$svalue></td></tr>";
		$out .= "<tr><td style=\"vertical-align: top;\">";
		$out .= wfMsgExt( 'jirabugreport_issue_type',
                                array( 'escape', 'parsemag', 'content' ) );
		$out .= "</td><td><select name=\"issueType\">";

		$helpmessage = '';

		foreach($jiraSoap->getIssueTypes($jiraAuth) as $issueType) {
			$selected = ($this->getParam('issueType')
				== $issueType->id) ? ' selected' : '';
			$out .= "<option value=\"".
				$issueType->id.
				"\" class=\"backedselect\" style=\"background-image: url(".
				$issueType->icon.
				");\"$selected> ".
				$issueType->name.
				"</option>";
			$helpmessage .= $issueType->name.': '.
				strip_tags($issueType->description)." ";
		}

		$out .= "</select>";
		if ($wgJiraBugReportHelpIcon && $helpmessage) {
			$out .= "&nbsp;<img align=\"center\" src=\"$wgJiraBugReportHelpIcon\" title=\"$helpmessage\" />";
		}
		$out .= "</td></tr>";
		$out .= "<tr><td style=\"vertical-align: top;\">*&nbsp;";
		$out .= wfMsgExt( 'jirabugreport_issue_summary',
                                array( 'escape', 'parsemag', 'content' ) );
		$svalue = $this->getParam('issueSummary');
		if ($svalue) $svalue = " value=\"".
			str_replace('"', '\\"', $svalue)."\"";
		$out .= "</td><td><input name=\"issueSummary\" size=\"50\"".
			"$svalue></td></tr>";
		$out .= "<tr><td style=\"vertical-align: top;\">";
		$out .= wfMsgExt( 'jirabugreport_issue_priority',
                                array( 'escape', 'parsemag', 'content' ) );
		$out .= "</td><td><select name=\"issuePriority\">";

		$helpmessage = '';

		foreach($jiraSoap->getPriorities($jiraAuth) as $priority) {
			$selected = ($this->getParam('issuePriority')
                                == $priority->id) ? ' selected' : '';
			$out .= "<option value=\"".
                                $priority->id.
                                "\" class=\"backedselect\" style=\"background-image: url(".
                                $priority->icon.
                                ");\"$selected> ".
                                $priority->name.
                                "</option>";
			$helpmessage .= $priority->name.': '.
				strip_tags($priority->description).' ';
                }

		$out .= "</select>";
                if ($wgJiraBugReportHelpIcon && $helpmessage) {
                        $out .= "&nbsp;<img align=\"center\" src=\"$wgJiraBugReportHelpIcon\" title=\"$helpmessage\" />";
                }
                $out .= "</td></tr>";
		$out .= "<tr><td style=\"vertical-align: top;\">";
		$out .= wfMsgExt( 'jirabugreport_issue_component',
                                array( 'escape', 'parsemag', 'content' ) );
		$out .= "</td><td><select name=\"issueComponent\">";

		$out .= "<option value=\"-\">".
                        wfMsgExt( 'jirabugreport_unknown',
                                array( 'escape', 'parsemag', 'content' ) ).
                        "</option>";

		foreach($jiraSoap->getComponents($jiraAuth,$projectName) as $component) {
			$fname = $component->id."/".$component->name;
			$selected = ($this->getParam('issueComponent')
                                == $fname) ? ' selected' : '';
                        $out .= "<option value=\"".
                                $fname.
				"\"$selected>".
                                $component->name.
                                "</option>";
                }

		$out .= "</select></td></tr>";
		$out .= "<tr><td style=\"vertical-align: top;\">";
		$out .= wfMsgExt( 'jirabugreport_issue_affected_version',
                                array( 'escape', 'parsemag', 'content' ) );
		$out .= "</td><td><select name=\"issueVersion\">";

		$out .= "<option value=\"-\">".
			wfMsgExt( 'jirabugreport_unknown',
                                array( 'escape', 'parsemag', 'content' ) ).
			"</option>";

		foreach($jiraSoap->getVersions($jiraAuth,$projectName) as $version) {
			$selected = ($this->getParam('issueVersion')
                                == $version->name) ? ' selected' : '';
                        $out .= "<option value=\"".
                                $version->name.
                                "\"$selected>".
                                $version->name.
                                "</option>";
                }

		$out .= "</select></td></tr>";
		$out .= "<tr><td style=\"vertical-align: top;\">";
		$out .= wfMsgExt( 'jirabugreport_issue_environment',
                                array( 'escape', 'parsemag', 'content' ) );
		$out .= "</td><td><textarea name=\"issueEnvironment\" cols=50 rows=5>".
				$this->getParam('issueEnvironment').
				"</textarea><br><small>";
		$helpUrl = $this->wikiUrl(
                                "/index.php/$wgJiraBugReportHowToPage",
                                $wgJiraBugReportHowToPage,
                                $wgJiraBugReportHowToPage);
		$out .= wfMsgExt( 'jirabugreport_issue_environment_label',
                                array( 'parsemag', 'content' ),
				$helpUrl);
		$out .= "</small></td></tr>";
		$out .= "<tr><td style=\"vertical-align: top;\">*&nbsp;";
		$out .= wfMsgExt( 'jirabugreport_issue_description',
                                array( 'escape', 'parsemag', 'content' ) );
		$out .= "</td><td><textarea name=\"issueDescription\" cols=50 rows=25>".
				$this->getParam('issueDescription').
				"</textarea></td></tr>\n";
		$out .= "<tr><td></td><td>";
		$out .= "<script>var RecaptchaOptions = { tabindex:1, theme:'white' }; </script> ";

                $out .= recaptcha_get_html($recaptcha_public_key, $this->recaptcha_error);
		$out .= "</td></tr></table>";
		$out .= "<center><input type=\"submit\" value=\"";
		$out .= wfMsgExt( 'jirabugreport_submit',
                                array( 'escape', 'parsemag', 'content' ) );
		$out .= "\"> <input type=\"reset\" value=\"";
		$out .= wfMsgExt( 'jirabugreport_cancel',
                                array( 'escape', 'parsemag', 'content' ) );
		$out .= "\"></center>";
		$out .= "</form></p>";

		return $out;
	}


	/**
	 * Calls the library function recaptcha_check_answer to verify the users input.
	 * Sets $this->recaptcha_error if the user is incorrect.
         * @return boolean 
         *
         */
	private function passCaptcha()
	{
		global $recaptcha_private_key;
		global $_POST;
		$recaptcha_response = recaptcha_check_answer ($recaptcha_private_key,
							      wfGetIP (),
							      $_POST['recaptcha_challenge_field'],
							      $_POST['recaptcha_response_field']);
                if (!$recaptcha_response->is_valid) {
			$this->recaptcha_error = $recaptcha_response->error;
			return false;
                }
		$this->recaptcha_error = null;
                return true;
	}

}

?>
