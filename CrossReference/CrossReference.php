<?php

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

/**
 * CONFIGURATION 
 * These variables may be overridden in LocalSettings.php after you include the
 * extension file.
 */

/** REGISTRATION */
$wgExtensionFunctions[] = 'wfSetupCrossReference';
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'CrossReference',
	'version' => '3.0', 
	'url' => 'http://www.mediawiki.org/wiki/Extension:CrossReference',
	'author' => array('Tore Birkeland', '[[:Wikipedia:User:sgalland-arakhne|Stéphane GALLAND]]'),
	'descriptionmsg' => 'crossreference_desc',
);

$wgAutoloadClasses['ExtCrossReference'] = dirname(__FILE__).'/CrossReference.class.php';
$wgExtensionMessagesFiles['CrossReference'] = dirname(__FILE__) . '/CrossReference.i18n.php';

function wfSetupCrossReference()
{
	global $wgCrossReferenceHookStub, $wgHooks, $wgParser;

	$wgCrossReferenceHookStub = new CrossReference_HookStub;

	$wgHooks['LanguageGetMagic'][] = array( &$wgCrossReferenceHookStub, 'getMagicWords' );
	$wgHooks['ParserFirstCallInit'][] = array( &$wgCrossReferenceHookStub, 'registerParser' );
	$wgHooks['ParserClearState'][] = array( &$wgCrossReferenceHookStub, 'clearState' );
	$wgHooks['ParserAfterTidy'][] = array( &$wgCrossReferenceHookStub, 'parserAfterTidy' );
}

/**
 * Stub class to defer loading of the bulk of the code until a function is
 * actually used.
 */
class CrossReference_HookStub
{
	var $realObj = null;
	var $crMagicWords = null;

	public function registerParser( $parser )
	{
		require( dirname(__FILE__) . '/CrossReference.mapping.magic.php');
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
	public function getMagicWords( &$globalMagicWords, $langCode = 'en' )
	{
		if ( is_null( $this->crMagicWords ) ) {
			$magicWords = array();
			$dirname = dirname(__FILE__).'/i18n';
			$dir = @opendir($dirname);
			if ($dir) {

				while ($file = readdir($dir)) {
					if (preg_match("/\\.magic\\.php\$/s", $file)) {
						$fn = "$dirname/$file";
						require_once($fn);
					}
				}			

				@closedir($dir);

				if (array_key_exists($langCode, $magicWords)) {
					$this->crMagicWords = $magicWords[$langCode];
				}
				else {
					$this->crMagicWords = $magicWords['en'];
				}
			}
		}

		foreach($this->crMagicWords as $word => $language) {
			$globalMagicWords[$word] = $language;
		}
		return true;
	}

	/** Defer ParserClearState */
	public function clearState( $parser )
	{
		if ( !is_null( $this->realObj ) ) {
			$this->realObj->clearState( $parser );
		}
		$this->crMagicWords = null;
		return true;
	}

	/** Pass through function call */
	public function __call( $name, $args )
	{
		if ( is_null( $this->realObj ) ) {
			$this->realObj = new ExtCrossReference;
			$this->realObj->clearState( $args[0] );
		}
		return call_user_func_array( array( $this->realObj, "$name" ), $args );
	}
}

?>
