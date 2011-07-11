#!/usr/bin/php
<?php

ini_set('display_errors', true);

/**
 * Class for converting an HTML document to an RSS feed by querying it with XPath expressions.
 * 
 * @author Jarno Rantanen <jarno@jrw.fi>
 */
class XPath2RSS {

	const HTTP_CONNECTTIMEOUT	= 60;
	const HTTP_TIMEOUT		= 120;
	const HTTP_USERAGENT		= 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/534.30 (KHTML, like Gecko) Chrome/12.0.742.100 Safari/534.30';

	protected $doc;
	protected $db = array();

	/**
	 * Returns the current contents of the internal items DB.
	 * 
	 * This is public only for debugging purposes; there is no setter.
	 *
	 */
	public function getDB() {

		return $this->db;

	}

	/**
	 * Retrieves the HTML-document from the given URL and loads it into the object.
	 * 
	 * The need to @-suppress errors here because the HTML is VERY likely not well-formed (X)HTML.
	 * 
	 * @param $fromURL Where to get the HTML from
	 */
	public function loadHTML($fromURL) {

		@$this->doc = DOMDocument::loadHTML($this->wget($fromURL));
		
	}

	/**
	 * Runs the given XPath expression on the contained HTML document and returns the result as scalar, if any.
	 * 
	 * @throws Exception if the XPath doesn't match exactly one element
	 * 
	 * @param $expression XPath to run
	 */
	public function xpath($expression) {
	
		$xpath = new DOMXPath($this->doc);
		$result = $xpath->query($expression);

		if (!$result instanceof DOMNodeList)
			throw new Exception("Invalid expression '$expression'");
		else if ($result->length == 0)
			throw new Exception("The expression '$expression' didn't match anything");

		return trim($result->item(0)->textContent);

	}

	/**
	 * Retrieves the resource at the given URL and returns its content as a string.
	 * 
	 * Claims to be a regular browser in the hopes of not looking like a scraper.
	 * 
	 * @throws Exception to signal any errors in retrieval
	 * 
	 * @param $url Where to fetch the data from
	 */
	public function wget($url) {

		if (!function_exists('curl_init')) // fall back to file_get_contents if CURL extension is not available
			return @file_get_contents($url);
		
		$curlOptions = array(
			CURLOPT_USERAGENT	=> self::HTTP_USERAGENT,	// The contents of the "User-Agent: " header to be used in a HTTP request.
			CURLOPT_URL		=> $url,			// The URL to fetch.
			CURLOPT_HEADER		=> false,			// TRUE to include the header in the output.
			CURLOPT_FOLLOWLOCATION	=> false,			// TRUE to follow any "Location: " header that the server sends as part of the HTTP header.
			CURLOPT_CONNECTTIMEOUT	=> self::HTTP_CONNECTTIMEOUT,	// The number of seconds to wait while trying to connect. Use 0 to wait indefinitely.
			CURLOPT_TIMEOUT		=> self::HTTP_TIMEOUT,		// The maximum number of seconds to allow cURL functions to execute.
			CURLOPT_RETURNTRANSFER	=> true,			// TRUE to return the transfer as a string of the return value of curl_exec() instead of outputting it out directly.
			);
		
		$handle = curl_init();
		
		curl_setopt_array($handle, $curlOptions);
		
		$result		= curl_exec($handle);
		$curlInfo	= curl_getinfo($handle);
		
		curl_close($handle);
		
		if (empty($curlInfo['http_code']))
			throw new Exception('Connection error');
		
		if ($curlInfo['http_code'] != 200)
			throw new Exception("HTTP Error: {$curlInfo['http_code']}");
		
		return $result;
	
	}

	/**
	 * Scrapes the contained HTML document with the given XPath expressions and updates the internal item DB.
	 * 
	 * @param $keyXPath String to use as the key 
	 * @param $valXPath String to use as the value
	 */
	public function scrape($keyXPath, $valXPath) {
		
		$key = $this->xpath($keyXPath);
		$val = $this->xpath($valXPath);

		if (!isset($this->db[$key]))
			$this->db[$key] = $val;
		
	}

	/**
	 * Returns the contents of the internal item DB as an RSS feed, as a string.
	 * 
	 * In the templates, %key% and %val% expand to the key and value, respectively.
	 * 
	 * @param $feedTitle     What to report as the feed name
	 * @param $feedURL       What to report as the origin link of the feed
	 * @param $titleTemplate Template for title-tag
	 * @param $descrTemplate Template for description-tag
	 */
	public function getRSS($feedTitle, $feedURL, $titleTemplate = null, $descrTemplate = null) {

		$feedTitle = htmlspecialchars($feedTitle);
		$feedURL   = htmlspecialchars($feedURL);
		
		$rss = "<?xml version=\"1.0\"?>
			<rss version=\"2.0\">
				<channel>
					<title>$feedTitle</title>
					<link>$feedURL</link>
					<description></description>";

		foreach ($this->db as $key => $val) {

			$key         = htmlspecialchars($key);
			$val         = htmlspecialchars($val);
			$expansions  = array('%key%' => $key, '%val%' => $val);
			$title       = str_replace(array_keys($expansions), array_values($expansions), $titleTemplate ? $titleTemplate : '%key%');
			$description = str_replace(array_keys($expansions), array_values($expansions), $descrTemplate ? $descrTemplate : '%val%');
			$description = htmlspecialchars("<!-- XPath2RSS: $val -->$description");
			$rss        .= "<item>
					<title>$title</title>
					<link>$feedURL</link>
					<guid isPermaLink=\"false\">$key</guid>
					<description>$description</description>
				</item>";

		}

		$rss .= '</channel></rss>';

		$doc = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->formatOutput = true;
		$doc->loadXML($rss);

		return $doc->saveXML(); // return a pretty-printed RSS XML string

	}

