<?php

/**
 * Filters a log file, removing all lines matching given filter.
 *
 * Usage: jamp filter-log <filter rule regex> <log filename>
 *    jamp filter-log -d <log filename>
 *    jamp filter-log -r <log filename>
 *    jamp filter-log -s <log filename>
 *
 *   -d <log filename> Runs the default filter for null characters and empty
 *                     lines
 *   -r <log filename> Reuses all previously saved filters to filter log
 *   -s <log filename> Scans for common lines without filtering.
 *
 * Every line of the log is compared against all regex rules. If it matches a
 * rule, it will not appear in the filtered version of the log.
 */

jampUse('jampEcho');

define('MODE_DEFAULT_FILTERS', 'default filters');
define('MODE_DO_FILTER', 'do filter');
define('MODE_REUSE_FILTERS', 'reuse filters');
define('MODE_SCAN', 'scan');
define('CHUNK_SIZE', 4096);

if (!isset($argv[1]) || !isset($argv[2]) || isset($argv[3])) {
	passthru('jamp usage filter-log');
	exit;
}

$state = initState();

// Validate the state
if (!is_file($state->file)) {
	throw new Error('Could not find file ' . $argv[2]);
}
foreach ($state->filters as $filter) {
	if (@preg_match($filter, '') === false) {
		throw new Error("Invalid regex: \"$filter\"");
	}
}

run($state);

function run($state) {
	switch ($state->mode) {
		case MODE_DEFAULT_FILTERS: return doDefaultFilter($state);
		case MODE_SCAN: return doScan($state);
		case MODE_DO_FILTER: return doFilter($state);
		default: echo 'Not implemented.' . PHP_EOL; return;
	}
}

function initState() {
	global $argv;
	$mode = getMode();
	$state = (object)[
		'mode' => getMode(),
		'file' => realpath($mode === MODE_DO_FILTER ? $argv[1] : $argv[2])
	];
	if ($state->mode === MODE_DO_FILTER) {
		$state->filters = [formatFilter($argv[2])];
	} elseif ($state->mode === MODE_REUSE_FILTERS) {
		$state->filters = getFiltersFromFile();
	} else {
		$state->filters = [];
	}
	return $state;
}

function getMode() {
	global $argv;
	switch ($argv[1]) {
		case '-d': return MODE_DEFAULT_FILTERS;
		case '-r': return MODE_REUSE_FILTERS;
		case '-s': return MODE_SCAN;
		default: return MODE_DO_FILTER;
	}
}

function formatFilter($filterInput) {
	$shouldAddDelimiters = $filterInput[0] !== $filterInput[-1]
	&& strlen($filterInput) > 1;
	$delimited = $shouldAddDelimiters ? "/$filterInput/" : $filterInput;
	$filtered = preg_replace('/\$ip\$/', '\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}',
	$delimited);
	return $filtered;
}

function doDefaultFilter($state) {
	extract(setupFiles($state)); // Produces $inputFile, $tempFile, $filteredFile
	$inputHandle = fopen($inputFile, 'rb');
	if (!$inputFile) {
		throw new Error("Could not open: $inputFile");
	}
	$tempHandle = fopen($tempFile, 'wb');
	if (!$tempHandle) {
		fclose($inputHandle);
		throw new Error("Could not open: $tempHandle");
	}

	$initialSize = filesize($inputFile);
	if ($initialSize < 1) {
		fclose($inputHandle);
		fclose($tempHandle);
		throw new Error("File is empty: $inputFile");
	}
	$bytesRead = 0;
	$nullChar = "\0";
	$linefeed = "\r";
	$chunk = fread($inputHandle, CHUNK_SIZE);
	$last = '';
	while ($chunk !== false && !feof($inputHandle)) {
		$bytesRead += CHUNK_SIZE;
		echo round(100 * $bytesRead / $initialSize) . "%\r";
		$newChunk = '';
		$size = strlen($chunk);
		for ($i=0;$i<$size;$i++) {
			$char = $chunk[$i];
			$lastTwo = $last . $char;
			if ($char === $nullChar || $char === $linefeed
			|| preg_match('/(\s\s|\n\n)/', $lastTwo)) {
				continue;
			}
			$last = $char;
			$newChunk .= $char;
		}
		fwrite($tempHandle, $newChunk);
		$chunk = fread($inputHandle, CHUNK_SIZE);
	}
	testForFullFileRead($inputHandle, $inputFile);
	fclose($tempHandle);
	fclose($inputHandle);
	echo PHP_EOL;
	$newSize = filesize($tempFile);
	$reduction = $initialSize - $newSize;
	logSizeReduction($state, $reduction);
	logFiltersUsed($state);
	rename($tempFile, $filteredFile);
	echo 'Complete. ' . $reduction . ' bytes removed.' . PHP_EOL;
}

