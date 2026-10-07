<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCP\AppFramework\IAppContainer;
use RuntimeException;

/**
 * Nextcloud's server container, as far as OCP's classes reach it through \OC::$server.
 */
final class TestServer {
	/** @var list<string> the apps whose container was asked for */
	public array $appContainerRequests = [];

	/** @param array<class-string, object> $services */
	public function __construct(
		private array $services,
		private ?IAppContainer $appContainer = null,
	) {
	}

	public function get(string $id): object {
		if (!isset($this->services[$id])) {
			throw new RuntimeException("The test server has no service {$id}.");
		}

		return $this->services[$id];
	}

	public function getRegisteredAppContainer(string $appName): IAppContainer {
		$this->appContainerRequests[] = $appName;
		if ($this->appContainer === null) {
			throw new RuntimeException('The test server has no app container.');
		}

		return $this->appContainer;
	}
}
