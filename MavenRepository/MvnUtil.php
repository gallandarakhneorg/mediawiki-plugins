<?php

class MvnUtil {

	public static function getFileSeparator()
	{
		return '/';
	}

	public static function makePath()
	{
		$args = func_get_args();
		return implode(MvnUtil::getFileSeparator(), $args);
	}

	public static function groupId($moduleId)
	{
		if (preg_match('/^([a-zA-Z0-9_.-]+):[a-zA-Z0-9_.-]+$/',$moduleId,$args)) {
			return $args[1];
		}
		return '';
	}

	public static function artifactId($moduleId)
	{
		if (preg_match('/^[a-zA-Z0-9_.-]+:([a-zA-Z0-9_.-]+)$/',$moduleId,$args)) {
			return $args[1];
		}
		return '';
	}

	public static function removeSnapshot($version)
	{
		if (preg_match("/^([0-9]+(?:\\.[0-9.]+)*)(?:-.+)?\$/", $filename, $args)) {
			return $args[1];
		}
		return $version;
	}

	public static function isSnapshot($version)
	{
		return preg_match("/^([0-9]+(?:\\.[0-9.]+)*)\\Q-SNAPSHOT\\E\$/i", $version);
	}

	public static function isRelease($version)
	{
		return preg_match("/^([0-9]+(?:\\.[0-9.]+)*)\$/", $version);
	}

	protected static function makeGroupPath($groupId, $root='')
	{
		$path = str_replace('.', MvnUtil::getFileSeparator(), $groupId);
                if ($root) {
                        $path = MvnUtil::makePath($root,$path);
                }
                return $path;
	}

	protected static function makeModulePath($groupId, $artifactId, $root='')
	{
		$path = MvnUtil::makeGroupPath($groupId, $root);
		$path = MvnUtil::makePath($path,$artifactId);
		return $path;
	}

	protected static function makeModuleFilename($filename, $groupId, $artifactId, $root='')
	{
		$path = MvnUtil::makeModulePath($groupId, $artifactId, $root);
		if ($path) {
			$path = MvnUtil::makePath($path, $filename);
		}
		return $path;
	}

	protected static function makeReleaseFilename($filename, $groupId, $artifactId, $version, $root='')
	{
		$path = MvnUtil::makeModulePath($groupId, $artifactId, $root);
		if ($path) {
			$path = MvnUtil::makePath($path, $version, $filename);
		}
		return $path;
	}

	protected static function compareVersions($v1,$v2) {
		if ($v1 == $v2) $r = 0;
		elseif (!$v1) $r = -1;
		elseif (!$v2) $r = 1;
		else {
			$r = 0;
			$ext1 = '';
			if (preg_match('/^([0-9.]+)(-.+)$/', $v1,$args)) {
				$ext1 = $args[2];
				$v1 = $args[1];
			}
			if (preg_match('/^([0-9.]+)(-.+)$/', $v2,$args)) {
				$ext2 = $args[2];
				$v2 = $args[1];
			}
			$n1 = preg_split("/\\./s", $v1);
			$n2 = preg_split("/\\./s", $v2);
			for($i=0; $r==0 && $i<count($n1) && $i<count($n2); $i++) {
				$cmp = intval($n1[$i]) - intval($n2[$i]);
				if ($cmp!=0) {
					$r = $cmp;
				}
			}
			if ($r==0) {
				$cmp = count($n2) - count($n1);
				if ($cmp!=0) $r = $cmp;
				elseif ($ext1 == $ext2) $r = 0;
				elseif ($ext1 == '-SNAPSHOT') $r = -1;
				elseif ($ext2 == '-SNAPSHOT') $r = 1;
				else $r = 0;
			}
		}
		return $r;
	}

	protected static function readMetadata($path, $tags, $groupId, $artifactId, $root='')
	{
		$fid = @opendir($path);
		$meta = array();
		if ($fid) {
			while ($filename = readdir($fid)) {
				if (preg_match("/^\\Qmaven-metadata\\E(.*)\\.xml\$/", $filename, $args)) {
					$fn = "$path/$filename";
					$content = @file($fn);
					if ($content) {
						$content = implode('', $content);
						if ((preg_match("!\\Q<groupId>$groupId</groupId>\\E!s", $content))
						    &&
						    (preg_match("!\\Q<artifactId>$artifactId</artifactId>\\E!s", $content))) {
							foreach($tags as $tag) {
								if (preg_match_all("!\\Q<$tag>\\E\\s*(.*?)\\s*\\Q</$tag>\\E!s",
									       $content, $matches, PREG_SET_ORDER)) {
									foreach($matches as $match) {
										if ($match[1]) {
											$meta[$tag][] = $match[1];
										}
									}
								}
							}
						}
					}
				}
			}
			@closedir($fid);
		}
		return $meta;
	}

