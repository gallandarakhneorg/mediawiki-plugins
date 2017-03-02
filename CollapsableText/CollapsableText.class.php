<?php

global $collapsableTextTopKeyIndex;
$collapsableTextTopKeyIndex = null;

class ExtCollapsableText
{

	function __construct()
	{
		if ( function_exists( 'wfLoadExtensionMessages' ) ) {
			wfLoadExtensionMessages( 'CollapsableText' );
		}
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

	/** Expand <collapsetext ...> </collapsetext>
	 *
	 * show="true|false" 		Init visibility state
	 *
	 * titleclass=""                CSS style for title text
	 * bodyclass=""			CSS style for collapsed text
         */
        public function expandCollapseText( $input='', $argv='', $parser=null )
        {
		global $wgCollapsableTextHideIcon;
                global $wgCollapsableTextShowIcon;
		global $wgCollapsableTextEmptyIcon;
		global $collapsableTextTopKeyIndex;

		wfProfileIn( __METHOD__ );

		$show = false;
		if (isset($argv['show']) && ($argv['show']=='true')) {
			$show = true;
		}
		$style1 = 'collapsedtexttitle';
                if (isset($argv['titleclass']) && $argv['titleclass']) {
                        $style2 = $argv['titleclass'];
                }
		$style2 = 'collapsedtextbody';
		if (isset($argv['bodyclass']) && $argv['bodyclass']) {
			$style2 = $argv['bodyclass'];
		}

		if (preg_match("!^(.*?)\\Q<collapse\\E\\s*/?\\Q>\\E(.*)\$!s", $input, $matches)) {
			$firstLine = trim($matches[1]);
			$collapsedText = trim($matches[2]);
		}
		elseif (preg_match("/^\\s*[{]\\s*(.*?)\\s*[}](.*)\$/s", $input, $matches)) {
			$firstLine = trim($matches[1]);
                        $collapsedText = trim($matches[2]);
		}
		elseif (preg_match("/^(.*?\\.)(.*)\$/s", $input, $matches)) {
			$firstLine = trim($matches[1]);
			$collapsedText = trim($matches[2]);
		}
		else {
			$firstLine = trim($input);
			$collapsedText = '';	
		}

		$firstLine = $parser->recursiveTagParse($firstLine);
		$collapsedText = $parser->recursiveTagParse($collapsedText);

		$showflag = $show ? 'block' : 'none';
		$isempty = !isset($collapsedText) || !$collapsedText;

		// Add global init function
		if (!isset($collapsableTextTopKeyIndex)) {
			$out .= $this->getHideShowJavaScript();
			$collapsableTextTopKeyIndex = 0;
		}

		$collapsableTextTopKeyIndex ++;

		$out .= $this->getHideShowToggleButton(
					$collapsableTextTopKeyIndex,
					$show, $isempty);
		$out .= "<div class=\"$style1\">";
		$out .= $firstLine;
		$out .= "</div>";

		$out .= "<div class=\"$style2\"><span id=\"ctsh-";
		$out .= $collapsableTextTopKeyIndex;
		$out .= "-description\" style=\"display:$showflag;\">";
		$out .= $collapsedText;
		$out .= "</span></div>";

		wfProfileOut( __METHOD__ );
		return $out;
	}

	private function getHideShowJavaScript()
        {
                global $wgCollapsableTextHideIcon;
                global $wgCollapsableTextShowIcon;
                $content = file(dirname(__FILE__)."/CollapsableText.js");
                $content = implode("", $content);
                $content = '$collapsableTextHideIcon = "'.
                                addslashes($wgCollapsableTextHideIcon).
                                '"; '.$content;
                $content = '$collapsableTextShowIcon = "'.
                                addslashes($wgCollapsableTextShowIcon).
                                '"; '.$content;
                return "<script type=\"text/javascript\">/*<![CDATA[*/".
                        preg_replace("/\\s+/s", ' ', $content).
                        "/*]]>*/</script>";
        }

        private function getHideShowToggleButton($id,$show=false,$isempty=false)
        {
                global $wgCollapsableTextShowIcon;
		global $wgCollapsableTextHideIcon;
		global $wgCollapsableTextEmptyIcon;
		if ($isempty) {
			return '<img id="ctsh-'.
                                addslashes($id).
                                '-togglebt" src="'.
                                addslashes($wgCollapsableTextEmptyIcon).
                                '"></a>';
		}
		else {
			if ($show) {
				$icon = $wgCollapsableTextHideIcon;
			}
			else {
				$icon = $wgCollapsableTextShowIcon;
			}
        	        return "<script type=\"text/javascript\">".
				"initializeCollapsableTextItemHS(\"".
				addslashes($id).
				'");</script><a href="'.
				'javascript:toggleCollapsableTextHS(\''.
				addslashes($id).
				'\')"><img id="ctsh-'.
				addslashes($id).
				'-togglebt" src="'.
				addslashes($icon).
				'"></a>';
		}
        }

}

?>
