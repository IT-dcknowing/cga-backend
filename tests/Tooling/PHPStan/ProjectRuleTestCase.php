<?php

namespace Tests\Tooling\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Base des tests des règles PHPStan du projet, avec Larastan chargé comme dans phpstan.neon.
 *
 * Le conteneur de PHPStan installe ses gestionnaires d'erreurs et d'exceptions en démarrant. Il
 * démarre donc avant les tests de la classe, et ses gestionnaires sont retirés après eux : sinon
 * PHPUnit les prend pour des gestionnaires laissés par un test, y compris dans les tests Laravel
 * qui suivent dans le même processus (exécution parallèle).
 *
 * @template TRule of Rule
 *
 * @extends RuleTestCase<TRule>
 */
abstract class ProjectRuleTestCase extends RuleTestCase
{
    /**
     * @var callable|null
     */
    private static $errorHandlerBefore;

    /**
     * @var callable|null
     */
    private static $exceptionHandlerBefore;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$errorHandlerBefore = self::currentErrorHandler();
        self::$exceptionHandlerBefore = self::currentExceptionHandler();
        self::getContainer();
    }

    public static function tearDownAfterClass(): void
    {
        for ($attempt = 0; $attempt < 10 && self::currentErrorHandler() !== self::$errorHandlerBefore; $attempt++) {
            restore_error_handler();
        }

        for ($attempt = 0; $attempt < 10 && self::currentExceptionHandler() !== self::$exceptionHandlerBefore; $attempt++) {
            restore_exception_handler();
        }

        parent::tearDownAfterClass();
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/../../../vendor/larastan/larastan/extension.neon'];
    }

    private static function currentErrorHandler(): ?callable
    {
        $current = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $current;
    }

    private static function currentExceptionHandler(): ?callable
    {
        $current = set_exception_handler(static fn (): null => null);
        restore_exception_handler();

        return $current;
    }
}
