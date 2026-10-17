<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit;

use OCA\PaperlessSync\Service\PaperlessApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SensitiveParameter;

/**
 * Nextcloud's log writes an exception with the arguments of every frame of its trace. PHP leaves
 * an argument out only when its parameter is marked #[\SensitiveParameter], and an attribute is not
 * inherited: every method that takes the API token or the secret of the webhook marks it itself,
 * the interface and the class that implements it alike.
 */
final class SensitiveParametersTest extends TestCase {
	private const LIB = __DIR__ . '/../../lib';

	/** @return array<string, array{class-string, string, string}> the class, method and name of every parameter that takes the token */
	public static function parametersWithTheToken(): array {
		$parameters = [];
		foreach (self::classes() as $class) {
			foreach ((new ReflectionClass($class))->getMethods() as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				foreach ($method->getParameters() as $parameter) {
					$type = $parameter->getType();
					// A string named after the token, such as $token or $tokenCandidate, or after the secret
					// of the webhook, which comes in the header Authorization; $tokenConfigured is a bool.
					if ($type instanceof ReflectionNamedType && $type->getName() === 'string' && preg_match('/token|secret|authorization/i', $parameter->getName()) === 1) {
						$parameters[self::label($method, $parameter->getName())] = [$class, $method->getName(), $parameter->getName()];
					}
				}
			}
		}
		// The options of a request carry the token in the header Authorization.
		$send = new ReflectionMethod(PaperlessApiService::class, 'send');
		$parameters[self::label($send, 'options')] = [PaperlessApiService::class, 'send', 'options'];
		ksort($parameters);

		return $parameters;
	}

	/** @param class-string $class */
	#[DataProvider('parametersWithTheToken')]
	public function testTokenIsLeftOutOfEveryTrace(string $class, string $method, string $name): void {
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
			if ($parameter->getName() === $name) {
				self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class), "Mark \${$name} of {$class}::{$method}() with #[\\SensitiveParameter].");
			}
		}
	}

	/** The search above finds the parameters that it must find, so that it can't pass empty-handed. */
	public function testEveryKnownParameterWithTheTokenIsChecked(): void {
		self::assertSame([
			'ConfigService::resolveToken($tokenCandidate)',
			'ConfigService::save($tokenCandidate)',
			'ConfigService::storeToken($token)',
			'ConfigService::validate($tokenCandidate)',
			'PaperlessApiService::account($token)',
			'PaperlessApiService::failureMessage($token)',
			'PaperlessApiService::headersForToken($token)',
			'PaperlessApiService::listCheck($token)',
			'PaperlessApiService::preflight($token)',
			'PaperlessApiService::probe($token)',
			'PaperlessApiService::requestPage($token)',
			'PaperlessApiService::send($options)',
			'PaperlessApiService::send($token)',
			'PaperlessApiService::testConnection($token)',
			'PaperlessApiService::trashCheck($token)',
			'PaperlessClientInterface::preflight($token)',
			'PaperlessClientInterface::testConnection($token)',
			'SettingsController::checks($token)',
			'SettingsController::save($token)',
			'SettingsController::test($token)',
			'WebhookService::verify($authorization)',
		], array_keys(self::parametersWithTheToken()));
	}

	/** @return list<class-string> every class and interface of the app */
	private static function classes(): array {
		$classes = [];
		$root = realpath(self::LIB);
		self::assertIsString($root);
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$relative = substr($file->getPathname(), strlen($root) + 1, -4);
			/** @var class-string $class */
			$class = 'OCA\\PaperlessSync\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
			$classes[] = $class;
		}
		sort($classes);

		return $classes;
	}

	private static function label(ReflectionMethod $method, string $parameter): string {
		return $method->getDeclaringClass()->getShortName() . '::' . $method->getName() . '($' . $parameter . ')';
	}
}
