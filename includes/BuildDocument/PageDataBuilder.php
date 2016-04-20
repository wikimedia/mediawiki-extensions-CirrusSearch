<?php

namespace CirrusSearch\BuildDocument;

use Category;
use Sanitizer;
use Title;
use CirrusSearch\Util;

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
	/**
	 * @return \Elastica\Document
	 */
	public function build() {
		switch ( $this->content->getModel() ) {
			case CONTENT_MODEL_CSS:
			case CONTENT_MODEL_JAVASCRIPT:
				// Don't use parser output here. It's useless and leads
				// to weird results. Instead, clear everything. See bug 61752.
				$this->doc->set( 'category', array() );
				$this->doc->set( 'external_link', array() );
				$this->doc->set( 'heading', array() );
				$this->doc->set( 'outgoing_link', array() );
				$this->doc->set( 'template', array() );
				break;
			default:
				$this->categories();
				$this->externalLinks();
				$this->headings();
				$this->outgoingLinks();
				$this->templates();
				$this->wikidataInfo();
		}

		// All content types have a language
		$this->doc->set( 'language',
			$this->title->getPageLanguage()->getCode() );

		return $this->doc;
	}

	private function categories() {
		$categories = array();
		foreach ( array_keys( $this->parserOutput->getCategories() ) as $key ) {
			$categories[] = Category::newFromName( $key )->getTitle()->getText();
		}
		$this->doc->set( 'category', $categories );
	}

	private function externalLinks() {
		$this->doc->set( 'external_link',
			array_keys( $this->parserOutput->getExternalLinks() )
		);
	}

	private function outgoingLinks() {
		$outgoingLinks = array();
		foreach ( $this->parserOutput->getLinks() as $linkedNamespace => $namespaceLinks ) {
			foreach ( array_keys( $namespaceLinks ) as $linkedDbKey ) {
				$outgoingLinks[] =
					Title::makeTitle( $linkedNamespace, $linkedDbKey )->getPrefixedDBkey();
			}
		}
		$this->doc->set( 'outgoing_link', $outgoingLinks );
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
		$this->doc->set( 'template', $templates );
	}

	private function headings() {
		$headings = array();
		$ignoredHeadings = $this->getIgnoredHeadings();
		foreach ( $this->parserOutput->getSections() as $heading ) {
			$heading = $heading[ 'line' ];
			// First strip out things that look like references.  We can't use HTML filtering because
			// the references come back as <sup> tags without a class.  To keep from breaking stuff like
			//  ==Applicability of the strict mass–energy equivalence formula, ''E'' = ''mc''<sup>2</sup>==
			// we don't remove the whole <sup> tag.  We also don't want to strip the <sup> tag and remove
			// everything that looks like [2] because, I dunno, maybe there is a band named Word [2] Foo
			// or something.  Whatever.  So we only strip things that look like <sup> tags wrapping a
			// reference.  And since the data looks like:
			//      Reference in heading <sup>&#91;1&#93;</sup><sup>&#91;2&#93;</sup>
			// we can not really use HtmlFormatter as we have no suitable selector.

			// Some wikis wrap the brackets in a span:
			// http://en.wikipedia.org/wiki/MediaWiki:Cite_reference_link
			$heading = preg_replace( '/<\/?span>/', '', $heading );
			// Normalize [] so the following regexp would work.
			$heading = preg_replace( [ '/&#91;/', '/&#93;/' ], [ '[', ']' ], $heading );
			$heading = preg_replace( '/<sup>\s*\[\s*\d+\s*\]\s*<\/sup>/is', '', $heading );

			// Strip tags from the heading or else we'll display them (escaped) in search results
			$heading = trim( Sanitizer::stripAllTags( $heading ) );

			// Note that we don't take the level of the heading into account - all headings are equal.
			// Except the ones we ignore.
			if ( !in_array( $heading, $ignoredHeadings ) ) {
				$headings[] = $heading;
			}
		}
		$this->doc->set( 'heading', $headings );
	}

	/**
	 * @return string[]
	 */
	private function getIgnoredHeadings() {
		static $ignoredHeadings = null;
		if ( $ignoredHeadings === null ) {
			$source = wfMessage( 'cirrussearch-ignored-headings' )->inContentLanguage();
			$ignoredHeadings = array();
			if( !$source->isDisabled() ) {
				$lines = Util::parseSettingsInMessage( $source->plain() );
				$ignoredHeadings = $lines;               // Now we just have headings!
			}
		}
		return $ignoredHeadings;
	}

	/**
	 * Add wikidata information to the index if wikibase is installed on this wiki.
	 */
	private function wikidataInfo() {
		$wikibaseItem = $this->parserOutput->getProperty( 'wikibase_item' );
		if ( $wikibaseItem !== false ) {
			$this->doc->set( 'wikibase_item', $wikibaseItem );
		}
	}
}
