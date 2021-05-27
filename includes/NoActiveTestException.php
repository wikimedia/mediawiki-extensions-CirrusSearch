<?php

namespace CirrusSearch;

/**
 * Thrown when a user testing method is called that requires an active test,
 * but no test was active. Callers must check the active state on the user
 * testing status before using some methods.
 */
class NoActiveTestException extends \Exception {
}
