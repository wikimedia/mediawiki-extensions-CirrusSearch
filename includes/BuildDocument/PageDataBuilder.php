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
	public function build() {
		switch ( $this->content->getModel() ) {
			case CONTENT_MODEL_CSS:
			case CONTENT_MODEL_JAVASCRIPT:
				// Don't use parser output here. It's useless and leads
				// to weird results. Instead, clear everything. See bug 61752.
				$this->doc->add( 'category', array() );
				$this->doc->add( 'external_link', array() );
				$this->doc->add( 'heading', array() );
				$this->doc->add( 'outgoing_link', array() );
				$this->doc->add( 'template', array() );
				break;
			default:
				$this->categories();
				$this->externalLinks();
				$this->headings();
				$this->outgoingLinks();
				$this->templates();
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

	private function headings() {
		$headings = array();
		$ignoredHeadings = $this->getIgnoredHeadings();
		foreach ( $this->parserOutput->getSections() as $heading ) {
			$heading = $heading[ 'line' ];
			// First strip out things that look like references.  We can't use HTML filtering becase
			// the references come back as <sup> tags without a class.  To keep from breaking stuff like
			//  ==Applicability of the strict mass–energy equivalence formula, ''E'' = ''mc''<sup>2</sup>==
			// we don't remove the whole <sup> tag.  We also don't want to strip the <sup> tag and remove
			// everything that looks like [2] because, I dunno, maybe there is a band named Word [2] Foo
			// or something.  Whatever.  So we only strip things that look like <sup> tags wrapping a
			// refence.  And we do it with regexes because HtmlFormatter doesn't support css selectors.
			$heading = preg_replace( '/<sup>\s*\[\d+\]\s*<\/sup>/', '', $heading );

			// Strip tags from the heading or else we'll display them (escaped) in search results
			$heading = trim( Sanitizer::stripAllTags( $heading ) );

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
			$ignoredHeadings = array();
			if( !$source->isDisabled() ) {
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
