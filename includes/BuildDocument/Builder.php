<?php

namespace CirrusSearch\BuildDocument;
use \Title;

/**
 * Build documents!
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * Base class for all things that build documents
 */
abstract class Builder {
	/**
	 * @var \Elastica\Document The document we're building
	 */
	protected $doc;

	/**
	 * @var Title The title to build upon
	 */
	protected $title;

	/**
	 * Constructor
	 * @param \Elastica\Document $d The document we will be building on
	 * @param Title $t The title to build a document for
	 */
	public function __construct( $d, $t ) {
		$this->doc = $d;
		$this->title = $t;
	}

	/**
	 * Build a document
	 * @return \Elastica\Document
	 */
	abstract public function build();
}

/**
 * Utility base class for builders that require parsed data
 */
abstract class ParseBuilder extends Builder {
	/**
	 * @var Content The page content to build from
	 */
	protected $content;

	/**
	 * @var ParserOutput
	 */
	protected $parserOutput;

	/**
	 * Constructor
	 * @param \Elastica\Document $d The document we will be building on
	 * @param Title $t The title to build a document for
	 * @param Content $c The page content to build a document from
	 * @param ParserOutput $p The parser output to build a document from
	 */ 
	public function __construct( $d, $t, $c, $p ) {
		parent::__construct( $d, $t );
		$this->content = $c;
		$this->parserOutput = $p;
	}
}
