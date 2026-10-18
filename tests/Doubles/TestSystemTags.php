<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use InvalidArgumentException;
use LogicException;
use OCP\IUser;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagCreationForbiddenException;
use OCP\SystemTag\TagNotFoundException;

/**
 * The collaborative tags of Nextcloud in memory, its tags and which objects have them, as
 * Nextcloud 33 to 35 keep them: one tag of a name, whatever its case, a tag with no user may
 * create outside the command line ($createForbidden), and every tag that a call names must exist,
 * or the call changes nothing. It records each change, and fails with a failure that a test sets.
 * The methods that the app doesn't call fail the test.
 */
final class TestSystemTags implements ISystemTagManager, ISystemTagObjectMapper {
	/** @var array<int, TestSystemTag> the tags by their IDs */
	public array $tags = [];
	/** @var array<string, array<string, list<int>>> the IDs of the tags of each object, by its type and ID */
	public array $objects = [];
	/** @var list<string> the names of the tags that were created, in their order */
	public array $created = [];
	/** @var list<int> the IDs of the tags that were deleted */
	public array $deleted = [];
	/** @var list<array{string, list<int>}> each assignment: the object and the IDs of its new tags */
	public array $assigned = [];
	/** @var list<array{string, list<int>}> each removal: the object and the IDs of the tags it lost */
	public array $unassigned = [];
	/** Whether Nextcloud refuses to create a tag, as for a request or a background job without a user outside the command line. */
	public bool $createForbidden = false;
	/** @var list<string> the names of tags that someone else creates while a run creates them, between its question and its creation */
	public array $createdMeanwhile = [];
	/** The failure of every lookup of tags by their IDs, such as one that names no missing tag. */
	public ?\Throwable $lookupException = null;
	/** The failure of every question which tags objects have, such as of the database. */
	public ?\Throwable $readException = null;
	/** @var array<string, \Throwable> the failure of the assignment of tags to one object, by its ID */
	public array $assignFailures = [];
	/** @var array<string, \Throwable> the failure of the creation of the tag of one name, such as of a database that holds no emoji */
	public array $createFailures = [];
	private int $lastId = 0;

	/** A tag that someone made in Nextcloud, such as a user in Files or an administrator; returns its ID. */
	public function add(string $name, bool $userVisible = true, bool $userAssignable = true): int {
		$id = ++$this->lastId;
		$this->tags[$id] = new TestSystemTag((string)$id, $name, $userVisible, $userAssignable);

		return $id;
	}

	/**
	 * The names of the tags of a file, in the order of their names.
	 *
	 * @return list<string>
	 */
	public function namesOf(int $fileId): array {
		$names = array_map(fn (int $id): string => $this->tags[$id]->getName(), $this->objects['files'][(string)$fileId] ?? []);
		sort($names);

		return $names;
	}

	/** The ID of the tag of a name, or null when there is none. */
	public function idOf(string $name): ?int {
		foreach ($this->tags as $id => $tag) {
			if ($tag->getName() === $name) {
				return $id;
			}
		}

		return null;
	}

	public function getTagsByIds($tagIds, ?IUser $user = null): array {
		if ($this->lookupException !== null) {
			throw $this->lookupException;
		}
		$found = [];
		$missing = [];
		foreach ((array)$tagIds as $tagId) {
			if (!is_numeric($tagId)) {
				throw new InvalidArgumentException('Tag id must be integer');
			}
			if (isset($this->tags[(int)$tagId])) {
				$found[(int)$tagId] = $this->tags[(int)$tagId];
			} else {
				$missing[] = (string)$tagId;
			}
		}
		if ($missing !== []) {
			throw new TagNotFoundException('Tag id(s) not found', 0, null, $missing);
		}

		return $found;
	}

	public function getTag(string $tagName, bool $userVisible, bool $userAssignable): ISystemTag {
		throw new LogicException('Paperless Sync finds the tags of a name with getAllTags().');
	}

	public function createTag(string $tagName, bool $userVisible, bool $userAssignable): ISystemTag {
		if ($this->createForbidden) {
			throw new TagCreationForbiddenException();
		}
		if (isset($this->createFailures[$tagName])) {
			throw $this->createFailures[$tagName];
		}
		if (in_array($tagName, $this->createdMeanwhile, true)) {
			$this->add($tagName);
		}
		foreach ($this->tags as $tag) {
			if (mb_strtolower($tag->getName()) === mb_strtolower($tagName)) {
				throw new TagAlreadyExistsException('Tag ' . $tagName . ' already exists');
			}
		}
		$id = $this->add($tagName, $userVisible, $userAssignable);
		$this->created[] = $tagName;

		return $this->tags[$id];
	}

