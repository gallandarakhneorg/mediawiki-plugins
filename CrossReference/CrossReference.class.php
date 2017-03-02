<?php

class CrossReferenceSubComponentParser
{
	var $componenttag;
	var $argv;
	var $parser;
	var $parentid;
	var $parenttag;
	var $num;

	public function __construct($parenttag, $componenttag, $argv, $parser, $parentid)
	{
		$this->parenttag = $parenttag;
		$this->componenttag = $componenttag;
		$this->argv = $argv;
		$this->parser = $parser;
		$this->parentid = $parentid;
		$this->num = 1;
	}

	public function clear()
	{
		$this->parenttag = null;
		$this->componenttag = null;
                $this->argv = null;
                $this->parser = null;
		$this->parentId = null;
		$this->num = null;
	}

	public function parse($matches)
	{
		$subid = $this->parentid."#".$this->num;
		$txt = "<".$this->parenttag." id=\"".addslashes($subid)."\">";
		$txt .= $matches[1];
		$txt .= "</".$this->parenttag.">";
		$out = $this->parser->recursiveTagParse($txt);
		$this->num ++;
		$n = count($this->buffer);
		return $out;
	}

}

class ExtCrossReference
{

	var $counter = array();
	var $lookup = array();

	function __construct()
	{
		if ( function_exists( 'wfLoadExtensionMessages' ) ) {
			wfLoadExtensionMessages( 'CrossReference' );
		}
	}

