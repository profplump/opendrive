#!/usr/local/bin/php
<?php

# Debug
$DEBUG = false;
if ($_ENV['DEBUG']) {
	$DEBUG = true;
}

# Includes
require_once 'opendrive.php';

# Command-line parameters
global $argc;
global $argv;
$LIMIT_PATH = '';
if (($argc > 1) && (strlen($argv[1]) > 0)) {
	$LIMIT_PATH = trim($argv[1]);
}

# Allow local-only scanning
$REMOTE = true;
if (isset($_ENV['REMOTE'])) {
	if (!$_ENV['REMOTE']) {
		$REMOTE = false;
	}
}

# Delete only if specified
$DELETE = false;
if ($_ENV['DELETE']) {
	$DELETE = true;
}

# Open the DB connection
$dbh = dbOpen();

# Find our scan paths
$paths = NULL;
$priority = $dbh->prepare('UPDATE files SET priority = :priority WHERE base = :base AND path LIKE :path');
$scan = $dbh->prepare('UPDATE paths SET last_scan = now() WHERE base = :base AND path = :path');
if (!$LIMIT_PATH) {
	$paths = $dbh->prepare('SELECT base, path, priority, min_age FROM paths WHERE last_scan < now() - scan_age');
	$paths->execute();
} else {
	$paths = $dbh->prepare('SELECT base, path, priority, min_age FROM paths WHERE last_scan < now() - scan_age' .
		' AND path LIKE :path');
	$paths->execute(array(':path' => $LIMIT_PATH));
}
while ($pathsRow = $paths->fetch(PDO::FETCH_ASSOC)) {

	# Grab our globals
	$BASE = $pathsRow['base'];
	$PATH = $pathsRow['path'];
	$PATH_LIKE = $PATH . '/%';
	$MIN_AGE = $pathsRow['min_age'];
	$LOCAL = $BASE . '/' . $PATH;

	# Debug
	if ($DEBUG) {
		echo 'Scanning path: ' . $LOCAL . "\n";
	}

	# Sanity check
	if (!@is_dir($LOCAL) | !@is_readable($LOCAL)) {
		die('Invalid local directory: ' . $LOCAL . "\n");
	}

	# Look for files in the DB that no longer exist or have changed types
	$delete = $dbh->prepare('DELETE FROM files WHERE base = :base AND path = :path');
	$missing = $dbh->prepare('SELECT path, type FROM files WHERE base = :base AND path LIKE :path');
	$missing->execute(array(':base' => $BASE, ':path' => $PATH_LIKE));
	while ($row = $missing->fetch(PDO::FETCH_ASSOC)) {
		$trigger = false;
		$file = $BASE . '/' . $row['path'];

		if ($row['type'] == 'folder') {
			# Ensure the path exists and is a folder
			if (!is_dir($file)) {
				echo 'Missing folder: ' . $file . "\n";
				$trigger = true;
			}
		} else if ($row['type'] == 'ignored') {
			# Silently delete missing ignored files of any type
			if (!file_exists($file)) {
				if ($DEBUG) {
					echo 'Missing ignored file: ' . $file . "\n";
				}
				$trigger = true;
			}
		} else {
			# Ensure the path exists and is a regular file
			if (!is_file($file)) {
				echo 'Missing file: ' . $file . "\n";
				$trigger = true;
			}
		}

		# Delete if global DELETE is enabled
		if ($DELETE && $trigger) {
			$delete->execute(array(':base' => $BASE, ':path' => $row['path']));
		}

		unset($trigger);
		unset($file);
	}
	unset($row);
	unset($missing);
	unset($delete);

	# Grab the file list
	$FILES = array();
	$compAge = time() - ($MIN_AGE * 86400);
	$fsh = NULL;
	try {
		$fsh = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$LOCAL,
				FilesystemIterator::KEY_AS_PATHNAME |
				FilesystemIterator::CURRENT_AS_FILEINFO |
				FilesystemIterator::SKIP_DOTS |
				FilesystemIterator::UNIX_PATHS
			),
			RecursiveIteratorIterator::SELF_FIRST
		);
	} catch (Exception $e) {
		die('Invalid filesystem path: ' . $LOCAL . "\n");
	}
	foreach ($fsh as $path => $file) {

		# Ignore anything that isn't a file and isn't a directory
		if (!$file->isDir() && !$file->isFile()) {
			continue;
		}

		# Ignore file less than $MIN_AGE old
		if ($file->isFile() && $file->getMTime() > $compAge) {
			continue;
		}

		# Keep everything else
		$FILES[] = $file;

		unset($path);
		unset($file);
	}
	unset($compAge);
	unset($fsh);

	# Loop through all the files we found
	$select = $dbh->prepare('SELECT base, path, type FROM files WHERE base = :base AND path = :path');
	$insert = $dbh->prepare('INSERT INTO files (base, path, type, mtime) VALUES (:base, :path, :type, now())');
	$check = $dbh->prepare('SELECT type, size, EXTRACT(EPOCH FROM mtime) AS mtime FROM files' .
		' WHERE base = :base AND path = :path');
	$set_mtime = $dbh->prepare('UPDATE files SET mtime = now() WHERE base = :base AND path = :path');
	$set_size = $dbh->prepare('UPDATE files SET size = :size WHERE base = :base AND path = :path');
	$set_type = $dbh->prepare('UPDATE files SET type = :type WHERE base = :base AND path = :path');
	$check_hash = $dbh->prepare('SELECT hash, EXTRACT(EPOCH FROM hash_time) AS hash_time ' .
		'FROM files WHERE base = :base AND path = :path AND ' .
		'(hash_time IS NULL OR EXTRACT(EPOCH FROM hash_time) < :mtime)');
	$set_hash = $dbh->prepare('UPDATE files SET hash = :hash, hash_time = now() WHERE base = :base AND path = :path');
	foreach ($FILES as $file) {

		# Construct the absolute path
		$path = $file->getPathname();
		$shortPath = substr($path, strlen($BASE) + 1);
		if ($DEBUG) {
			echo 'Scanning: ' . $path . "\n";
		}

		# Pick a file type
		$ext = $file->getExtension();
		$name = strtolower($file->getBasename('.' . $ext));
		$ext = strtolower($ext);

		$type = 'other';
		if (preg_match('/\/\.git(\/.*)?$/', $shortPath)) {
			$type = 'ignored';
		} else if (preg_match('/\/\._/', $shortPath)) {
			$type = 'ignored';
		} else if ($ext == 'lastfindrecode' || $name == 'placeholder' || $ext == 'plexignore') {
			$type = 'ignored';
		} else if ($ext == 'tmp' || $ext == 'gitignore' || $ext == 'ds_store' ||
			preg_match('/^\.smbdelete/', $name) || preg_match('/\.mkv\.\w+$/', $shortPath)) {
				$type = 'ignored';
		} else if (preg_match('/^iTunes\/Album Artwork\/Cache/', $shortPath) || $shortPath == 'iTunes/sentinel') {
			$type = 'ignored';
		} else if ($file->isDir()) {
			$type = 'folder';
			if ($ext == '' && $name == 'cmd') {
				$type = 'ignored';
			}
		} else if ($ext == 'm4v' || $ext == 'mkv' || $ext == 'mp4' || $ext == 'mov' ||
			$ext == 'vob' || $ext == 'iso' || $ext == 'avi') {
				$type = 'video';
		} else if ($ext == 'mp3' || $ext == 'aac' || $ext == 'm4a' || $ext == 'm4b' ||
			$ext == 'm4p' || $ext == 'wav') {
				$type = 'audio';
		} else if ($ext == 'epub' || $ext == 'pdf') {
			$type = 'book';
		} else if ($ext == 'jpg' || $ext == 'png') {
			$type = 'image';
		} else if ($ext == 'gz' || $ext == 'zip' || $ext == 'xz') {
			$type = 'archive';
		} else if ($ext == 'itc' || $ext == 'itl' || $ext == 'strings' || $ext == 'itdb' ||
			$ext == 'xml' || $ext == 'plist' || $ext == 'ipa' || $ext == 'ini') {
				$type = 'database';
		} else if ($ext == 'clip' || $ext == 'riff' || $ext == 'nfo') {
			$type = 'metadata';
		} else if ($ext == 'webloc' || $name == 'skip' || $name == 'season_done' || 
			$name == 'more_number_formats' || $name == 'no_quality_checks' ||
			$name == 'filler' || $name == 'search_name' || $ext == 'disabled' ||
			$name == 'must_match' || $ext == 'fakeshow' || $ext == 'filler' ||
			$name == 'excludes' || $name == 'search_by_date' || $ext == 'twopart') { 
				$type = 'metadata';
		} else if ($ext == 'fake' || $ext == 'txt' || $ext == 'json' ||
			$ext == 'bup' || $ext == 'ifo') {
				$type = 'metadata';
		} else if (preg_match('/\.sparsebundle\//i', $shortPath)) {
			if ($ext == 'bckup' || $ext == 'plist') {
				$type = 'disk';
			} else if (preg_match('/\.sparsebundle\/bands\/\w+$/i', $shortPath)) {
				$type = 'disk';
			} else if (preg_match('/\.sparsebundle\/token$/i', $shortPath)) {
				$type = 'disk';
			}
		}
		if ($type == 'other') {
			die('Unknown file type: ' . $path . ': ' . $name . '|' . $ext . "\n");
		}

		# Add missing paths
		$select->execute(array(':base' => $BASE, ':path' => $shortPath));
		if (!$select->fetch(PDO::FETCH_ASSOC)) {
			if ($DEBUG) {
				echo 'Adding: ' . $path . ': ' . $type . "\n";
			}
			$insert->execute(array(':base' => $BASE, ':path' => $shortPath, ':type' => $type));
		}

		# Grab the DB entry
		$check->execute(array(':base' => $BASE, ':path' => $shortPath));
		$row = $check->fetch(PDO::FETCH_ASSOC);
		if (!$row || !is_array($row)) {
			die('Unable to fetch entry for: ' . $path . "\n");
		}

		# Check the file type
		if ($row['type'] != $type) {
			if ($DEBUG) {
				echo 'Updating type for: ' . $path . "\n";
			}
			$set_type->execute(array(':base' => $BASE, ':path' => $shortPath, ':type' => $type));
		}

		# Skip everything else for 'ignored' files
		if ($type == 'ignored') {
			continue;
		}

		# Check the file mtime
		if ($row['mtime'] < $file->getMTime()) {
			if ($DEBUG) {
				echo 'Updating mtime for: ' . $file . "\n";
			}
			$set_mtime->execute(array(':base' => $BASE, ':path' => $shortPath));
		}

		# Check the file size
		if ($row['size'] != $file->getSize()) {
			if ($DEBUG) {
				echo 'Updating size for: ' . $file . "\n";
			}
			$set_size->execute(array(':base' => $BASE, ':path' => $shortPath, ':size' => $file->getSize()));
		}

		# Update hashes as needed
		if ($type != 'folder') {
			$check_hash->execute(array(':base' => $BASE, ':path' => $shortPath, ':mtime' => $mtime));
			$row = $check_hash->fetch(PDO::FETCH_ASSOC);
			if ($row) {
				if ($DEBUG) {
					echo 'Adding hash: ' . $path . "\n";
				}
				$hash = md5_file($path, false);
				if (strlen($hash) == 32) {
					$set_hash->execute(array(':base' => $BASE, ':path' => $shortPath, ':hash' => $hash));
				} else {
					echo 'Unable to hash: ' . $path . "\n";
					continue;
				}
				unset($hash);
			}
			unset($row);
		}
		unset($mtime);

		unset($name);
		unset($ext);
		unset($type);
		unset($shortPath);
		unset($path);
	}
	unset($FILES);
	unset($select);
	unset($insert);
	unset($check);
	unset($set_mtime);
	unset($set_size);
	unset($check_hash);
	unset($set_hash);

	# Update priorities
	$priority->execute(array(':priority' => $pathsRow['priority'], ':base' => $BASE, ':path' => $PATH_LIKE));
	if ($DEBUG) {
		echo 'Updated ' . $priority->rowCount() . ' rows from ' . $PATH . ' with priority ' . $pathsRow['priority'] . "\n";
	}
	
	# Update the scan time
	$scan->execute(array(':base' => $BASE, ':path' => $PATH));

	unset($BASE);
	unset($PATH);
	unset($PATH_LIKE);
	unset($MIN_AGE);
	unset($LOCAL);
}
unset($priority);
unset($scan);
unset($pathsRow);
unset($paths);

