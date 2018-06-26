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

// Name of the page which has bug reporting how to.
$wgJiraBugReportHowToPage = '';

// URL of the help icon
$wgJiraBugReportHelpIcon = '';


/** REGISTRATION */
$wgExtensionFunctions[] = 'wfSetupJiraBugReport';
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'JiraBugReport',
	'version' => '1.0',
	'url' => 'http://www.mediawiki.org/wiki/Extension:JiraBugReport',
	'author' => array('[[:Wikipedia:User:sgalland-arakhne|Stéphane GALLAND]]'),
	'descriptionmsg' => 'jirabugreport_desc',
);

$wgAutoloadClasses['ExtJiraBugReport'] = dirname(__FILE__).'/JiraBugReport.class.php';
$wgExtensionMessagesFiles['JiraBugReport'] = dirname(__FILE__) . '/JiraBugReport.i18n.php';

function wfSetupJiraBugReport() {
	global $wgJiraBugReportHookStub, $wgHooks, $wgParser;

	$wgJiraBugReportHookStub = new JiraBugReport_HookStub;

	$wgHooks['LanguageGetMagic'][] = array( &$wgJiraBugReportHookStub, 'getMagicWords' );
	$wgHooks['ParserFirstCallInit'][] = array( &$wgJiraBugReportHookStub, 'registerParser' );
	$wgHooks['ParserClearState'][] = array( &$wgJiraBugReportHookStub, 'clearState' );
}

/**
 * Stub class to defer loading of the bulk of the code until a function is
 * actually used.
 */
class JiraBugReport_HookStub {
	var $realObj = null;
	var $jbrMagicWords = null;

	public function registerParser( $parser ) {
		require( dirname(__FILE__) . '/JiraBugReport.mapping.magic.php');
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
		if ( is_null( $this->jbrMagicWords ) ) {
			$magicWords = array();
			require( dirname( __FILE__ ) . '/JiraBugReport.i18n.magic.php' );

			if (array_key_exists($langCode, $magicWords)) {
				$this->jbrMagicWords = $magicWords[$langCode];
			}
			else {
				$this->jbrMagicWords = $magicWords['en'];
			}
		}

		foreach($this->jbrMagicWords as $word => $language) {
			$globalMagicWords[$word] = $language;
		}
		return true;
	}

	/** Defer ParserClearState */
	public function clearState( $parser ) {
		if ( !is_null( $this->realObj ) ) {
			$this->realObj->clearState( $parser );
		}
		$this->jbrMagicWords = null;
		return true;
	}

	/** Pass through function call */
	public function __call( $name, $args ) {
		if ( is_null( $this->realObj ) ) {
			$this->realObj = new ExtJiraBugReport;
			$this->realObj->clearState( $args[0] );
		}
		return call_user_func_array( array( $this->realObj, "$name" ), $args );
	}
}

?>
