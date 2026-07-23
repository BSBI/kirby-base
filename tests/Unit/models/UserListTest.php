<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\models;

use BSBI\WebBase\models\User;
use BSBI\WebBase\models\UserList;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the UserList model.
 *
 * Covers add/retrieve, empty-state behaviour, and descending
 * sort-by-title inherited from BaseList.
 */
final class UserListTest extends TestCase
{
    /**
     * Create a User with a given title for testing.
     *
     * @param string $title The user display name
     * @return User
     */
    private function createUser(string $title = 'Alice'): User
    {
        return new User($title);
    }

    /**
     * Verify users can be added and retrieved in insertion order.
     */
    public function testAddAndRetrieveUsers(): void
    {
        $list = new UserList();
        $user1 = $this->createUser('Alice');
        $user2 = $this->createUser('Bob');

        $list->addListItem($user1);
        $list->addListItem($user2);

        $this->assertSame(2, $list->count());
        $this->assertSame($user1, $list->getListItems()[0]);
        $this->assertSame($user2, $list->getListItems()[1]);
    }

    /**
     * Verify a new UserList is empty by default.
     */
    public function testEmptyByDefault(): void
    {
        $list = new UserList();

        $this->assertSame(0, $list->count());
        $this->assertFalse($list->hasListItems());
    }

    /**
     * Verify sortByTitle() sorts users in descending alphabetical order.
     */
    public function testSortByTitleDescending(): void
    {
        $list = new UserList();
        $list->addListItem($this->createUser('Alice'));
        $list->addListItem($this->createUser('Charlie'));
        $list->addListItem($this->createUser('Bob'));

        $list->sortByTitle();

        $items = $list->getListItems();
        $this->assertSame('Charlie', $items[0]->getTitle());
        $this->assertSame('Bob', $items[1]->getTitle());
        $this->assertSame('Alice', $items[2]->getTitle());
    }

    /**
     * Verify sortByTitle(false) sorts ascending — the $descending parameter
     * must be honoured, not ignored.
     */
    public function testSortByTitleAscending(): void
    {
        $list = new UserList();
        $list->addListItem($this->createUser('Charlie'));
        $list->addListItem($this->createUser('Alice'));
        $list->addListItem($this->createUser('Bob'));

        $list->sortByTitle(false);

        $items = $list->getListItems();
        $this->assertSame('Alice', $items[0]->getTitle());
        $this->assertSame('Bob', $items[1]->getTitle());
        $this->assertSame('Charlie', $items[2]->getTitle());
    }
}