	/**
	 * Writes the RSS content to the named file.
	 * 
	 * @see self::getRSS()
	 * 
	 * @param $toFile Filename to write to
	 */
	public function writeRSS($toFile, $feedTitle, $feedURL, $titleTemplate = null, $descrTemplate = null) {

		file_put_contents($toFile, $this->getRSS($feedTitle, $feedURL, $titleTemplate, $descrTemplate));

	}

	/**
	 * Reads an RSS file in from the disk, and imports its contents as the internal items DB.
	 * 
	 * This is in essence all items that have been previously added.  Missing files are ignored, so that the first run will be smooth.
	 * 
	 * @param $fromFile Filename to read from
	 */
	public function loadRSS($fromFile) {

		if (!is_readable($fromFile))
			return;

		$doc = new SimpleXMLElement($fromFile, 0, true);
		$this->db = array();

		foreach ($doc->channel->item as $item) {
			
			$key = $item->guid;
			$val = preg_replace('/^.*<!-- XPath2RSS: (.+) -->.*$/', '$1', $item->description);

			$this->db[trim($key)] = trim($val);

		}

	}

	/**
	 * Reads in an ini-file and returns its contents as an associative array.
	 * 
	 * @throws Exception if there's trouble
	 * 
	 * @see http://php.net/manual/en/function.parse-ini-file.php
	 * 
	 * @param $fromFile Filename to read from
	 */
	public static function parseINI($fromFile) {

		if (!is_readable($fromFile))
			throw new Exception("Expected ini-file '$fromFile' was not readable");

		@$ini = parse_ini_file($fromFile);

		if ($ini === false || empty($ini))
			throw new Exception("Expected ini-file '$fromFile' failed to parse");

		return $ini;

	}

}

$argv = $_SERVER['argv'];
$w = new XPath2RSS();

if (!empty($argv[1]) && empty($argv[2])) { // Read config from .ini file and execute

	$conf = XPath2RSS::parseINI($argv[1]);

	$w->loadRSS($conf['toFile']);
	$w->loadHTML($conf['fromURL']);
	$w->scrape($conf['keyXPath'], $conf['valXPath']);
	$w->writeRSS($conf['toFile'], $conf['feedTitle'], $conf['fromURL'], $conf['titleTemplate'], $conf['descrTemplate']);

	exit(0);

} else if (!empty($argv[1]) && $argv[1] === '--test') { // Run in testing mode

	$conf = XPath2RSS::parseINI($argv[2]);

	echo "\nConfiguration from \"{$argv[2]}\":\n\n";

	foreach ($conf as $key => $value)
		echo "\t$key => \"$value\"\n";

	echo "\nCurrent item DB from \"{$conf['toFile']}\":\n\n";

	$w->loadRSS($conf['toFile']);

	foreach ($w->getDB() as $key => $value)
		echo "\t\"$key\" => \"$value\"\n";

	echo "\nXPath matches against \"{$conf['fromURL']}\":\n\n";

	$w->loadHTML($conf['fromURL']);

	echo "\tkeyXPath => \"{$w->xpath($conf['keyXPath'])}\"\n";
	echo "\tvalXPath => \"{$w->xpath($conf['valXPath'])}\"\n\n";

	exit(0);

} else if (!empty($argv[1]) && $argv[1] === '--dry-run') { // Run in dry-run mode

	$conf = XPath2RSS::parseINI($argv[2]);

	$w->loadRSS($conf['toFile']);
	$w->loadHTML($conf['fromURL']);
	$w->scrape($conf['keyXPath'], $conf['valXPath']);

	echo $w->getRSS($conf['feedTitle'], $conf['fromURL'], $conf['titleTemplate'], $conf['descrTemplate']);

	exit(0);

}

?>

XPath2RSS
=========

Usage:

	xpath2rss.php [ --test | --dry-run ] <filename>

Where:

	--test     will trigger test mode, reporting about the expressions used, etc
	--dry-run  will run as normal but will write RSS to stdout instead of disk
	<filename> is the path to an ini-file containing configuration settings

Notes:

	The script uses the CURL-extension when available to spoof its User Agent -header (to not
	look like a scraper).  To get in on the fun, try $ apt-get install php5-curl (assuming a
	Debian-like system).

