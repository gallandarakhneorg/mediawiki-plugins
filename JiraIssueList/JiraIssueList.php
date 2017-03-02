<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

/**
 * CONFIGURATION 
 * These variables may be overridden in LocalSettings.php after you include the
 * extension file.
 */

// URL of the JIRA server
$jiraHost = 'http://localhost';

// Login for JIRA
$jiraUser = '';

// Password for JIRA
$jiraPass = '';

/** REGISTRATION */
$wgExtensionFunctions[] = 'wfSetupJiraIssueList';
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'JiraIssueList',
	'version' => '1.0',
	'url' => 'http://wwww.mediawiki.org/wiki/Extension:JiraIssueList',
	'author' => array('[[:Wikipedia:User:sgalland-arakhne|Stéphane GALLAND]]', 'River Tarnell'),
	'descriptionmsg' => 'jiraissuelist_desc',
);

$wgAutoloadClasses['ExtJiraIssueList'] = dirname(__FILE__).'/JiraIssueList.class.php';
$wgExtensionMessagesFiles['JiraIssueList'] = dirname(__FILE__) . '/JiraIssueList.i18n.php';

function wfSetupJiraIssueList() {
	global $wgJiraIssueListHookStub, $wgHooks, $wgParser;

	$wgJiraIssueListHookStub = new JiraIssueList_HookStub;

	$wgHooks['LanguageGetMagic'][] = array( &$wgJiraIssueListHookStub, 'getMagicWords' );
	$wgHooks['ParserFirstCallInit'][] = array( &$wgJiraIssueListHookStub, 'registerParser' );
	$wgHooks['ParserClearState'][] = array( &$wgJiraIssueListHookStub, 'clearState' );
}

/**
 * Stub class to defer loading of the bulk of the code until a function is
 * actually used.
 */
class JiraIssueList_HookStub {
	var $realObj = null;
	var $jilMagicWords = null;

	public function registerParser( $parser ) {
		require( dirname(__FILE__) . '/JiraIssueList.mapping.magic.php');
		foreach($tagMapping as $magicWord => $phpFunction) {
			$parser->setHook( $magicWord, array( &$this, $phpFunction ) );
		}
		foreach($functionMapping as $magicWord => $phpFunction) {
			$parser->setFunctionHook( $magicWord, array( &$this, $phpFunction ) );
                }
		return true;
	}

	/** Replies magic word for given language.
	 */
	public function getMagicWords( &$globalMagicWords, $langCode = 'en' ) {
		if ( is_null( $this->jilMagicWords ) ) {
			$magicWords = array();
			require( dirname( __FILE__ ) . '/JiraIssueList.i18n.magic.php' );

			if (array_key_exists($langCode, $magicWords)) {
				$this->jilMagicWords = $magicWords[$langCode];
			}
			else {
				$this->jilMagicWords = $magicWords['en'];
			}
		}

		foreach($this->jilMagicWords as $word => $language) {
			$globalMagicWords[$word] = $language;
		}
		return true;
	}

	/** Defer ParserClearState */
	public function clearState( $parser ) {
		if ( !is_null( $this->realObj ) ) {
			$this->realObj->clearState( $parser );
		}
		$this->jilMagicWords = null;
		return true;
	}

	/** Pass through function call */
	public function __call( $name, $args ) {
		if ( is_null( $this->realObj ) ) {
			$this->realObj = new ExtJiraIssueList;
			$this->realObj->clearState( $args[0] );
		}
		return call_user_func_array( array( $this->realObj, "$name" ), $args );
	}
}

?>