	private function forceTempCache($parser)
        {
                # Force this page to be rendered after a delay.
                # Pass the number of seconds after which the
                # page must be rendered again.
                # 21600 seconds = 6 hours
                $parser->getOutput()->setCacheTime(time()+21600); // old version style
                #$parser->getOutput()->updateCacheExpiry(21600); // new version style

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
	private function error($message, $title='')
	{
		if ($title)
			$title = " title=\"".addslashes($title)."\"";
		return "<strong class=\"error\"$title>$message</strong>"; 
	}

	// Generates Error message
        private function errorMsg($messageId, $solutionId, $method='')
        {
		$solution = '';
		if ($solutionId) {
			$solution = wfMsgExt( 
				$solutionId,
				 array( 'escape', 'parsemag', 'content' ),
				$method);
			if ($solution==$solutionId)
				$solution = '';
		}
                $msg = $this->error(wfMsgExt( $messageId,
                         array( 'escape', 'parsemag', 'content' )),
			$solution);
		return $msg;
        }

	private function wikiUrl($url, $text='', $title='')
        {
                if (!$text) {
                        if (!$title) $text = $title;
                        else $text = $url;
                }
                if (!$title) $title = $url;
                return "<a href=\"".trim($url)."\" title=\"".
                           addslashes(strip_tags(trim($title))).
                           "\" class=\"external text\" ".
                           "rel=\"nofollow\">".trim($text)."</a>";

        }

	private function postreplaceId($matches)
	{
		$hidden = $matches[1];
		$id = trim($matches[2]);
		if ($id && isset($this->lookup[$id])) {
			if ($hidden) {
				return $this->lookup[$id]['num'];
			}
			else {
				return $this->wikiUrl("#label-$id",
					$this->lookup[$id]['num'],
					$this->lookup[$id]['caption']);
			}
		}
		return $this->error('???', $id);
	}

	private function postreplaceUnknownId($matches)
	{
		$defaultCaption = $matches[1];
		$hidden = $matches[2];
		$ids = preg_split("/\\s*,\\s*/s", $matches[3]);
                $out = '';
		$group = '';
                foreach($ids as $id) {
                        if ($out) $out .= ',';
                        if ($id && isset($this->lookup[$id])) {
				if ($hidden) {
					$out .= $this->lookup[$id]['num'];
				}
				else {
	                                $out .= $this->wikiUrl("#label-$id",
        	                                   $this->lookup[$id]['num'],
                	                          $this->lookup[$id]['caption']);
				}
				$group = $this->lookup[$id]['group'];
                        }
                        else {
                                $out .= $this->error('???', $id);
                        }
                }
		if ($defaultCaption && $group) {
			$caption = $this->getDefaultCaption($group, '',
				count($ids)>1);
			$out = str_replace('%i', $out, $caption);
		}
		return $out;
	}

	/** Invoked at the last stage of the p  arsing process.
	  */
        public function parserAfterTidy( &$parser, &$text )
        {
		$text = preg_replace("/&(amp;)?column;/s", ':', $text);
		// Put back captions
		foreach ($this->lookup as $id => $entry) {
			$nummarker = "xx--CrossReference--$id--xx";
			$hnummarker = "xx--CrossReference--####--$id--xx";

			$caption = $this->getDefaultCaption($entry['group']);
                        if ($caption) {
                                $ncaption = str_replace('%i', $nummarker, $caption);
				$marker = "xx--CrossReference--dft--$id--xx";
				$text = str_replace($marker, $ncaption, $text);

				$ncaption = str_replace('%i', $hnummarker, $caption);
				$marker = "xx--CrossReference--dft--####--$id--xx";
                                $text = str_replace($marker, $ncaption, $text);
                        }
		}
		foreach ($this->lookup as $id => $entry) {
			$text = preg_replace_callback(
                                "/\\Qxx--CrossReference--\\E((?:\\Q####--\\E)?)(\\Q$id\\E)\\Q--xx\\E/s",
                                array($this, 'postreplaceId'),
                                $text);
	        }
		// Replace by error message the not found marks
		$text = preg_replace_callback(
			"/\\Qxx--CrossReference--\\E((?:\\Qdft--\\E)?)((?:\\Q####--\\E)?)(.*?)\\Q--xx\\E/s", 
			array($this, 'postreplaceUnknownId'),
			$text);
	        return true;
        }

	private function getDefaultCaption($group, $caption='', $plurial=false) {
 	       if ($group) {
			if ($caption) {
	                	$c = 'crossreference_caption_'.$group;
			}
			else {
				$c = 'crossreference_ncaption_'.$group;
			}
			if ($plurial) {
				$c .= '_s';
			}
	                $caption = wfMsgExt( $c,
        	                        array( 'parsemag', 'content' ),
                	                '%i', $caption );
			$caption = str_replace('%c', '', $caption);
	                if ("<$c>"!=$caption) return $caption;
        	}
	        return '';      
	}

	public function getCrossReferenceText($id, $group,$hidden=true)
	{
		$caption = $this->getDefaultCaption($group);
		$hidden = $hidden ? '####--' : '';
		$marker = "xx--CrossReference--$hidden$id--xx";
		$caption = str_replace('%i', $marker, $caption);
		return $caption;
	}

	private function recursiveNumber($group, $parentid, $childn)
	{
		$out = chr(ord('a')+$childn-1);
		while (preg_match("/^(.+)\\#([0-9]+)$/s", $parentid, $matches)) {
			$out = chr(org('a')+$matches[2]-1).'-'.$out;
			$parentid = $matches[1];
		}
		$out = "xx--CrossReference--####--$parentid--xx-$out";
		return $out;
	}

	/** Expand <xrlabel> text </xrlabel>
	 *
	 * In text, <caption></caption> defines the text of 
	 * the caption. This text will be treated according to
	 * the type of label. This tag is parsed to determine
	 * if a default caption should be added, eg. "Figure X: ".
	 *
	 * In text, all occurrences of "%c" will be replaced by
	 * the caption, and "%i" by the label number.
	 *
	 * Supported groups are:
	 * fig			figure.
	 * tab			table.
	 * eqn			equation.
	 * the			theorem.
	 * def			definition.
	 * bib			bibliography.
	 * txt			default type, text.
	 *
	 * id="id"		identifier of the reference to site
	 * group="name"		name of the reference group
	 * 			(default is 'txt').
	 * shownumber		if present, display the figure inside
	 *			parenthesis before the label text itself.
	 * noblock		do not output HTML blocks.
	 * noautocaption        do not change the caption text in output.
         */
        public function expandXrLabel( $text='', $argv='', $parser=null )
        {
		wfProfileIn( __METHOD__ );

		$out = '';

		$id = trim($argv['id']);
		if ($id) {
                        $subcomponenttag = $argv['subcomponent'];
			$group = trim($argv['group']);
			if (!$group) $group = 'ref';
			$noautocaption = isset($argv['noautocaption']);

			// Detect a subcomponent id
			if (preg_match("/^(.+)\\#([0-9]+)$/s", $id, $matches)) {
				$parentid = $matches[1];
				$childid = $matches[2];
			}
			else {
				$parentid = $childid = null;
			}

			// Increment reference counter
			if (!$parentid) {
				if (!array_key_exists($group, $this->counter)) {
					$this->counter[$group] = 1;
				}
				$num = $this->counter[$group];
				$this->counter[$group] += 1;
			}
			else {
				$num = $this->recursiveNumber($group, $parentid, $childid);
				$subcomponenttag = null; // ignore subsubcomponents
			}

			// Save counter for the id
			$this->lookup[$id]['num'] = $num;

			// Protect and parse subcomponents
			if ($subcomponenttag) {
				$submatcher = new CrossReferenceSubComponentParser(
						preg_replace("/^sub/s", '', $subcomponenttag),
						$subcomponenttag,
						$argv,
						$parser,
						$id);
				$count = null;
				$text = preg_replace_callback(
					"!\\Q<$subcomponenttag>\\E(.*?)\\Q</$subcomponenttag>\\E!s",
					array($submatcher, 'parse'),
					$text);
			}
			else {
				$submatcher = null;
			}

			// Retreive the caption
			$caption = '';
			if (preg_match("!\\Q<caption>\\E(.*?)\\Q</caption>\\E!s", $text, $matches)) {
				$caption = trim($matches[1]);
			}

			$defaultCaption = $this->getDefaultCaption($group, $caption);
                	if ($caption) {
				$replacement = ($noautocaption) ? $caption : $defaultCaption;
		        	$text = preg_replace("!\\Q<caption>\\E(.*?)\\Q</caption>\\E!s", $replacement, $text);
		        }

			// Replace %c
			$text = str_replace("%c", $defaultCaption, $text);
			// Replace %i
			$text = str_replace("%i", $num, $text);
			$defaultCaption = str_replace("%i", $num, $defaultCaption);

			// Save the caption and group
                        $this->lookup[$id]['caption'] = $defaultCaption;
                        $this->lookup[$id]['group'] = $group;

			// Expand text
			$innerHtml = $parser->recursiveTagParse(trim($text));

			// Build label
			if (!isset($argv['noblock'])) {
				$out .= "<div class='crossref-block' id='label-".
					$id."'>";
			}
			if (isset($argv['showNumber'])) {
				$out .= "<span class='cross-id'>";
				$out .= "(" . $num . ")";
				$out .= "</span>";
			}
			if (!isset($argv['noblock'])) {
			        $out .= "<span class='crossref-content'>";
			}
		        $out .= $innerHtml;
			if (!isset($argv['noblock'])) {
			        $out .= "</span>";
			        $out .= "</div>";	
			}

			if (isset($submatcher)) {
				$submatcher->clear();
				$submatcher = null;
			}
		}
		else {
			$out = $this->errorMsg('crossreference_noid',
				'crossreference_noid_explanation',
                                        __METHOD__);
		}

		wfProfileOut( __METHOD__ );
		return $out;
	}

	/** Expand <xr></xr>
	 *
	 * id="id"		identifier of the reference
	 * nolink		no hypertext link
         */
        public function expandXr( $text='', $argv='', $parser=null )
        {
                wfProfileIn( __METHOD__ );
                $out = '';

		$hidden = '';
                if (isset($argv['nolink'])) {
                	$hidden = '####--';
                }

		$text = trim($text);

		$id = trim($argv["id"]);
		if ($id) {
	        	$marker = "xx--CrossReference--$hidden$id--xx";
			$dmarker = "xx--CrossReference--dft--$hidden$id--xx";

			if (!$text) {
				if (count($ids)>1) $txt = $marker;
				else $txt = $dmarker;
			}
			elseif (!strpos($text, "%i")) {
				$txt = $text.'&nbsp;'.$marker;
		       	}
			else {
				$txt = str_replace("%i", $marker, $text);
			}
			$out = $parser->recursiveTagParse($txt);
		}
		else {
			$out = $this->errorMsg('crossreference_noid',
					'crossreference_noid_explanation',
					__METHOD__);
		}
		
                wfProfileOut( __METHOD__ );
                return $out;
        }

	/** Expand <figure></figure>
         */
        public function expandFigure( $input='', $argv='', $parser=null )
        {
                wfProfileIn( __METHOD__ );
		$argv['group'] = 'fig';
		$argv['subcomponent'] = 'subfigure';
                $out = $this->expandXrLabel($input, $argv, $parser);
                wfProfileOut( __METHOD__ );
                return $out;
        }

	/** Expand <equation></aquation>
         */
        public function expandEquation( $input='', $argv='', $parser=null )
        {
                wfProfileIn( __METHOD__ );
                $argv['group'] = 'eqn';
		$argv['subcomponent'] = 'subequation';
                $out = $this->expandXrLabel($input, $argv, $parser);
                wfProfileOut( __METHOD__ );
                return $out;
        }

	/** Expand <definition></definition>
         */
        public function expandDefinition( $input='', $argv='', $parser=null )
        {
                wfProfileIn( __METHOD__ );
                $argv['group'] = 'def';
		$argv['subcomponent'] = 'subdefinition';
                $out = $this->expandXrLabel($input, $argv, $parser);
                wfProfileOut( __METHOD__ );
                return $out;
        }

	/** Expand <figtable></figtable>
         */
        public function expandFigTable( $input='', $argv='', $parser=null )
        {
                wfProfileIn( __METHOD__ );
                $argv['group'] = 'tab';
		$argv['subcomponent'] = 'subfigtable';
                $out = $this->expandXrLabel($input, $argv, $parser);
                wfProfileOut( __METHOD__ );
                return $out;
        }

	/** Expand <theorem></theorem>
         */
        public function expandTheorem( $input='', $argv='', $parser=null )
        {
                wfProfileIn( __METHOD__ );
                $argv['group'] = 'the';
		$argv['subcomponent'] = 'subtheorem';
                $out = $this->expandXrLabel($input, $argv, $parser);
                wfProfileOut( __METHOD__ );
                return $out;
        }

}

?>
