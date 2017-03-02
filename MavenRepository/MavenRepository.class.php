<?php

require_once( dirname(__FILE__) . '/MvnUtil.php');

class ExtMavenRepository
{

	var $supportedTypes = array('snapshot','release','*','all');

	function __construct()
	{
		if ( function_exists( 'wfLoadExtensionMessages' ) ) {
			wfLoadExtensionMessages( 'MavenRepository' );
		}
	}

	public static function mvnJoinUrl()
	{
        	$params = func_get_args();
	        $nparams = func_num_args();
        	$t = '';
	        for($i=0; $i<$nparams; $i++) {
        	        $p = preg_replace('/\\/+$/', '', $params[$i]);
                	if ($t) $t .= '/';
	                $t .= $p;
        	}
	        return $t;
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

	// Generates error message.  Called when no maven module given.
	private function noMavenModuleError()
	{
		global $wgContLang;
		wfLoadExtensionMessages( 'MavenRepository' );
		return '<strong class="error">' . 
			wfMsgExt( 'mvnrepo_no_maven_module',
				array( 'escape', 'parsemag', 'content' ) ) .
			'</strong>';
	}

	// Generates error message.  Called when no maven group id given.
	private function noMavenGroupIdError()
	{
		global $wgContLang;
		wfLoadExtensionMessages( 'MavenRepository' );
		return '<strong class="error">' . 
			wfMsgExt( 'mvnrepo_no_maven_group_id',
				array( 'escape', 'parsemag', 'content' ) ) .
			'</strong>';
	}

	// Generates error message.  Called when no maven artifact id given.
	private function noMavenArtifactIdError()
	{
		global $wgContLang;
		wfLoadExtensionMessages( 'MavenRepository' );
		return '<strong class="error">' . 
			wfMsgExt( 'mvnrepo_no_maven_artifact_id',
				array( 'escape', 'parsemag', 'content' ) ) .
			'</strong>';
	}

	// Generates error message.  Called when no value available.
	private function noValueError()
	{
		global $wgContLang;
		wfLoadExtensionMessages( 'MavenRepository' );
		return '<strong class="error">' . 
			wfMsgExt( 'mvnrepo_no_value',
				array( 'escape', 'parsemag', 'content' ) ) .
			'</strong>';
	}

	// Change the caching behaviour.
	private function updateCache($parser)
	{
		# You can disable the cache, but, remember that
                # this'll hit svn everytime the page is read.
                # not exactly that smart resaving an article 
                # will automatically pull down the fresh status 
                # of the svn content. 
                #$parser->disableCache();
                
                # Force this page to be rendered after a delay.
                # Pass the number of seconds after which the
                # page must be rendered again.
                # 21600 seconds = 6 hours
                $parser->getOutput()->setCacheTime(time()+21600); // old version style
                #$parser->getOutput()->updateCacheExpiry(21600); // new version style
	}

	/**
	 * {{#mvngroupid:moduleId}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * 
	 * Reports group identifier from the given module identifier.
	 */
	public function runMvnGroupId( $parser, $moduleId = '')
	{
		wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

		$moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}

		$groupId = MvnUtil::groupId($moduleId);

		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		wfProfileOut( __METHOD__ );
		return $groupId;
	}

	/**
	 * {{#mvnartifactid:moduleId}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * 
	 * Reports artifact identifier from the given module identifier.
	 */
	public function runMvnArtifactId( $parser, $moduleId = '')
	{
		wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

		$moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}

		$artifactId = MvnUtil::artifactId($moduleId);