	protected static function readMetaMetadata($tags, $groupId, $artifactId, $root='')
	{
		$path = MvnUtil::makeModulePath($groupId, $artifactId, $root);
		return MvnUtil::readMetadata($path, $tags, $groupId, $artifactId, $root);
	}

	protected static function readVersionMetadata($tags, $groupId, $artifactId, $version, $root='')
	{
		$path = MvnUtil::makeModuleFilename($version, $groupId, $artifactId, $root);
		return MvnUtil::readMetadata($path, $tags, $groupId, $artifactId, $root);
	}

	protected static function readVersionFromFileSystem($tags, $groupId, $artifactId, $root='', $ext='*')
	{
		$path = MvnUtil::makeModulePath($groupId, $artifactId, $root);
		$fid = @opendir($path);
		$version = '';
		if ($fid) {
			$subfiles = array();
			while ($filename = readdir($fid)) {
				if (preg_match("/^([0-9]+(?:\\.[0-9.]+)*(?:-.+)?)\$/", $filename, $args)) {
					if (MvnUtil::isMatchVersionTag($ext,$args[1]) &&
					    MvnUtil::compareVersions($args[1], $version)>0) {
						$version = $args[1];
					}
				}
			}
			@closedir($fid);
		}
		return $version;
	}

	protected static function isMatchVersionTag($ext, $version)
	{
		$ext = strtolower($ext);
		if (!$ext || $ext == '*' || $ext == 'all') return true;
		if ($ext == 'snapshot') {
			return MvnUtil::isSnapshot($version);
		}
		if ($ext == 'release') {
			return MvnUtil::isRelease($version);
		}
		return false;
	}

	public static function getLastVersion($groupId, $artifactId, $ext='*', $needarray = false)
	{
		global $wgMvnRepoPaths;
		$version = '';
		$repository = '';
	
		foreach($wgMvnRepoPaths as $root => $url) {
			$content = MvnUtil::readMetaMetadata(array('lastest','version'), $groupId, $artifactId, $root);
			if (!$content) {
				$content = MvnUtil::readVersionFromFileSystem(array('lastest','version'), $groupId, $artifactId, $root, $ext);
				if (MvnUtil::compareVersions($content,$version)>0) {
					$version = $content;
					$repository = $url;
				}
			}
			else {
				if (array_key_exists('lastest', $content)) {
					foreach($content['lastest'] as $v) {
						if ($v && 
						    MvnUtil::isMatchVersionTag($ext,$v) &&
						    MvnUtil::compareVersions($v,$version)>0) {
							$version = $v;
							$repository = $url;
						}
					}
				}
				elseif (array_key_exists('version', $content)) {
					foreach($content['version'] as $v) {
						if ($v &&
						    MvnUtil::isMatchVersionTag($ext,$v) &&
						    MvnUtil::compareVersions($v,$version)>0) {
							$version = $v;
							$repository = $url;
						}
					}
				}
			}
		}

		if ($needarray && $version) {
			return array( $version, $repository );
		}
		else {
			return $version;
		}
	}

	public static function getTimestamp($groupId, $artifactId, $version, $needarray = false)
	{
		global $wgMvnRepoPaths;
		$date = 0;
		$repository = '';
	
		foreach($wgMvnRepoPaths as $root => $url) {
			$content = MvnUtil::readVersionMetadata(array('lastUpdated'), $groupId, $artifactId, $version, $root);
			if ($content) {
				if (array_key_exists('lastUpdated', $content)) {
					foreach($content['lastUpdated'] as $v) {
						if ($v > $date) {
							$date = $v;
							$repository = $url;
						}
					}
				}
			}
			else {
				$content = MvnUtil::readMetaMetadata(array('lastUpdated'), $groupId, $artifactId, $root);
				if ($content) {
					if (array_key_exists('lastUpdated', $content)) {
						foreach($content['lastUpdated'] as $v) {
							if ($v > $date) {
								$date = $v;
								$repository = $url;
							}
						}
					}
				}
			}
		}
		if ($date>0 && preg_match('/^\\s*([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})\\s*$/', $date, $args)) {
			$year = $args[1];
			$month = $args[2];
			$day = $args[3];
			$hour = $args[4];
			$minute = $args[5];
			$second = $args[6];
			$date = mktime($hour, $minute, $second, $month, $day, $year);
		}
		else {
			$date = 0;
		}

		if ($needarray && $date>0) {
			return array( $date, $repository );
		}
		else {
			return $date;
		}
	}

	public static function getDate($groupId, $artifactId, $version, $needarray = false)
	{
		global $wgMvnRepoPaths;

		$date = MvnUtil::getTimestamp($groupId, $artifactId, $version, $needarray);

		if ($needarray) {
			if ($date[0]>0) {
				return array( date('d M Y H:i:s', $date), $date[1] );
			}
			return '';
		}
		if ($date>0) {
			return date('d M Y H:i:s', $date);
		}
		return '';
	}

