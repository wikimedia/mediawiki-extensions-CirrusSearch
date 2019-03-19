<?php

namespace CirrusSearch\Search\Fetch;

abstract class FetchedFieldBuilder {
	/** Priority for properties that are doc dependent (e.g. doc size) */
	const DEFAULT_TARGET_PRIORITY = 100;

	/** Priority for properties that are query dependent (highlight in content) */
	const QUERY_DEPENDENT_TARGET_PRIORITY = 200;

	/** Priority for properties that are query dependent and triggered using search keywords (intitle:foo highlight) */
	const EXPERT_SYNTAX_PRIORITY = 300;

	const TARGET_TITLE_SNIPPET = 'title';

	const TARGET_REDIRECT_SNIPPET = 'redirect';

	const TARGET_CATEGORY_SNIPPET = 'category';

	const TARGET_MAIN_SNIPPET = 'mainSnippet';

	/**
	 * Priority for properties are query dependent and triggered using costly search keywords
	 * (for intitle:/foo[0-9]/ intitle:bar we will prefer the highlight on the regex over the simple intitle:bar)
	 */
	const COSTLY_EXPERT_SYNTAX_PRIORITY = 400;

	/** @var string */
	private $fieldName;

	/** @var string */
	private $type;

	/** @var string */
	private $target;

	/** @var int */
	private $priority;

	/**
	 * @param string $type
	 * @param string $fieldName
	 * @param string $target
	 * @param int $priority
	 */
	public function __construct( $type, $fieldName, $target, $priority = self::DEFAULT_TARGET_PRIORITY ) {
		$this->type = $type;
		$this->fieldName = $fieldName;
		$this->target = $target;
		$this->priority = $priority;
	}

	/**
	 * @return string
	 */
	public function getFieldName() {
		return $this->fieldName;
	}

	/**
	 * @return string
	 */
	public function getTarget() {
		return $this->target;
	}

	/**
	 * @return int
	 */
	public function getPriority() {
		return $this->priority;
	}

	/**
	 * @return string
	 */
	public function getType() {
		return $this->type;
	}
}