# If remote operations are enabled
if ($REMOTE) {

	# Log in to OpenDrive
	require_once 'opendrive.php';
	$session = NULL;

	# Find our scan paths
	$paths = NULL;
	$scan = $dbh->prepare('UPDATE paths SET remote_last_scan = now() WHERE base = :base AND path = :path');
	if (!$LIMIT_PATH) {
		$paths = $dbh->prepare('SELECT base, path, priority, min_age FROM paths' .
			' WHERE remote_last_scan < now() - remote_scan_age');
	} else {
		$paths = $dbh->prepare('SELECT base, path, priority, min_age FROM paths' .
			' WHERE remote_last_scan < now() - remote_scan_age' .
			' AND path LIKE :path');
		$paths->execute(array(':path' => $LIMIT_PATH));
	}
	while ($pathsRow = $paths->fetch(PDO::FETCH_ASSOC)) {

		# Grab our globals
		$BASE = $pathsRow['base'];
		$PATH = $pathsRow['path'];
		$PATH_LIKE = $PATH . '/%';
		if ($DEBUG) {
			echo 'Remote scan for: ' . $PATH . "\n";
		}

		# Log in to OpenDrive as needed
		if (!$session) {
			$session = login();
		}

		# Validate the existence, type and size of all files we think on the remote system
		$select = $dbh->prepare('SELECT path, type, size FROM files WHERE remote_mtime IS NOT NULL AND path LIKE :path');
		$clear = $dbh->prepare('UPDATE files SET remote_mtime = NULL, remote_hash = NULL WHERE base = :base AND path = :path');
		$select->execute(array(':path' => $PATH_LIKE));
		while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
			$trigger = false;
			if ($DEBUG > 2) {
				echo 'Checking remote: ' . $row['path'] . "\n";
			}

			if ($row['type'] == 'ignored') {
				continue;
			} else if ($row['type'] == 'folder') {
				if (!folderID($session, $row['path'])) {
					echo 'Missing remote folder: ' . $row['path'] . "\n";
					$trigger = true;
				}
			} else {
				$data = fileInfo($session, $row['path']);
				if (!$data) {
					echo 'Missing remote file: ' . $row['path'] . "\n";
					$trigger = true;
				} else if ($data['Size'] != $row['size']) {
					echo 'Wrong size remote file: ' . $row['path'] . ' (' . $data['size'] . '/' . $row['Size'] . ")\n";
					$trigger = true;
				}
				unset($data);
			}

			if ($DELETE && $trigger) {
				$clear->execute(array(':base' => $BASE, ':path' => $row['path']));
			}
			unset($trigger);
		}
		unset($select);
		unset($clear);
		unset($row);

		# Update the remote scan time
		$scan->execute(array(':base' => $BASE, ':path' => $PATH));

		# Cleanup
		unset($PATH);
		unset($PATH_LIKE);
	}

	# Logout of OpenDrive as needed
	if ($session) {
		logout($session);
	}
	unset($session);
}
unset($scan);
unset($pathsRow);
unset($paths);

# Cleanup
unset($dbh);

?>
