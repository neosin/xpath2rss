<?php

define('XPATH2RSS_TEST', true);

require_once dirname(__FILE__) . '/../xpath2rss.php';

/**
 * Test class for XPath2RSS.
 * 
 * Generated by PHPUnit.
 * 
 * @author Jarno Rantanen <jarno@jrw.fi>
 */
class XPath2RSSTest extends PHPUnit_Framework_TestCase {

	private $fixtPath;

	/**
	 * Sets up the fixture, for example, opens a network connection.
	 * This method is called before a test is executed.
	 */
	protected function setUp() {

		$this->fixtPath = 'file://' . dirname(__FILE__) . '/';

	}

	public function testBasicXPath() {

		$x = new XPath2RSS();
		$x->loadHTML($this->fixtPath . 'test.html');

		$this->assertEquals('Hello', $actual = $x->xpath('//p'));

	}

}