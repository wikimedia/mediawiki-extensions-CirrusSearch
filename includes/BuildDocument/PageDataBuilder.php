<?php

namespace CirrusSearch\BuildDocument;
use \Category;
use \Sanitizer;
use \Title;

/**
 * Add everything to a page that doesn't require page text.
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

class PageDataBuilder extends ParseBuilder {
	private $parseFuncs = array(
		'categories',
		'externalLinks',
		'fileText',
		'headings',
		'outgoingLinks',
		'templates',
	);

	public function build() {
		switch ( $this->content->getModel() ) {
			case CONTENT_MODEL_CSS:
			case CONTENT_MODEL_JAVASCRIPT:
				// Don't use parser output here. It's useless and leads
				// to weird results. Instead, clear everything. See bug 61752.
				$this->doc->add( 'category', array() );
				$this->doc->add( 'outgoing_link', array() );
				$this->doc->add( 'template', array() );
				$this->doc->add( 'file_text', array() );
				$this->doc->add( 'heading', array() );
				break;
			default:
				foreach( $this->parseFuncs as $f ) {
					$this->$f();
				}
		}

		return $this->doc;
	}

	private function categories() {
		$categories = array();
		foreach ( array_keys( $this->parserOutput->getCategories() ) as $key ) {
			$categories[] = Category::newFromName( $key )->getTitle()->getText();
		}
		$this->doc->add( 'category', $categories );
	}

	private function externalLinks() {
		$this->doc->add( 'external_link',
			array_keys( $this->parserOutput->getExternalLinks() )
		);
	}

	private function outgoingLinks() {
		$outgoingLinks = array();
		foreach ( $this->parserOutput->getLinks() as $linkedNamespace => $namespaceLinks ) {
			foreach ( array_keys( $namespaceLinks ) as $linkedDbKey ) {
				$outgoingLinks[] =
					Title::makeTitle( $linkedNamespace, $linkedDbKey )->getPrefixedDBKey();
			}
		}
		$this->doc->add( 'outgoing_link', $outgoingLinks );
	}

	private function templates() {
		$templates = array();
		foreach ( $this->parserOutput->getTemplates() as $tNS => $templatesInNS ) {
			foreach ( array_keys( $templatesInNS ) as $tDbKey ) {
				$templateTitle = Title::makeTitleSafe( $tNS, $tDbKey );
				if ( $templateTitle && $templateTitle->exists() ) {
					$templates[] = $templateTitle->getPrefixedText();
				}
			}
		}
		$this->doc->add( 'template', $templates );
	}

	private function fileText() {
		// Technically this doesn't require the parserOutput but it is heavyweight
		// so we should only do it on article change.
		if ( $this->title->getNamespace() == NS_FILE ) {
			$file = wfLocalFile( $this->title );
			if ( $file && $file->exists() && $file->getHandler() ) {
				$fileText = $file->getHandler()->getEntireText( $file );
				if ( $fileText ) {
					$this->doc->add( 'file_text', $fileText );
				}
			}
		}
	}

	private function headings() {
		$headings = array();
		$ignoredHeadings = $this->getIgnoredHeadings();
		foreach ( $this->parserOutput->getSections() as $heading ) {
			$heading = $heading[ 'line' ];
			// Strip tags from the heading or else we'll display them (escaped) in search results
			$heading = Sanitizer::stripAllTags( $heading );
			// Note that we don't take the level of the heading into account - all headings are equal.
			// Except the ones we ignore.
			if ( !in_array( $heading, $ignoredHeadings ) ) {
				$headings[] = $heading;
			}
		}
		$this->doc->add( 'heading', $headings );
	}

	private function getIgnoredHeadings() {
		static $ignoredHeadings = null;
		if ( $ignoredHeadings === null ) {
			$source = wfMessage( 'cirrussearch-ignored-headings' )->inContentLanguage();
			if( $source->isDisabled() ) {
				$ignoredHeadings = array();
			} else {
				$lines = explode( "\n", $source->plain() );
				$lines = preg_replace( '/#.*$/', '', $lines ); // Remove comments
				$lines = array_map( 'trim', $lines );          // Remove extra spaces
				$lines = array_filter( $lines );               // Remove empty lines
				$ignoredHeadings = $lines;               // Now we just have headings!
			}
		}
		return $ignoredHeadings;
	}
}
