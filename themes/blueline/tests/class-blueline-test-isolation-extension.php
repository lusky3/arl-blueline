<?php
/**
 * PHPUnit extension that keeps the theme suite's tests independent of the
 * order they run in. Registered in phpunit.xml.
 *
 * The suite runs in random order (phpunit.xml's executionOrder), and three
 * things in the stub environment are only safe in file order:
 *
 * - The hook baseline blueline_test_reset_hooks() restores to was captured
 *   by whichever test happened to call it first. A test that added a filter
 *   without resetting, and happened to run before that, became part of the
 *   "clean" state for the rest of the run.
 * - Request state (superglobals, the logged-in user, is_admin() and friends)
 *   was only cleared by the tests that knew to clear the exact keys they had
 *   set, so a later test could see an earlier one's import POST or admin
 *   context.
 * - The fake-WordPress state (posts, post meta, terms) and the option,
 *   transient and cache stores were only reset by tests that asked, so a
 *   test that forgot one could render posts another test registered.
 *
 * This captures the hook baseline once, after every test file has loaded
 * and before the first test runs, and resets every store (request state
 * included) before every test, so no test depends on which test came
 * first. Tests still call the reset helpers from their own setUp(); that
 * remains correct and documents what each test relies on.
 *
 * @package blueline
 */

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Registers the two subscribers described in the file docblock.
 */
final class Blueline_Test_Isolation_Extension implements Extension {

	/**
	 * Called once by PHPUnit after the bootstrap file has loaded.
	 *
	 * @param Configuration       $configuration Run configuration (unused).
	 * @param Facade              $facade        Event registration facade.
	 * @param ParameterCollection $parameters    Extension parameters (unused).
	 * @return void
	 */
	public function bootstrap( Configuration $configuration, Facade $facade, ParameterCollection $parameters ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature fixed by PHPUnit's Extension interface.
		$facade->registerSubscriber(
			new class() implements ExecutionStartedSubscriber {
				/**
				 * Every test file is loaded by now, so the hook store holds
				 * exactly the production file-scope registrations.
				 *
				 * @param ExecutionStarted $event Event (unused).
				 * @return void
				 */
				public function notify( ExecutionStarted $event ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature fixed by the subscriber interface.
					blueline_test_capture_hook_baseline();
				}
			}
		);

		$facade->registerSubscriber(
			new class() implements PreparationStartedSubscriber {
				/**
				 * Runs before each test's setUp(): every store back to empty
				 * (hooks back to the production baseline).
				 *
				 * @param PreparationStarted $event Event (unused).
				 * @return void
				 */
				public function notify( PreparationStarted $event ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature fixed by the subscriber interface.
					blueline_test_reset();
					blueline_test_reset_state();
				}
			}
		);
	}
}
