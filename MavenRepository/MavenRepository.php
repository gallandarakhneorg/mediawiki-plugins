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
 * Defines the paths to the Maven repositories
 * It is an associative array of (repository_local_path => repository_url).
 */
$wgMvnRepoPaths = array();

/** REGISTRATION */
$wgExtensionFunctions[] = 'wfSetupMavenRepository';
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'MavenRepository',
	'version' => '1.2',
	'url' => 'http://www.mediawiki.org/wiki/Extension:MavenRepository',
	'author' => array('[[:Wikipedia:User:sgalland-arakhne|Stéphane GALLAND]]'),
	'descriptionmsg' => 'mvnrepo_desc',
);

$wgAutoloadClasses['ExtMavenRepository'] = dirname(__FILE__).'/MavenRepository.class.php';
$wgExtensionMessagesFiles['MavenRepository'] = dirname(__FILE__) . '/MavenRepository.i18n.php';

function wfSetupMavenRepository() {
	global $wgMvnRepoHookStub, $wgHooks;

	$wgMvnRepoHookStub = new MavenRepository_HookStub;

	$wgHooks['LanguageGetMagic'][] = array( &$wgMvnRepoHookStub, 'getMagicWords' );
	$wgHooks['ParserFirstCallInit'][] = array( &$wgMvnRepoHookStub, 'registerParser' );
	$wgHooks['ParserClearState'][] = array( &$wgMvnRepoHookStub, 'clearState' );
}

/**
 * Stub class to defer loading of the bulk of the code until a mvn function is
 * actually used.
 */
class MavenRepository_HookStub {
	var $realObj = null;
	var $mvnMagicWords = null;

	public function registerParser( $parser ) {
		require( dirname(__FILE__) . '/MavenRepository.mapping.magic.php');
		foreach($mapping as $magicWord => $phpFunction) {
			$parser->setFunctionHook( $magicWord, array( &$this, $phpFunction ) );
		}
		return true;
	}

	/** Replies magic word for given language.
	 */
	public function getMagicWords( &$globalMagicWords, $langCode = 'en' ) {
		if ( is_null( $this->mvnMagicWords ) ) {
			$magicWords = array();
			require( dirname( __FILE__ ) . '/MavenRepository.i18n.magic.php' );

			if (array_key_exists($langCode, $magicWords)) {
				$this->mvnMagicWords = $magicWords[$langCode];
			}
			else {
				$this->mvnMagicWords = $magicWords['en'];
			}
		}

		foreach($this->mvnMagicWords as $word => $language) {
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
			$this->realObj = new ExtMavenRepository;
			$this->realObj->clearState( $args[0] );
		}
		return call_user_func_array( array( $this->realObj, "$name" ), $args );
	}
}

?>