function doScan($state) {
	extract(setupFiles($state)); // Produces $inputFile, $tempFile, $filteredFile
	$size = filesize($inputFile);
	if ($size < 1) {
		echo "File is empty: $inputFile" . PHP_EOL;
		return;
	}
	$inputHandle = fopen($inputFile, 'rb');
	$bytesRead = 0;
	$line = fgets($inputHandle, CHUNK_SIZE);
	$commonLines = [];
	while ($line !== false) {
		$bytesRead += strlen($line);
		echo round(100 * $bytesRead / $size) . "%\r";
		$lineSig = getLineSignature($line);
		if (isset($commonLines[$lineSig])) {
			$commonLines[$lineSig]['count'] += 1;
		}
		else {
			$commonLines[$lineSig]['first'] = $line;
			$commonLines[$lineSig]['count'] = 1;
		}
		$line = fgets($inputHandle, CHUNK_SIZE);
	}
	testForFullFileRead($inputHandle, $inputFile);
	fclose($inputHandle);
	echo PHP_EOL;
	echo getCommonLinesSummary($commonLines);
}

function testForFullFileRead($handle, $filename) {
	if (!feof($handle)) {
		echo "Warning: file was not completely scanned: $filename" . PHP_EOL;
	}
}

function createFilter($state) {
	if (count($state->filters) === 1) {
		$filter = $state->filters[0];
		return function($line) use ($filter) {
			return 1 === preg_match($filter, trim($line)) ? false : $line;
		};
	}
	elseif (count($state->filters) > 1) {
		$filters = $state->filters;
		return function($line) use ($filters) {
			foreach($filters as $filter) {
				if (1 === preg_match($filter, trim($line))) {
					return false;
				}
			}
			return $line;
		};
	}
	else {
		return function ($line) {
			return $line;
		};
	}
}

function doFilter($state) {
	extract(setupFiles($state)); // Produces $inputFile, $filteredFile, $tempFile
	$inputHandle = fopen($inputFile, 'rb');
	if (!$inputHandle) {
		throw new Error("Could not open $inputFile");
	}
	$tempHandle = fopen($tempFile, 'w');
	if (!$tempHandle) {
		fclose($inputHandle);
		throw new Error("Could not create temp file: $tempFile");
	}
	$initialSize = filesize($inputFile);
	if ($initialSize < 1) {
		fclose($tempHandle);
		fclose($inputHandle);
		throw new Error("File is empty: $inputFile");
	}
	$bytesRead = 0;
	$line = fgets($inputHandle, CHUNK_SIZE);
	$filter = createFilter($state);
	while ($line !== false) {
		$bytesRead += strlen($line);
		echo round(100 * $bytesRead / $initialSize) . "%\r";
		$filtered = $filter($line);
		if ($filtered) {
			fwrite($tempHandle, $filtered);
		}
		$line = fgets($inputHandle);
	}
	testForFullFileRead($inputHandle, $inputFile);
	fclose($tempHandle);
	fclose($inputHandle);
	$newSize = filesize($tempFile);
	$reduction = $initialSize - $newSize;
	logSizeReduction($state, $reduction);
	logFiltersUsed($state);
	rename($tempFile, $filteredFile);
	echo PHP_EOL . 'Complete. ' . $reduction . ' bytes removed.' . PHP_EOL;
}

