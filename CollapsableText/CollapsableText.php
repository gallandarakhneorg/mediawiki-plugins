<?php

define(COLLAPSABLETEXT_EXT_NAME, 'CollapsableText');
define(COLLAPSABLETEXT_EXT_VERSION, '1.0');

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}


/**
 * CONFIGURATION 
 * These variables may be overridden in LocalSettings.php after you include the
 * extension file.
 */

// URL to the hiding icon
$wgCollapsableTextHideIcon = $wsScriptPath.'/extensions/CollapsableText/hide.png';

// URL to the showing icon
$wgCollapsableTextShowIcon = $wsScriptPath.'/extensions/CollapsableText/show.png';

// URL to the empty icon
$wgCollapsableTextEmptyIcon = $wsScriptPath.'/extensions/CollapsableText/empty.png';

/** REGISTRATION */
$wgExtensionFunctions[] = 'wfSetupCollapsableText';
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => COLLAPSABLETEXT_EXT_NAME,
	'version' => COLLAPSABLETEXT_EXT_VERSION,
	'url' => 'http://www.mediawiki.org/wiki/Extension:CollapsableText',
	'author' => array('[[:Wikipedia:User:sgalland-arakhne|Stéphane GALLAND]]'),
	'descriptionmsg' => 'collapsabletext_desc',
);

$wgAutoloadClasses['ExtCollapsableText'] = dirname(__FILE__).'/CollapsableText.class.php';
$wgExtensionMessagesFiles['CollapsableText'] = dirname(__FILE__) . '/CollapsableText.i18n.php';

function wfSetupCollapsableText() {
	global $wgCollapsableTextHookStub, $wgHooks, $wgParser;

	$wgCollapsableTextHookStub = new CollapsableText_HookStub;

	$wgHooks['LanguageGetMagic'][] = array( &$wgCollapsableTextHookStub, 'getMagicWords' );
	$wgHooks['ParserFirstCallInit'][] = array( &$wgCollapsableTextHookStub, 'registerParser' );
	$wgHooks['ParserClearState'][] = array( &$wgCollapsableTextHookStub, 'clearState' );
}

/**
 * Stub class to defer loading of the bulk of the code until a function is
 * actually used.
 */
class CollapsableText_HookStub {
	var $realObj = null;
	var $ctMagicWords = null;

	public function registerParser( $parser ) {
		require( dirname(__FILE__) . '/CollapsableText.mapping.magic.php');
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
		if ( is_null( $this->ctMagicWords ) ) {
			$magicWords = array();
			require( dirname( __FILE__ ) . '/CollapsableText.i18n.magic.php' );

			if (array_key_exists($langCode, $magicWords)) {
				$this->ctMagicWords = $magicWords[$langCode];
			}
			else {
				$this->ctMagicWords = $magicWords['en'];
			}
		}

		foreach($this->ctMagicWords as $word => $language) {
			$globalMagicWords[$word] = $language;
		}
		return true;
	}

	/** Defer ParserClearState */
	public function clearState( $parser ) {
		if ( !is_null( $this->realObj ) ) {
			$this->realObj->clearState( $parser );
		}
		$this->ctMagicWords = null;
		return true;
	}

	/** Pass through function call */
	public function __call( $name, $args ) {
		if ( is_null( $this->realObj ) ) {
			$this->realObj = new ExtCollapsableText;
			$this->realObj->clearState( $args[0] );
		}
		return call_user_func_array( array( $this->realObj, "$name" ), $args );
	}
}

?>
