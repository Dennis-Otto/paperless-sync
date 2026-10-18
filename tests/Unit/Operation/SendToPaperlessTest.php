<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Operation;

use OCA\PaperlessSync\Operation\SendToPaperless;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\FileActions;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use OCA\PaperlessSync\Tests\Doubles\TestStorage;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SendToPaperlessTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	/** The owner of the files of the tests, who uploads them. */
	private const OWNER = 'alice';

	private ConfigService $configService;
	private TestStorage $storage;
	private TestStateRepository $state;
	/** @var IRootFolder&MockObject */
	private IRootFolder $rootFolder;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;
	/** The user of the request that wrote the file, or null, as in cron. */
	private ?string $sessionUser = 'bob';
	/** @var list<mixed> the rules that match, as Flow's rule matcher gives them */
	private array $flows = [['id' => 7, 'operation' => '{"tags":["Scans","Family"]}']];
	private SendToPaperless $operation;

	protected function setUp(): void {
		$this->configService = $this->configService();
		$this->configService->save(self::validSettings(), 'secret-token');
		$this->storage = new TestStorage();
		$this->state = new TestStateRepository();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => "/apps/{$app}/img/{$image}");
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser === null ? null : $this->user($this->sessionUser));
		$this->operation = new SendToPaperless(
			$this->germanL10n(),
			$urls,
			$this->configService,
			new FileActions($this->configService, $this->createStub(IGroupManager::class), $this->storage, $this->state),
			$this->rootFolder,
			$session,
			$this->logger,
		);
	}

	/** Flow offers it for rules of files, in the words of the administrator, with the icon of the app. */
	public function testItIsAnOperationOfFlowForFiles(): void {
		self::assertInstanceOf(ISpecificOperation::class, $this->operation);
		self::assertSame('OCA\WorkflowEngine\Entity\File', $this->operation->getEntityId());
		self::assertSame('An Paperless senden', $this->operation->getDisplayName());
		self::assertSame('Sendet die Dateien mit den Tags für jeden Import des Eingangs und denen der Regel an Paperless. Die Dateien bleiben, wo sie sind.', $this->operation->getDescription());
		self::assertSame('/apps/paperless_sync/img/flow.svg', $this->operation->getIcon());
		self::assertFileExists(__DIR__ . '/../../../img/flow.svg');
	}

	/** The uploads use the token of the app, so a user may not make a rule of their own. */
	public function testOnlyTheAdministratorsMayUseIt(): void {
		self::assertTrue($this->operation->isAvailableForScope(IManager::SCOPE_ADMIN));
		self::assertFalse($this->operation->isAvailableForScope(IManager::SCOPE_USER));
	}

	/** @return array<string, array{string, list<string>}> */
	public static function operations(): array {
		return [
			'a new rule' => ['', []],
			'no tags' => ['{"tags":[]}', []],
			'no member' => ['{}', []],
			'tags' => ['{"tags":["Scans","Family"]}', ['Scans', 'Family']],
			'spaces around a name' => ['{"tags":["  Scans  "]}', ['Scans']],
			'a name twice in another case' => ['{"tags":["Scans","scans","Family"]}', ['Scans', 'Family']],
			'the longest name' => ['{"tags":["' . str_repeat('ä', 128) . '"]}', [str_repeat('ä', 128)]],
		];
	}

	/** @param list<string> $tags */
	#[DataProvider('operations')]
	public function testTheOperationHoldsTheTagsOfTheRule(string $operation, array $tags): void {
		$this->operation->validateOperation('', [], $operation);

		self::assertSame($tags, SendToPaperless::tags($operation));
	}

	/** @return array<string, array{string, string}> */
	public static function invalidOperations(): array {
		$invalid = 'Die Tags der Regel sind keine Liste von Namen.';

		return [
			'no JSON' => ['tags: Scans', $invalid],
			'too deep' => ['{"tags":[[["Scans"]]]}', $invalid],
			'no object' => ['"Scans"', $invalid],
			'a member of another version' => ['{"tags":[],"deleteAfterSuccess":true}', $invalid],
			'a name in place of a list' => ['{"tags":"Scans"}', $invalid],
			'tags by key' => ['{"tags":{"a":"Scans"}}', $invalid],
			'a number' => ['{"tags":[2026]}', $invalid],
			'an empty name' => ['{"tags":["Scans","  "]}', $invalid],
			'a comma' => ['{"tags":["Tax, 2026"]}', 'Der Name eines Tags darf kein Komma und keinen Zeilenumbruch enthalten: Tax, 2026'],
			'a line break' => ['{"tags":["Tax\n2026"]}', "Der Name eines Tags darf kein Komma und keinen Zeilenumbruch enthalten: Tax\n2026"],
			'a name longer than Paperless keeps' => ['{"tags":["' . str_repeat('a', 129) . '"]}', 'Paperless speichert Namen von Tags mit höchstens 128 Zeichen: ' . str_repeat('a', 129)],
		];
	}

	#[DataProvider('invalidOperations')]
	public function testFlowRefusesARuleWhoseTagsPaperlessCanNotTake(string $operation, string $message): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage($message);

		$this->operation->validateOperation('Scans', [], $operation);
	}

	/** @return array<string, array{string}> */
	public static function eventsOfAFile(): array {
		return [
			'File created' => ['\OCP\Files::postCreate'],
			'File updated' => ['\OCP\Files::postWrite'],
			'File accessed' => ['\OCP\Files::postTouch'],
		];
	}

	#[DataProvider('eventsOfAFile')]
	public function testAMatchingFileIsQueuedForItsOwnerWithTheTagsOfTheRule(string $eventName): void {
		$id = $this->upload('Scans/receipt.pdf');

		$this->onEvent($eventName, new GenericEvent($this->file($id, self::OWNER)));

		self::assertSame(
			['path' => 'Scans/receipt.pdf', 'tags' => 'Scans,Family', 'rule_id' => 7, 'status' => 'queued'],
			array_intersect_key($this->state->findSend(self::OWNER, $id) ?? [], array_flip(['path', 'tags', 'rule_id', 'status'])),
		);
	}

	/** A file moved or copied into the folder of a rule is the new file of the event. */
	#[DataProvider('moves')]
	public function testAMovedOrCopiedFileIsQueuedAtItsNewPlace(string $eventName): void {
		$id = $this->upload('Scans/receipt.pdf');
		$source = $this->createStub(File::class);
		$source->method('getId')->willReturn(999);

		$this->onEvent($eventName, new GenericEvent([$source, $this->file($id, self::OWNER)]));

		self::assertSame([self::OWNER . '/' . $id], array_keys($this->state->sends));
	}

	/** @return array<string, array{string}> */
	public static function moves(): array {
		return ['File renamed' => ['\OCP\Files::postRename'], 'File copied' => ['\OCP\Files::postCopy']];
	}

	/** "Tag assigned": a tag of Nextcloud such as "To Paperless" sends the file that gets it. */
	public function testAFileThatGetsATagIsQueued(): void {
		$id = $this->upload('Scans/receipt.pdf');
		$this->rootFolder->expects(self::once())->method('getFirstNodeById')->with($id)->willReturn($this->file($id, self::OWNER));

		$this->onEvent(MapperEvent::EVENT_ASSIGN, new MapperEvent(MapperEvent::EVENT_ASSIGN, 'files', (string)$id, [3]));

		self::assertNotNull($this->state->findSend(self::OWNER, $id));
	}

	/** @return array<string, array{string, \Closure(self): Event}> */
	public static function eventsWithoutAFile(): array {
		return [
			'a deleted file' => ['\OCP\Files::postDelete', static fn (self $test): Event => new GenericEvent($test->file(1, self::OWNER))],
			'a folder' => ['\OCP\Files::postCreate', static fn (self $test): Event => new GenericEvent($test->createStub(Folder::class))],
			'no node' => ['\OCP\Files::postCreate', static fn (self $test): Event => new GenericEvent(null)],
			'a move without its new file' => ['\OCP\Files::postRename', static fn (self $test): Event => new GenericEvent([$test->file(1, self::OWNER)])],
			'a tag of another object' => [MapperEvent::EVENT_ASSIGN, static fn (self $test): Event => new MapperEvent(MapperEvent::EVENT_ASSIGN, 'calendar', '1', [3])],
			'a tag of a file that is gone' => [MapperEvent::EVENT_ASSIGN, static fn (self $test): Event => new MapperEvent(MapperEvent::EVENT_ASSIGN, 'files', '1', [3])],
			'another event' => ['\OCP\Files::postCreate', static fn (self $test): Event => new Event()],
		];
	}

	/** @param \Closure(self): Event $event */
	#[DataProvider('eventsWithoutAFile')]
	public function testAnEventWithoutAFileSendsNothing(string $eventName, \Closure $event): void {
		$this->upload('Scans/receipt.pdf');
		$this->rootFolder->method('getFirstNodeById')->willReturn(null);
		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->expects(self::never())->method('getFlows');

		$this->operation->onEvent($eventName, $event($this), $matcher);

		self::assertSame([], $this->state->sends);
	}

	/** Flow calls the operation for every event of the rules of the operation; a file that no rule matches stays. */
	public function testAFileThatNoRuleMatchesStays(): void {
		$id = $this->upload('Scans/receipt.pdf');
		$this->flows = [];

		$this->onEvent('\OCP\Files::postCreate', new GenericEvent($this->file($id, self::OWNER)));

		self::assertSame([], $this->state->sends);
	}

	/** The file gets the tags of every rule that matches, once each, and the first rule names the send. */
	public function testTheTagsOfEveryMatchingRuleAreJoined(): void {
		$id = $this->upload('Scans/receipt.pdf');
		$this->flows = [
			'no rule',
			['operation' => '{"tags":["Lost"]}'],
			['id' => '7', 'operation' => '{"tags":["Scans"]}'],
			['id' => 8, 'operation' => '{"tags":["scans","Family"]}'],
			['id' => 9, 'operation' => 'written by hand'],
			['id' => 10],
		];

		$this->onEvent('\OCP\Files::postCreate', new GenericEvent($this->file($id, self::OWNER)));

		self::assertSame(['Scans,Family', 7], [$this->state->findSend(self::OWNER, $id)['tags'] ?? null, $this->state->findSend(self::OWNER, $id)['rule_id'] ?? null]);
	}

	/** A file without an owner, as in a group folder, goes for the user who wrote it. */
	public function testAFileWithoutAnOwnerIsQueuedForTheUserOfTheRequest(): void {
		$this->storage->filesOfOthers['bob']['Team/receipt.pdf'] = 'Synthetic receipt';
		$id = $this->storage->fileId('Team/receipt.pdf', 'bob');

		$this->onEvent('\OCP\Files::postCreate', new GenericEvent($this->file($id, null)));

		self::assertNotNull($this->state->findSend('bob', $id));
	}

	public function testAFileWithoutAnOwnerOutsideARequestStays(): void {
		$this->sessionUser = null;
		$id = $this->upload('Scans/receipt.pdf');

		$this->onEvent('\OCP\Files::postCreate', new GenericEvent($this->file($id, null)));

		self::assertSame([], $this->state->sends);
	}

	/** The log says what became of a file, for an administrator who looks for it. */
	public function testTheLogSaysWhatBecameOfTheFile(): void {
		$id = $this->upload('Downloads/Invoice [P123].pdf');
		$this->logger->expects(self::once())->method('debug')->with(
			'The rule {rule} of Flow sends the file {file} of {user} to Paperless: {outcome}',
			['app' => 'paperless_sync', 'rule' => 7, 'file' => $id, 'user' => self::OWNER, 'outcome' => FileActions::RULE_IN_PAPERLESS],
		);

		$this->onEvent('\OCP\Files::postCreate', new GenericEvent($this->file($id, self::OWNER)));
	}

	/** The upload that started the event happened: an error of the operation goes into the log, not to the user. */
	public function testAnErrorNeverFailsTheRequestThatWroteTheFile(): void {
		$file = $this->createStub(File::class);
		$file->method('getOwner')->willReturn($this->user(self::OWNER));
		$file->method('getId')->willThrowException(new RuntimeException('The file cache is locked.'));
		$this->logger->expects(self::once())->method('warning')->with(
			'Paperless Sync could not queue a file of a rule of Flow for Paperless: {error}',
			self::callback(static fn (array $context): bool => $context['error'] === 'The file cache is locked.' && $context['exception'] instanceof RuntimeException),
		);

		$this->onEvent('\OCP\Files::postCreate', new GenericEvent($file));

		self::assertSame([], $this->state->sends);
	}

	private function onEvent(string $eventName, Event $event): void {
		$matcher = $this->createStub(IRuleMatcher::class);
		$matcher->method('getFlows')->willReturnCallback(fn (bool $first): array => $first ? self::fail('Every rule that matches counts.') : $this->flows);

		$this->operation->onEvent($eventName, $event, $matcher);
	}

	/** A file of the owner among their files, which a rule matches. */
	private function upload(string $path): int {
		$this->storage->filesOfOthers[self::OWNER][$path] = 'Synthetic content of ' . $path;

		return $this->storage->fileId($path, self::OWNER);
	}

	/** The node of the file with the ID, as the event of Nextcloud carries it. */
	private function file(int $id, ?string $owner): File {
		$file = $this->createStub(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getOwner')->willReturn($owner === null ? null : $this->user($owner));

		return $file;
	}

	private function user(string $uid): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}
}