	public static function getLastTimestamp($groupId, $artifactId, $ext='*', $needarray = false)
	{
		$lastVersion = MvnUtil::getLastVersion($groupId, $artifactId, $ext);
		if ($lastVersion) {
			return MvnUtil::getTimestamp($groupId, $artifactId, $lastVersion, $needarray);
		}
		return '';
	}

	public static function getLastDate($groupId, $artifactId, $ext='', $needarray = false)
	{
		$lastVersion = MvnUtil::getLastVersion($groupId, $artifactId, $ext);
		if ($lastVersion) {
			return MvnUtil::getDate($groupId, $artifactId, $lastVersion, $needarray);
		}
		return '';
	}

	public static function getFile($fileExtension, $groupId, $artifactId, $version, $modifier='', $needarray = false)
	{
		global $wgMvnRepoPaths;

		$jar = '';
		$repository = '';
		$localRoot = '';

		$mvnMod = '';
		if ($modifier) $mvnMod = "-$modifier";
	
		foreach($wgMvnRepoPaths as $root => $url) {
			$filename = MvnUtil::makeReleaseFilename("$artifactId-$version$mvnMod$fileExtension", $groupId, $artifactId, $version, $root);
			$jarFilename = MvnUtil::makeReleaseFilename("$artifactId-$version$mvnMod$fileExtension", $groupId, $artifactId, $version);
			if (file_exists("$filename")) {
				$jar = $jarFilename;
				$repository = $url;
				$localRoot = $root;
			}
		}

		if ($jar && $needarray) {
			return array( $jar, $repository, $localRoot );
		}
		return $jar;
	}

	public static function getArtifacts( $groupId, $ext='*')
	{
		global $wgMvnRepoPaths;

		$artifacts = array();

		foreach($wgMvnRepoPaths as $root => $url) {
			$path = MvnUtil::makeGroupPath($groupId, $root);
			$did = @opendir($path);
			if ($did) {
				while ($file = readdir($did)) {
					if (!preg_match("/^\\./s", $file)) {
						$metadata = "$path/$file/maven-metadata.xml";
						if (is_file($metadata)) {
							$artifacts[] = "$file";
						}
					}
				}
				closedir($did);
			}
                }
		return $artifacts;
	}

	public static function getLastJar($groupId, $artifactId, $ext='*', $modifier='', $needarray = false)
	{
		$lastVersion = MvnUtil::getLastVersion($groupId, $artifactId, $ext);
		if ($lastVersion) {
			return MvnUtil::getFile('.jar', $groupId, $artifactId, $lastVersion, $modifier, $needarray);
		}
		return '';
	}

	public static function getLastJarSha1($groupId, $artifactId, $ext='*', $modifier='', $needarray = false)
	{
		$lastVersion = MvnUtil::getLastVersion($groupId, $artifactId, $ext);
		if ($lastVersion) {
			return MvnUtil::getFile('.jar.sha1', $groupId, $artifactId, $lastVersion, $modifier, $needarray);
		}
		return '';
	}

	public static function getLastJarMd5($groupId, $artifactId, $ext='*', $modifier='', $needarray = false)
	{
		$lastVersion = MvnUtil::getLastVersion($groupId, $artifactId, $ext);
		if ($lastVersion) {
			return MvnUtil::getFile('.jar.md5', $groupId, $artifactId, $lastVersion, $modifier, $needarray);
		}
		return '';
	}

	public static function getVersionPath($groupId, $artifactId, $version)
	{
		return MvnUtil::makeModuleFilename($version, $groupId, $artifactId);
	}

	public static function getLastVersionPath($groupId, $artifactId, $ext='*')
	{
		$lastVersion = MvnUtil::getLastVersion($groupId, $artifactId, $ext);
		if ($lastVersion) {
			return MvnUtil::makeModuleFilename($lastVersion, $groupId, $artifactId);
		}
		return '';
	}

	public static function getRepository($groupId, $artifactId, $version, $needarray=false)
	{
		if ($version) {
			global $wgMvnRepoPaths;

			$jar = '';
			$repository = '';
	
			foreach($wgMvnRepoPaths as $root => $url) {
				$lpath = MvnUtil::makeModuleFilename($version, $groupId, $artifactId, $root);
				$path = MvnUtil::makeModuleFilename($version, $groupId, $artifactId);
				if (@is_dir($lpath)) {
					if ($needarray) {
						return array( $path, $url );
					}
					else {
						return $path;
					}
				}
			}
		}
		return '';
	}

	public static function getLastVersionRepository($groupId, $artifactId, $ext='*', $needarray=false)
	{
		$lastVersion = MvnUtil::getLastVersion($groupId, $artifactId, $ext);
		if ($lastVersion) {
			return MvnUtil::getRepository($groupId, $artifactId, $lastVersion, $needarray);
		}
		return '';
	}
}

?>
