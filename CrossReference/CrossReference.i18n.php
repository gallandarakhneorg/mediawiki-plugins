<?php
/**
 * Internationalisation file for extension CrossReference.
 * See files in subdirectory ./i18n for message
 * translations.
 *
 * @file
 * @ingroup Extensions
 */

if ((!isset($messages))||(!is_array($messages))) {
	$messages = array();
}

{

	$dirname = dirname(__FILE__).'/i18n';
	$dir = @opendir($dirname);

	if ($dir) {

		while ($file = @readdir($dir)) {
			if (preg_match("/\\.i18n\\.php\$/s", $file)) {
				$fn = "$dirname/$file";
				require_once($fn);
			}
		}

		@closedir($dir);
	}

}