		if (!$artifactId) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		wfProfileOut( __METHOD__ );
		return $artifactId;
	}

	/**
	 * {{#mvnversion:moduleId:type}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * type is one of 'snapshot', 'release', '*', or 'all'.
	 * '*' and 'all' means all versions (snapshot or release).
	 * 
	 * Reports maven module version.
	 */
	public function runMvnVersion( $parser, $moduleId = '', $type = '*' )
	{
		wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

                $moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}

		$groupId = MvnUtil::groupId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenGroupIdError();
		}

		$artifactId = MvnUtil::artifactId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenArtifactIdError();
		}

		$type = $this->killMarkers( $parser, (string)$type );

		$version = MvnUtil::getLastVersion($groupId, $artifactId, $type);

		if (!$version) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		wfProfileOut( __METHOD__ );
		return $version;
	}

	/**
	 * {{#mvndate:moduleId:type}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * type is one of 'snapshot', 'release', '*', or 'all'.
	 * '*' and 'all' means all versions (snapshot or release).
	 * 
	 * Reports maven module release date.
	 */
	public function runMvnDate( $parser, $moduleId = '', $type = '*' )
	{
		wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

                $moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}

		$groupId = MvnUtil::groupId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenGroupIdError();
		}

		$artifactId = MvnUtil::artifactId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenArtifactIdError();
		}

		$type = $this->killMarkers( $parser, (string)$type );

		$date = MvnUtil::getLastDate($groupId, $artifactId, $type);

		if (!$date) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		wfProfileOut( __METHOD__ );
		return $date;
	}

	/**
	 * {{#mvnrepository:moduleId:type}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * type is one of 'snapshot', 'release', '*', or 'all'.
	 * '*' and 'all' means all versions (snapshot or release).
	 * 
	 * Reports maven repository for the given module.
	 */
	public function runMvnRepository( $parser, $moduleId = '', $type = '*' )
	{
		wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

                $moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}

		$groupId = MvnUtil::groupId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenGroupIdError();
		}

		$artifactId = MvnUtil::artifactId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenArtifactIdError();
		}

		$type = $this->killMarkers( $parser, (string)$type );

		$path = MvnUtil::getLastVersionRepository($groupId, $artifactId, $type, true);

		if (!$path) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		$url = ExtMavenRepository::mvnJoinUrl($path[1], $path[0]);

		wfProfileOut( __METHOD__ );
		return $url;
	}

	/**
	 * {{#mvnrepositorylink:moduleId:type:label}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * type is one of 'snapshot', 'release', '*', or 'all'.
	 * '*' and 'all' means all versions (snapshot or release).
	 * 
	 * Reports the HTML link of the maven repository for the given module.
	 */
	public function runMvnRepositoryLink( $parser, $moduleId = '', $type = '*', $label='' )
	{
		wfProfileIn( __METHOD__ );

		// Exchange type and label
		if (!$type || !in_array(strtolower($type),$this->supportedTypes)
) {
			if (in_array(strtolower($label), $this->supportedTypes)) {
				$t = $label;
				$label = $type;
				$type = $t;
			}
			else {
				$label = $type;
				$type = '*';
			}
		}
		$label = trim($this->killMarkers( $parser, (string)$label));
		$this->updateCache($parser);

                $moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}

		$groupId = MvnUtil::groupId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenGroupIdError();
		}

		$artifactId = MvnUtil::artifactId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenArtifactIdError();
		}

		$type = $this->killMarkers( $parser, (string)$type );

		$path = MvnUtil::getLastVersionRepository($groupId, $artifactId, $type, true);
		if (!$path) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}
		if (!$label) {
			if (!preg_match("/\\/([^\\/]+)\$/", $path[0], $matches)) {
				wfProfileOut( __METHOD__ );
				return $this->noValueError();
			}
			$label = $matches[1];
		}

		$url = ExtMavenRepository::mvnJoinUrl($path[1], $path[0]);

		wfProfileOut( __METHOD__ );
		return "[$url $label]";
	}

	/**
	 * {{#mvnjar:moduleId:type:modifier}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * type is one of 'snapshot', 'release', '*', or 'all'.
	 * '*' and 'all' means all versions (snapshot or release).
	 * The modifier is the version modifier used by maven, eg. 'jar-with-dependencies'.
	 * 
	 * Reports maven module jar file.
	 */
	public function runMvnJar( $parser, $moduleId = '', $type = '*', $modifier = '' )
	{
		wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

                $moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}

		$groupId = MvnUtil::groupId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenGroupIdError();
		}

		$artifactId = MvnUtil::artifactId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenArtifactIdError();
		}

		$type = $this->killMarkers( $parser, (string)$type );

		$jar = MvnUtil::getLastJar($groupId, $artifactId, $type, $modifier, true);

		if (!$jar) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		$url = ExtMavenRepository::mvnJoinUrl($jar[1], $jar[0]);

		wfProfileOut( __METHOD__ );
		return $url;
	}

	/**
         * {{#mvnjarsize:moduleId:type:modifier}}
         * 
         * moduleId is composed by the group id and the artifact id separated by a column (:).
         * type is one of 'snapshot', 'release', '*', or 'all'.
         * '*' and 'all' means all versions (snapshot or release).
         * The modifier is the version modifier used by maven, eg. 'jar-with-dependencies'.
         * 
         * Reports size in bytes of the maven module jar file.
         */
        public function runMvnJarSize( $parser, $moduleId = '', $type = '*', $modifier = '' )
        {
                wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

                $moduleId = $this->killMarkers( $parser, (string)$moduleId );
                if (!$moduleId) {
                        wfProfileOut( __METHOD__ );
                        return $this->noMavenModuleError();
                }

                $groupId = MvnUtil::groupId($moduleId);
                if (!$groupId) {
                        wfProfileOut( __METHOD__ );
                        return $this->noMavenGroupIdError();
                }

                $artifactId = MvnUtil::artifactId($moduleId);
                if (!$groupId) {
                        wfProfileOut( __METHOD__ );
                        return $this->noMavenArtifactIdError();
                }

                $type = $this->killMarkers( $parser, (string)$type );

                $jar = MvnUtil::getLastJar($groupId, $artifactId, $type, $modifier, true);

                if (!$jar) {
                        wfProfileOut( __METHOD__ );
                        return $this->noValueError();
                }

		$size = 0;

		$filename = MvnUtil::makePath($jar[2],$jar[0]);
		if (is_file($filename)) {
			global $wgLang;
			$size = @filesize($filename);
			$size = $wgLang->formatSize($size);
		}

		wfProfileOut( __METHOD__ );
		return $size;
	}

	/**
	 * {{#mvnjarname:moduleId:type:modifier}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * type is one of 'snapshot', 'release', '*', or 'all'.
	 * '*' and 'all' means all versions (snapshot or release).
	 * The modifier is the version modifier used by maven, eg. 'jar-with-dependencies'.
	 * 
	 * Reports the basename of the maven module jar file.
	 */
	public function runMvnJarName( $parser, $moduleId = '', $type = '*', $modifier = '' )
	{
		wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

                $moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}

		$groupId = MvnUtil::groupId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenGroupIdError();
		}

		$artifactId = MvnUtil::artifactId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenArtifactIdError();
		}

		$type = $this->killMarkers( $parser, (string)$type );

		$jar = MvnUtil::getLastJar($groupId, $artifactId, $type, $modifier);

		if (!$jar) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		if (!preg_match("/\\/([^\\/]+)\$/", $jar, $matches)) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		wfProfileOut( __METHOD__ );
		return $matches[1];
	}

	/**
         * {{#mvnjarlist:groupId:type:modifier}}
         * 
         * type is one of 'snapshot', 'release', '*', or 'all'.
         * '*' and 'all' means all versions (snapshot or release).
         * The modifier is the version modifier used by maven, eg. 'jar-with-dependencies'.
         * 
         * Reports the HTML list of the maven module jar files.
         */
        public function runMvnJarList( $parser, $groupId = '', $type = '*', $modifier = '')
        {
                wfProfileIn( __METHOD__ );

		$type = $this->killMarkers( $parser, (string)$type );

		$modules = MvnUtil::getArtifacts( $groupId, $type );

		$out = '';

		sort($modules);

		foreach($modules as $artifactId) {
			$jar = MvnUtil::getLastJar($groupId, $artifactId, $type, $modifier, true);
			if ($jar) {
                		$url = ExtMavenRepository::mvnJoinUrl($jar[1], $jar[0]);
				$out .= "* [$url $artifactId]\n";
			}
		}

		wfProfileOut( __METHOD__ );
		return $out;
	}

	/**
	 * {{#mvnjarlink:moduleId:type:modifier:label}}
	 * 
	 * moduleId is composed by the group id and the artifact id separated by a column (:).
	 * type is one of 'snapshot', 'release', '*', or 'all'.
	 * '*' and 'all' means all versions (snapshot or release).
	 * The modifier is the version modifier used by maven, eg. 'jar-with-dependencies'.
	 * 
	 * Reports the HTML link of the maven module jar file.
	 */
	public function runMvnJarLink( $parser, $moduleId = '', $type = '*', $modifier = '', $label='' )
	{
		wfProfileIn( __METHOD__ );

		$this->updateCache($parser);

		$label = trim($this->killMarkers( $parser, (string)$label));

                $moduleId = $this->killMarkers( $parser, (string)$moduleId );
		if (!$moduleId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenModuleError();
		}
		$groupId = MvnUtil::groupId($moduleId);
		if (!$groupId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenGroupIdError();
		}

		$artifactId = MvnUtil::artifactId($moduleId);
		if (!$artifactId) {
			wfProfileOut( __METHOD__ );
			return $this->noMavenArtifactIdError();
		}

		$type = $this->killMarkers( $parser, (string)$type );

		$jar = MvnUtil::getLastJar($groupId, $artifactId, $type, $modifier, true);

		if (!$jar) {
			wfProfileOut( __METHOD__ );
			return $this->noValueError();
		}

		if (!$label) {
			if (!preg_match("/\\/([^\\/]+)\$/", $jar[0], $matches)) {
				wfProfileOut( __METHOD__ );
				return $this->noValueError();
			}
			$label = $matches[1];
		}

		$url = ExtMavenRepository::mvnJoinUrl($jar[1], $jar[0]);

		$link = "[$url $label]";

		wfProfileOut( __METHOD__ );
		return $link;
	}

}

?>