	public function getAllTags($visibilityFilter = null, $nameSearchPattern = null): array {
		$tags = [];
		foreach ($this->tags as $id => $tag) {
			if ($visibilityFilter !== null && $tag->isUserVisible() !== (bool)$visibilityFilter) {
				continue;
			}
			if (!empty($nameSearchPattern) && mb_stripos($tag->getName(), (string)$nameSearchPattern) === false) {
				continue;
			}
			$tags[$id] = $tag;
		}

		return $tags;
	}

	public function updateTag(string $tagId, string $newName, bool $userVisible, bool $userAssignable, ?string $color) {
		throw new LogicException('Paperless Sync changes no tag.');
	}

	public function deleteTags($tagIds) {
		$this->getTagsByIds($tagIds);
		foreach ((array)$tagIds as $tagId) {
			unset($this->tags[(int)$tagId]);
			$this->deleted[] = (int)$tagId;
			foreach ($this->objects as $type => $objects) {
				foreach ($objects as $objectId => $ids) {
					$this->objects[$type][$objectId] = array_values(array_diff($ids, [(int)$tagId]));
				}
			}
		}
	}

	public function canUserAssignTag(ISystemTag $tag, ?IUser $user): bool {
		throw new LogicException('Paperless Sync asks for no permission of a user.');
	}

	public function canUserCreateTag(?IUser $user): bool {
		throw new LogicException('Paperless Sync asks for no permission of a user.');
	}

	public function canUserUpdateTag(?IUser $user): bool {
		throw new LogicException('Paperless Sync asks for no permission of a user.');
	}

	public function canUserSeeTag(ISystemTag $tag, ?IUser $user): bool {
		throw new LogicException('Paperless Sync asks for no permission of a user.');
	}

	public function setTagGroups(ISystemTag $tag, array $groupIds) {
		throw new LogicException('Paperless Sync sets no groups of a tag.');
	}

	public function getTagGroups(ISystemTag $tag): array {
		throw new LogicException('Paperless Sync reads no groups of a tag.');
	}

	public function getTagIdsForObjects($objIds, string $objectType): array {
		if ($this->readException !== null) {
			throw $this->readException;
		}
		$mapping = [];
		foreach ((array)$objIds as $objId) {
			// Nextcloud gives the IDs of the tags as the database has them, as strings.
			$mapping[$objId] = array_map('strval', $this->objects[$objectType][(string)$objId] ?? []);
		}

		return $mapping;
	}

	public function getObjectIdsForTags($tagIds, string $objectType, int $limit = 0, string $offset = ''): array {
		$this->getTagsByIds($tagIds);
		$objects = [];
		foreach ($this->objects[$objectType] ?? [] as $objectId => $ids) {
			if (array_intersect(array_map('intval', (array)$tagIds), $ids) !== []) {
				$objects[] = (string)$objectId;
			}
		}

		return $limit > 0 ? array_slice($objects, 0, $limit) : $objects;
	}

	public function assignTags(string $objId, string $objectType, $tagIds) {
		if (isset($this->assignFailures[$objId])) {
			throw $this->assignFailures[$objId];
		}
		$this->getTagsByIds($tagIds);
		$ids = array_map('intval', (array)$tagIds);
		$this->objects[$objectType][$objId] = array_values(array_unique(array_merge($this->objects[$objectType][$objId] ?? [], $ids)));
		$this->assigned[] = [$objId, $ids];
	}

	public function unassignTags(string $objId, string $objectType, $tagIds) {
		$this->getTagsByIds($tagIds);
		$ids = array_map('intval', (array)$tagIds);
		$this->objects[$objectType][$objId] = array_values(array_diff($this->objects[$objectType][$objId] ?? [], $ids));
		$this->unassigned[] = [$objId, $ids];
	}

	public function haveTag($objIds, string $objectType, string $tagId, bool $all = true): bool {
		throw new LogicException('Paperless Sync reads the tags of the objects with getTagIdsForObjects().');
	}

	public function getAvailableObjectTypes(): array {
		throw new LogicException('Paperless Sync tags files alone.');
	}

	public function setObjectIdsForTag(string $tagId, string $objectType, array $objectIds): void {
		throw new LogicException('Paperless Sync changes the tags of one file at a time.');
	}
}
