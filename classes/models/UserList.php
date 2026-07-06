<?php

namespace BSBI\WebBase\models;

/**
 * Represents a list of User objects, extending the BaseList functionality.
 * Provides methods to retrieve, add, and manage Users within the list.
 * @extends BaseList<User, BaseFilter>
 */
class UserList extends BaseList
{

    /**
     * @param User $user
     */
    public function addListItem(User $user): void
    {
        $this->add($user);
    }

    /**
     * @return string
     */
    function getItemType(): string
    {
        return User::class;
    }

    /**
     * @return string
     */
    function getFilterType(): string
    {
        return BaseFilter::class;
    }
}
