<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Controller;

use OCA\PaperlessSync\Controller\FileActionController;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\FileActions;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FileActionControllerTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	private const SENDER = 'alice';

	private ConfigService $configService;
	private TestStorage $storage;
	private TestStateRepository $state;
	private ?string $user = self::SENDER;
	private FileActionController $controller;

	protected function setUp(): void {
		$this->configService = $this->configService();
		$this->configService->save(['send_group' => 'scanners', 'batch_size' => 2] + self::validSettings(), 'secret-token');
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $userId, string $groupId): bool => $groupId === 'scanners' && in_array($userId, [self::SENDER, TestStorage::USER], true));
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->user === null) {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($this->user);

			return $user;
		});
		$this->controller = new FileActionController(
			'paperless_sync',
			$this->createStub(IRequest::class),
			$this->configService,
			new FileActions($this->configService, $groups, $this->storage, $this->state),
			$session,
			$this->germanL10n(),
		);
	}

	/** Every user of Files may click, so the route needs no administrator, keeps its request token and limits the clicks of each user. */
	public function testTheRouteIsForEveryUserWithItsRequestTokenAndALimit(): void {
		$method = new ReflectionMethod(FileActionController::class, 'send');

		self::assertCount(1, $method->getAttributes(NoAdminRequired::class));
		self::assertSame([], $method->getAttributes(NoCSRFRequired::class));
		self::assertCount(1, $method->getAttributes(UserRateLimit::class));
		self::assertSame(['limit' => FileActionController::RATE_LIMIT, 'period' => FileActionController::RATE_PERIOD], $method->getAttributes(UserRateLimit::class)[0]->getArguments());
	}

	public function testAFileIsQueuedAndTheAnswerSaysWhatHappensNext(): void {
		$id = $this->file('Scans/receipt.pdf');

		$response = $this->controller->send([$id]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([
			'message' => 'Paperless Sync sendet 1 Datei an Paperless. Eine Benachrichtigung sagt dir, sobald Paperless sie hat.',
			'queued' => 1,
			'waiting' => 0,
			'skipped' => 0,
		], $response->getData());
		self::assertNotNull($this->state->findSend(self::SENDER, $id));
	}

	/** IDs in JSON may arrive as text, as from a form. */
	public function testTheIdsMayBeText(): void {
		$first = $this->file('Scans/a.pdf');
		$second = $this->file('Scans/b.pdf');

		self::assertSame(2, $this->controller->send([(string)$first, $second])->getData()['queued']);
	}

	public function testTheAnswerTellsOfFilesOnTheirWayAndOfThoseThatStayOut(): void {
		$waiting = $this->file('Scans/receipt.pdf');
		$this->controller->send([$waiting]);
		$new = $this->file('Scans/letter.pdf');
		$known = $this->file('Scans/Invoice [P12].pdf');

		$response = $this->controller->send([$waiting, $new, $known]);

		self::assertSame(
			'Paperless Sync sendet 1 Datei an Paperless. Eine Benachrichtigung sagt dir, sobald Paperless sie hat. 1 Datei ist bereits auf dem Weg zu Paperless. 1 Datei bleibt außen vor: Paperless hat sie bereits, sie liegt im Ordner von Paperless Sync, oder ihre Freigabe erlaubt keine Downloads.',
			$response->getData()['message'],
		);
	}

	public function testFilesThatAreAllOnTheirWayAreNoError(): void {
		$first = $this->file('Scans/a.pdf');
		$second = $this->file('Scans/b.pdf');
		$this->controller->send([$first, $second]);

		$response = $this->controller->send([$first, $second]);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('2 Dateien sind bereits auf dem Weg zu Paperless.', $response->getData()['message']);
	}

	public function testAFolderWithoutFilesIsRefused(): void {
		$folder = $this->storage->folderId('Empty', self::SENDER);

		$response = $this->controller->send([$folder]);

		self::assertSame([Http::STATUS_BAD_REQUEST, ['message' => 'Es gibt keine Dateien zu senden.']], [$response->getStatus(), $response->getData()]);
	}

	public function testAnAppThatIsNotConnectedSendsNothing(): void {
		$this->configService->reset();

		$response = $this->controller->send([$this->file('Scans/receipt.pdf')]);

		self::assertSame([Http::STATUS_FORBIDDEN, ['message' => 'Paperless Sync ist noch nicht vollständig eingerichtet.']], [$response->getStatus(), $response->getData()]);
	}

	/** @return array<string, array{string|null}> */
	public static function usersWhoMayNotSend(): array {
		return [
			'not a member of the group' => ['bob'],
			'no user' => [null],
		];
	}

	#[DataProvider('usersWhoMayNotSend')]
	public function testOnlyMembersOfTheGroupMaySend(?string $user): void {
		$id = $this->file('Scans/receipt.pdf');
		$this->user = $user;

		$response = $this->controller->send([$id]);

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('Du darfst keine Dateien an Paperless senden. Ein Administrator kann es in den Einstellungen von Paperless Sync erlauben.', $response->getData()['message']);
		self::assertSame([], $this->state->sends);
	}

	/** @return array<string, array{array<array-key, mixed>}> */
	public static function invalidIds(): array {
		return [
			'none' => [[]],
			'not a number' => [['receipt.pdf']],
			'a negative number' => [[-3]],
			'zero' => [[0]],
			'a list in the list' => [[[1]]],
			'a number with a fraction' => [['1.5']],
		];
	}

	/** @param array<array-key, mixed> $ids */
	#[DataProvider('invalidIds')]
	public function testTheClickNamesTheFilesByTheirIds(array $ids): void {
		$response = $this->controller->send($ids);

		self::assertSame([Http::STATUS_BAD_REQUEST, ['message' => 'Wähle die Dateien, die an Paperless gehen sollen.']], [$response->getStatus(), $response->getData()]);
	}

	public function testAFileThatTheUserDoesNotSeeIsNotFound(): void {
		$response = $this->controller->send([404]);

		self::assertSame([Http::STATUS_NOT_FOUND, ['message' => 'Die Datei wurde nicht gefunden, oder du darfst sie nicht lesen.']], [$response->getStatus(), $response->getData()]);
	}

	public function testAFileOfTheFolderOfTheAppIsRefusedByItsName(): void {
		$this->user = TestStorage::USER;
		$this->storage->files['Documents/Paperless/Inbox/scan.pdf'] = 'Synthetic scan';

		$response = $this->controller->send([$this->storage->fileId('Documents/Paperless/Inbox/scan.pdf')]);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('scan.pdf liegt im Ordner von Paperless Sync, um den sich seine Läufe kümmern: Verschiebe eine Datei in seinen Eingang, um sie an Paperless zu senden.', $response->getData()['message']);
	}

	public function testMoreFilesThanABatchAreRefused(): void {
		$ids = [$this->file('Scans/a.pdf'), $this->file('Scans/b.pdf'), $this->file('Scans/c.pdf')];

		$response = $this->controller->send($ids);

		self::assertSame([Http::STATUS_BAD_REQUEST, ['message' => 'Das sind mehr als 2 Dateien, so viele sendet Paperless Sync höchstens auf einmal. Sende weniger auf einmal.']], [$response->getStatus(), $response->getData()]);
	}

	public function testAFolderWithMoreFilesThanABatchIsRefusedByItsName(): void {
		$this->file('Scans/a.pdf');
		$this->file('Scans/b.pdf');
		$this->file('Scans/c.pdf');

		$response = $this->controller->send([$this->storage->folderId('Scans', self::SENDER)]);

		self::assertSame([Http::STATUS_BAD_REQUEST, ['message' => 'Scans enthält mehr als 2 Dateien, so viele sendet Paperless Sync höchstens auf einmal. Sende seine Unterordner einzeln.']], [$response->getStatus(), $response->getData()]);
	}

	private function file(string $path): int {
		$this->storage->filesOfOthers[self::SENDER][$path] = 'Synthetic content of ' . $path;

		return $this->storage->fileId($path, self::SENDER);
	}
}
