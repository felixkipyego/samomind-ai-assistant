<?php
/**
 * The widget snippet sanitiser accepts the dashboard's documented shape
 * and nothing else.
 *
 * @package SAMOMIND
 */

class EmbedTest extends SAMOMIND_TestCase {

	// The dashboard's snippet, character for character (SamoMind RUNBOOK 8).
	const DOCUMENTED = "<script\n  src=\"https://widget.samomind.com/widget.js\"\n  data-site-key=\"pk_live_abc\"\n  data-base-url=\"https://api.samomind.com\"\n  async\n></script>";

	public function test_accepts_the_documented_snippet() {
		$this->assertSame(
			array(
				'src'           => 'https://widget.samomind.com/widget.js',
				'data-site-key' => 'pk_live_abc',
				'data-base-url' => 'https://api.samomind.com',
				'async'         => true,
			),
			SAMOMIND_Embed::parse( self::DOCUMENTED )
		);
	}

	public function test_accepts_single_line_single_quotes_and_defer() {
		$this->assertSame(
			array(
				'src'           => 'https://widget.samomind.com/widget.js',
				'data-site-key' => 'pk_live_abc',
				'defer'         => true,
			),
			SAMOMIND_Embed::parse( "  <script src='https://widget.samomind.com/widget.js' data-site-key=pk_live_abc defer></script>  " )
		);
	}

	public function test_empty_snippet_is_off() {
		$this->assertSame( array(), SAMOMIND_Embed::parse( "  \n " ) );
	}

	/**
	 * @dataProvider refused
	 */
	public function test_refuses( $snippet, $code ) {
		$result = SAMOMIND_Embed::parse( $snippet );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function refused() {
		$src = 'src="https://widget.samomind.com/widget.js"';
		return array(
			'inline script'           => array( '<script>alert(document.cookie)</script>', 'invalid_shape' ),
			'inline code with src'    => array( "<script $src>alert(1)</script>", 'invalid_shape' ),
			'two tags'                => array( "<script $src></script><script src=\"https://evil.example/x.js\"></script>", 'invalid_shape' ),
			'trailing markup'         => array( "<script $src></script><img src=x onerror=alert(1)>", 'invalid_shape' ),
			'other tag'               => array( '<iframe src="https://widget.samomind.com/"></iframe>', 'invalid_shape' ),
			'no closing tag'          => array( "<script $src>", 'invalid_shape' ),
			'other host'              => array( '<script src="https://evil.example/widget.js"></script>', 'invalid_src' ),
			'look-alike host'         => array( '<script src="https://widget.samomind.com.evil.example/widget.js"></script>', 'invalid_src' ),
			'user info trick'         => array( '<script src="https://widget.samomind.com@evil.example/widget.js"></script>', 'invalid_src' ),
			'plain http'              => array( '<script src="http://widget.samomind.com/widget.js"></script>', 'invalid_src' ),
			'protocol relative'       => array( '<script src="//widget.samomind.com/widget.js"></script>', 'invalid_src' ),
			'javascript url'          => array( '<script src="javascript:alert(1)"></script>', 'invalid_src' ),
			'port'                    => array( '<script src="https://widget.samomind.com:8443/widget.js"></script>', 'invalid_src' ),
			'missing src'             => array( '<script data-site-key="pk_live_abc" async></script>', 'invalid_src' ),
			'onload handler'          => array( "<script $src onload=\"alert(1)\"></script>", 'attribute_not_allowed' ),
			'onerror handler'         => array( "<script $src ONERROR=alert(1)></script>", 'attribute_not_allowed' ),
			'type attribute'          => array( "<script $src type=\"module\"></script>", 'attribute_not_allowed' ),
			'bare data prefix'        => array( "<script $src data-=\"x\"></script>", 'attribute_not_allowed' ),
			'duplicate src'           => array( "<script $src src=\"https://evil.example/x.js\"></script>", 'duplicate_attribute' ),
			'async with a value'      => array( "<script $src async=\"onload\"></script>", 'invalid_attribute' ),
			'quote breaking attempt'  => array( "<script $src data-x=\"a\"\"></script>", 'invalid_shape' ),
		);
	}
}
