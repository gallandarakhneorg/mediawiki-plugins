<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}


/**
 * CONFIGURATION 
 * These variables may be overridden in LocalSettings.php after you include the
 * extension file.
 */

/**
 * Defines the paths to the SVN repositories
 * It is an associative array of (software_id => repository_url).
 */
$wgSvnRepoPaths = array();

/**
 * Defines the authentification informations to the SVN repositories
 * It is an associative array of (software_id => "login password").
 */
$wgSvnRepoAuth = array();

/** Indicates if the [xxx] strings should be parsed to
 *  detect Jira issues.
 */
$wgSvnRepoShowJiraIssueState = false;

/** Indicates the URL where an Jira issue is accessible
 */
$wgSvnRepoJiraIssueLink = '';

/**
 * Defines the identifiers for the projects on JIRA.
 * These identifiers will be automatically detected
 * and enclosed by &lt;jira /&gt; HTML tag.
 * By default, the project id passed to the parser functions
 * is recognized.
 */
$wgSvnRepoJiraProjectIds = array();

/** REGISTRATION */
$wgExtensionFunctions[] = 'wfSetupSubversionChanges';
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'SubversionChanges',
	'version' => '1.2',
	'url' => 'http://www.mediawiki.org/wiki/Extension:SubversionChanges',
	'author' => array('[[:Wikipedia:User:sgalland-arakhne|Stéphane GALLAND]]'),
	'descriptionmsg' => 'svnchanges_desc',
);

$wgAutoloadClasses['ExtSubversionChanges'] = dirname(__FILE__).'/SubversionChanges.class.php';
$wgExtensionMessagesFiles['SubversionChanges'] = dirname(__FILE__) . '/SubversionChanges.i18n.php';

function wfSetupSubversionChanges() {
	global $wgSvnChangesHookStub, $wgHooks;

	$wgSvnChangesHookStub = new SubversionChanges_HookStub;

	$wgHooks['LanguageGetMagic'][] = array( &$wgSvnChangesHookStub, 'getMagicWords' );
	$wgHooks['ParserFirstCallInit'][] = array( &$wgSvnChangesHookStub, 'registerParser' );
	$wgHooks['ParserClearState'][] = array( &$wgSvnChangesHookStub, 'clearState' );
}

/**
 * Stub class to defer loading of the bulk of the code until a mvn function is
 * actually used.
 */
class SubversionChanges_HookStub {
	var $realObj = null;
	var $svnMagicWords = null;

	public function registerParser( $parser ) {
		require( dirname(__FILE__) . '/SubversionChanges.mapping.magic.php');
		foreach($mapping as $magicWord => $phpFunction) {
			$parser->setFunctionHook( $magicWord, array( &$this, $phpFunction ) );
		}
		return true;
	}

	/** Replies magic word for given language.
	 */
	public function getMagicWords( &$globalMagicWords, $langCode = 'en' ) {
		if ( is_null( $this->svnMagicWords ) ) {
			$magicWords = array();
			require( dirname( __FILE__ ) . '/SubversionChanges.i18n.magic.php' );

			if (array_key_exists($langCode, $magicWords)) {
				$this->svnMagicWords = $magicWords[$langCode];
			}
			else {
				$this->svnMagicWords = $magicWords['en'];
			}
		}

		foreach($this->svnMagicWords as $word => $language) {
			$globalMagicWords[$word] = $language;
		}
		return true;
	}

	/** Defer ParserClearState */
	public function clearState( $parser ) {
		if ( !is_null( $this->realObj ) ) {
			$this->realObj->clearState( $parser );
		}
		return true;
	}

	/** Pass through function call */
	public function __call( $name, $args ) {
		if ( is_null( $this->realObj ) ) {
			$this->realObj = new ExtSubversionChanges;
			$this->realObj->clearState( $args[0] );
		}
		return call_user_func_array( array( $this->realObj, "$name" ), $args );
	}
}

?>