function getCommonLinesSummary($commonLines) {
	usort($commonLines, function($a, $b) {
		return $b['count'] - $a['count'];
	});
	$total = count($commonLines);

	if ($total < 1) {
		return 'No lines found.' . PHP_EOL;
	}

	$index = 0;
	$output = "";
	$col1Width = strlen($commonLines[0]['count'] . '');
	$output .= str_pad('#', $col1Width, ' ', STR_PAD_LEFT) . ' ' . 'Example line'
	. PHP_EOL;

	while($index < $total && $index < 10) {
		$output .= str_pad(
			$commonLines[$index]['count'],
			$col1Width,
			' ',
			STR_PAD_LEFT
			) 
		. ' ' . $commonLines[$index]['first'];
		$index++;
	}
	return $output;
}

function getLineSignature($line) {
	$signature = "";
	$index = strpos($line, ' ');
	while ($index !== false) {
		$signature .= $index . '-';
		$index = strpos($line, ' ', ($index + 1));
	}
	return $signature;
}

function logFiltersUsed($state) {
	$filtersFile = getFiltersFilepath($state);
	$handle = fopen($filtersFile, 'a+');
	if (!$handle) {
		echo "Warning: could not add filter to:" . PHP_EOL . $filtersFile . PHP_EOL;
		return;
	}
	$filters = $state->mode === MODE_DEFAULT_FILTERS
	? ['Default filters']
	: $state->filters;
	fwrite($handle, 'Filters used:' . PHP_EOL . implode(PHP_EOL, $filters)
	. PHP_EOL . PHP_EOL);
	fclose($handle);
}

function logSizeReduction($state, $sizeReduction) {
	$sizeLog = realpath($state->file) . '-size-reductions.txt';
	$handle = fopen($sizeLog, 'a+');
	if (!$handle) {
		echo 'Warning: could not log size reduction to:' . PHP_EOL . $sizeLog
		. PHP_EOL;
		return;
	}
	fwrite($handle, "Filtered out $sizeReduction bytes." . PHP_EOL);
	fclose($handle);
}

function setupFiles($state) {
	$inputFile = getAbsolutePath($state->file);
	$filteredFile = addPrefixToFilepath($inputFile, "filtered-");

	if (is_file($filteredFile)) {
		$inputFile = $filteredFile;
	}

	$tempFile = addPrefixToFilepath($filteredFile, ".");
	if (file_exists($tempFile) && is_file($tempFile)) {
		unlink($tempFile);
	}
	if (file_exists($tempFile)) {
		throw new Error("Tried to write temp file $tempFile but it appears to be "
		. "a directory.");
	}

	return [
		'inputFile' => $inputFile,
		'filteredFile' => $filteredFile,
		'tempFile' => $tempFile
	];
}

function getFilters($state) {
	$filtersFile = getFiltersFilepath($state);
	if (!is_file($filtersFile)) {
		throw new Error("Could not find saved filters: $filtersFile");
	}
	return array_filter(file($filtersFile, FILE_IGNORE_NEW_LINES));
}

function getFiltersFilepath($state) {
	return realpath($state->file) . '-filters-used.txt';
}

function getAbsolutePath($path) {
	$dir = realpath(dirname($path));
	if (!$dir) {
		throw new Error("Could not determine the directory of: $path. The "
		. "directory must exist.");
	}
	$name = basename($path);
	return $dir . DIRECTORY_SEPARATOR . $name;
}

function addPrefixToFilepath($path, $prefix) {
	return dirname($path) . DIRECTORY_SEPARATOR . $prefix . basename($path);
}

