<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\User as UserModel;
use BSBI\WebBase\models\UserList;
use BSBI\WebBase\models\WebPageLink;
use BSBI\WebBase\models\WebPageLinks;
use DateTime;
use Exception;
use Kirby\Cms\App;
use Kirby\Cms\Block;
use Kirby\Cms\Blocks;
use Kirby\Cms\File;
use Kirby\Cms\Files;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Cms\Structure;
use Kirby\Cms\StructureObject;
use Kirby\Cms\User;
use Kirby\Content\Field;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Toolkit\Str;
use Throwable;

/**
 * Provides typed read access to Kirby CMS field values across pages, site,
 * structures, blocks, entries, and users. All methods receive their Kirby
 * objects as parameters; no global state is accessed beyond the injected
 * App and Site instances.
 */
final readonly class KirbyFieldReader
{
    /**
     * @param App $kirby
     * @param Site $site
     * @param GlossaryService|null $glossaryService Optional glossary service; when provided,
     *                                              glossary links in text/list blocks are
     *                                              enriched with definition title attributes
     */
    public function __construct(
        private App  $kirby,
        private Site $site,
        private ?GlossaryService $glossaryService = null,
    )
    {
    }

    // -------------------------------------------------------------------------
    // PAGE FIELDS
    // -------------------------------------------------------------------------

    /**
     * Returns the raw Field for the given field name on a page.
     * Throws if the field does not exist or is empty.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageField(Page $page, string $fieldName): Field
    {
        try {
            $pageField = $page->content()->get($fieldName);

            if (!$pageField instanceof Field) {
                throw new KirbyRetrievalException('The field ' . $fieldName . ' does not exist');
            }
            if ($pageField->isEmpty()) {
                throw new KirbyRetrievalException('The field ' . $fieldName . ' is empty');
            }
            return $pageField;
        } catch (InvalidArgumentException) {
            throw new KirbyRetrievalException('The field ' . $fieldName . ' does not exist');
        }
    }

    /**
     * Returns true when the field exists and is not empty.
     */
    public function isPageFieldNotEmpty(Page $page, string $fieldName): bool
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->isNotEmpty();
        } catch (KirbyRetrievalException) {
            return false;
        }
    }

    /**
     * Returns the field value as a string.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsString(
        Page   $page,
        string $fieldName,
        bool   $required = false,
        string $defaultValue = '',
    ): string
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            return $pageField->toString();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return $defaultValue;
        }
    }

    /**
     * Returns the field value as a string, or $fallback when the field is
     * empty or missing (never throws).
     */
    public function getPageFieldAsStringWithFallback(Page $page, string $fieldName, string $fallback): string
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            return $pageField->isNotEmpty() ? $pageField->toString() : $fallback;
        } catch (KirbyRetrievalException) {
            return $fallback;
        }
    }

    /**
     * Returns the field value escaped for safe use inside JavaScript literals.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsStringForJavascriptUse(
        Page   $page,
        string $fieldName,
        bool   $required = false,
        string $defaultValue = '',
    ): string
    {
        $value = $this->getPageFieldAsString($page, $fieldName, $required, $defaultValue);
        return esc($value, 'js');
    }

    /**
     * Returns the field value as an integer.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsInt(
        Page   $page,
        string $fieldName,
        bool   $required = false,
        int    $default = 0,
    ): int
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->toInt();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return $default;
        }
    }

    /**
     * Returns the field value as a float.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsFloat(Page $page, string $fieldName, bool $required = false): float
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->toFloat();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            //TODO: should be better than returning zero if not required. Maybe return float|null
            return 0;
        }
    }

    /**
     * Returns the field value as a boolean.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsBool(
        Page   $page,
        string $fieldName,
        bool   $required = false,
        bool   $default = false,
    ): bool
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->toBool();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return $default;
        }
    }

    /**
     * Returns 'Yes' or 'No' based on a boolean field value.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsYesNo(Page $page, string $fieldName, bool $required = false): string
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return ($pageField->toBool() === true) ? 'Yes' : 'No';
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            //TODO: should be better than returning false if not required. Maybe return bool|null
            return 'NO';
        }
    }

    /**
     * Returns the field value as a DateTime, or null when not required and missing.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsDateTime(Page $page, string $fieldName, bool $required = false): ?DateTime
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return (new DateTime())->setTimestamp($pageField->toDate());
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return null;
        }
    }

    /**
     * Returns the field value as a DateTime; always throws when missing.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsRequiredDateTime(Page $page, string $fieldName): DateTime
    {
        $pageField = $this->getPageField($page, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return (new DateTime())->setTimestamp($pageField->toDate());
    }

    /**
     * Returns the field value formatted as a time string (e.g. "3:45 pm").
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsTime(Page $page, string $fieldName, bool $isRequired = false): string
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->toDate('g:i a');
        } catch (KirbyRetrievalException $e) {
            if ($isRequired) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns the field value as a Kirby Structure.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsStructure(Page $page, string $fieldName, bool $isRequired = false): Structure
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->toStructure();
        } catch (KirbyRetrievalException $e) {
            if ($isRequired) {
                throw $e;
            }
            return new Structure();
        }
    }

    /**
     * Returns the field value as a URL string.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsUrl(Page $page, string $fieldName, bool $required = false): string
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->toUrl() ?? '';
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns block content rendered as HTML, optionally limited to an excerpt.
     *
     * When $excerpt is 0 the full HTML of every block is returned. When $excerpt
     * is greater than 0 only text blocks are included and the result is truncated
     * to that number of characters (plain text, via {@see Str::excerpt()}).
     *
     * @param Page $page The page to read from
     * @param string $fieldName The blocks field name
     * @param bool $required Whether to throw if the field is missing
     * @param int $excerpt Maximum excerpt length in characters (0 = full content, no truncation)
     * @return string
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsBlocksHtml(
        Page   $page,
        string $fieldName,
        bool   $required = false,
        int    $excerpt = 0,
    ): string
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            $blocksHTML = '';
            /** @noinspection PhpUndefinedMethodInspection */
            foreach ($pageField->toBlocks() as $block) {
                if ($excerpt === 0) {
                    $blocksHTML .= $this->getHTMLfromBlock($block);
                } else {
                    if ($block->type() === 'text') {
                        $blocksHTML .= $this->getHTMLfromBlock($block);
                    }
                }
            }
            if ($excerpt === 0) {
                return $blocksHTML;
            }

            // Insert a space between adjacent tags so block boundaries (e.g.
            // "</p><p>") don't run words together once Str::excerpt strips the
            // tags. Str::excerpt collapses any resulting double spaces.
            $spacedHTML = str_replace('><', '> <', $blocksHTML);

            return Str::excerpt($spacedHTML, $excerpt);
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns only the text blocks rendered as an HTML excerpt of $length characters.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldTextBlocksAsExcerpt(
        Page   $page,
        string $fieldName,
        int    $length,
        bool   $required = false,
    ): string
    {
        if ($length <= 0) {
            return '';
        }

        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            $textBlocks = $pageField->toBlocks()->filter(fn($block) => $block->type() === 'text');
            return Str::excerpt($textBlocks->toHtml(), $length);
        } catch (Exception $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns the field value as a Kirby Blocks collection.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsBlocks(Page $page, string $fieldName): Blocks
    {
        $pageField = $this->getPageField($page, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $pageField->toBlocks();
    }

    /**
     * Returns the field value as a split array of strings.
     *
     * @return string[]
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsArray(Page $page, string $fieldName, bool $required = false): array
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->split();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return [];
        }
    }

    /**
     * Returns the field value rendered as KirbyText HTML.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsKirbyText(Page $page, string $fieldName, bool $required = false): string
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->kti()->toString();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns the field value as a Kirby File, or null when not found.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsFile(Page $page, string $fieldName): File|null
    {
        $pageField = $this->getPageField($page, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $pageField->toFile();
    }

    /**
     * Returns the field value as a Kirby Files collection, or null when not found.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsFiles(Page $page, string $fieldName): Files|null
    {
        $pageField = $this->getPageField($page, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $pageField->toFiles();
    }

    /**
     * Returns the field value as a Kirby Pages collection, or null when not required and missing.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsPages(Page $page, string $fieldName, bool $isRequired = false): Pages|null
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->toPages();
        } catch (KirbyRetrievalException) {
            if ($isRequired) {
                throw new KirbyRetrievalException('The field ' . $fieldName . ' does not exist');
            }
            return null;
        }
    }

    /**
     * Returns the first page from a pages field, or null when not required and missing.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsFirstPage(Page $page, string $fieldName, bool $isRequired = false): Page|null
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $pageField->toPages()->first();
        } catch (KirbyRetrievalException) {
            if ($isRequired) {
                throw new KirbyRetrievalException('The field ' . $fieldName . ' does not exist');
            }
            return null;
        }
    }

    /**
     * Returns the title of the first page referenced by the field.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsPageTitle(Page $page, string $fieldName): string
    {
        $pages = $this->getPageFieldAsPages($page, $fieldName);
        $firstPage = $pages?->first();
        return $firstPage ? $firstPage->title()->toString() : '';
    }

    /**
     * Returns all page titles from a pages field as an array.
     *
     * @return string[]
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsPageTitles(Page $page, string $fieldName): array
    {
        $pages = $this->getPageFieldAsPages($page, $fieldName);
        return $pages ? $pages->pluck('title') : [];
    }

    /**
     * Returns the URL of the first page referenced by the field.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsPageUrl(Page $page, string $fieldName): string
    {
        $pages = $this->getPageFieldAsPages($page, $fieldName);
        $firstPage = $pages?->first();
        return $firstPage ? $firstPage->url() : '';
    }

    /**
     * Returns the ID of the first page referenced by the field.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsPageId(Page $page, string $fieldName): string
    {
        $pages = $this->getPageFieldAsPages($page, $fieldName);
        $firstPage = $pages?->first();
        return $firstPage ? $firstPage->id() : '';
    }

    /**
     * Returns the slug of the first page referenced by the field.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsPageSlug(Page $page, string $fieldName): string
    {
        $pages = $this->getPageFieldAsPages($page, $fieldName);
        $firstPage = $pages?->first();
        return $firstPage ? $firstPage->slug() : '';
    }

    /**
     * Returns 'page' or 'url' depending on what the link field resolves to.
     * Returns an empty string when neither applies.
     *
     * @throws KirbyRetrievalException
     */
    public function getLinkFieldType(Page $page, string $fieldName): string
    {
        $linkField = $this->getPageField($page, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        if ($linkField->toPage()) {
            return 'page';
        }
        /** @noinspection PhpUndefinedMethodInspection */
        if ($linkField->toUrl()) {
            return 'url';
        }
        //TODO: cope with other link types
        return '';
    }

    /**
     * Returns the blueprint field type (e.g. 'text', 'toggle', 'date') for the named field.
     *
     * @throws KirbyRetrievalException
     */
    public function getPageFieldType(Page $page, string $fieldName): string
    {
        try {
            $blueprintFields = $page->blueprint()->fields();
            if (isset($blueprintFields[$fieldName])) {
                return $blueprintFields[$fieldName]['type'];
            }
            throw new InvalidArgumentException(
                'The field "' . $fieldName . '" is not defined in the blueprint for page "' . $page->id() . '".'
            );
        } catch (InvalidArgumentException $e) {
            throw new KirbyRetrievalException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Returns a comma-separated string of usernames stored in the given field.
     */
    public function getUserNames(Page $page, string $fieldName): string
    {
        try {
            $postedByField = $page->content()->get($fieldName);

            if ($postedByField instanceof Field && $postedByField->isNotEmpty()) {
                $userNamesArray = [];
                /** @noinspection PhpUndefinedMethodInspection */
                foreach ($postedByField->toUsers() as $user) {
                    $userNamesArray[] = $user->name();
                }
                return implode(', ', $userNamesArray);
            }
            return 'Unknown';
        } catch (Exception) {
            return '';
        }
    }

    /**
     * Returns a UserList of full poster details (name, email, job title) for the
     * users stored in the given field, preserving their stored order.
     *
     * Returns an empty UserList if the field is empty or on any error.
     *
     * An optional enricher callback is invoked once per resolved user with the
     * built User model and the underlying Kirby user, allowing the caller to add
     * site-specific data (e.g. a linked profile page) without this generic helper
     * needing to know about it.
     *
     * @param Page $page The page holding the users field.
     * @param string $fieldName The name of the users field (e.g. 'postedBy').
     * @param callable(UserModel, User): void|null $enrich Optional per-user enricher.
     * @return UserList
     */
    public function getUsers(Page $page, string $fieldName, ?callable $enrich = null): UserList
    {
        $userList = new UserList();
        try {
            $field = $page->content()->get($fieldName);

            if ($field instanceof Field && $field->isNotEmpty()) {
                /** @noinspection PhpUndefinedMethodInspection */
                foreach ($field->toUsers() as $kirbyUser) {
                    $userModel = new UserModel($kirbyUser->username() ?? '');
                    $userModel
                        ->setUserId($kirbyUser->id())
                        ->setUserName($kirbyUser->username() ?? '')
                        ->setEmail($kirbyUser->email() ?? '')
                        ->setRole($kirbyUser->role()->name())
                        ->setJobTitle($kirbyUser->content()->get('jobTitle')->value() ?? '');
                    if ($enrich !== null) {
                        $enrich($userModel, $kirbyUser);
                    }
                    $userList->addListItem($userModel);
                }
            }
        } catch (Exception) {
            return new UserList();
        }
        return $userList;
    }

    /**
     * Returns a UserList of contributors selected on a page, resolved against the
     * site-level master contributors structure for their job role and email.
     *
     * The page field is expected to be a multiselect of contributor names (the
     * values stored by a field whose options are drawn from the master list).
     * Selection order is preserved. A selected name that is no longer present in
     * the master list yields a User carrying just that name. Contributors are not
     * Kirby users, so the built models have no user id (isLoggedIn() is false).
     *
     * Returns an empty UserList if nothing is selected or on any error.
     *
     * @param Page $page The page holding the contributor selection field.
     * @param string $fieldName The name of the multiselect field (e.g. 'postedByContributors').
     * @param Structure $contributors The site-level master contributors structure.
     * @return UserList
     */
    public function getContributors(Page $page, string $fieldName, Structure $contributors): UserList
    {
        $userList = new UserList();
        try {
            $field = $page->content()->get($fieldName);
            if (!($field instanceof Field) || $field->isEmpty()) {
                return $userList;
            }

            $selectedNames = $field->split(',');
            if ($selectedNames === []) {
                return $userList;
            }

            // Build a name => [jobTitle, email] map from the master list once.
            $detailsByName = [];
            foreach ($contributors as $row) {
                $name = trim($row->name()->value() ?? '');
                if ($name === '') {
                    continue;
                }
                $detailsByName[$name] = [
                    'jobTitle' => $row->jobRole()->value() ?? '',
                    'email'    => $row->email()->value() ?? '',
                ];
            }

            foreach ($selectedNames as $name) {
                $details = $detailsByName[$name] ?? ['jobTitle' => '', 'email' => ''];
                $userModel = new UserModel($name);
                $userModel
                    ->setUserId('')
                    ->setUserName($name)
                    ->setEmail($details['email'])
                    ->setRole('')
                    ->setJobTitle($details['jobTitle']);
                $userList->addListItem($userModel);
            }
        } catch (Exception) {
            return new UserList();
        }
        return $userList;
    }

    /**
     * Returns a comma-separated string of contributor names selected on a page.
     *
     * Returns 'Unknown' if nothing is selected, or an empty string on error,
     * mirroring {@see getUserNames()}.
     *
     * @param Page $page The page holding the contributor selection field.
     * @param string $fieldName The name of the multiselect field (e.g. 'postedByContributors').
     * @return string
     */
    public function getContributorNames(Page $page, string $fieldName): string
    {
        try {
            $field = $page->content()->get($fieldName);
            if ($field instanceof Field && $field->isNotEmpty()) {
                $names = $field->split(',');
                if ($names !== []) {
                    return implode(', ', $names);
                }
            }
            return 'Unknown';
        } catch (Exception) {
            return '';
        }
    }

    /**
     * Returns the file's last-modified date as a DateTime.
     */
    public function getFileModifiedAsDateTime(File $file): DateTime
    {
        return (new DateTime())->setTimestamp($file->modified());
    }

    /**
     * Returns the page title as a string.
     */
    public function getPageTitle(Page $page): string
    {
        return $page->title()->toString();
    }

    /**
     * Returns the page URL as a string.
     */
    public function getPageUrl(Page $page): string
    {
        return $page->url();
    }

    /**
     * @param Page $page
     * @return string
     */
    public function getPageId(Page $page): string
    {
        return $page->id();
    }

    /**
     * Returns the template name (type) of the page as a string.
     * @param Page $page
     * @return string
     */
    public function getPageType(Page $page): string
    {
        return $page->template()->name();
    }

    // -------------------------------------------------------------------------
    // SITE FIELDS
    // -------------------------------------------------------------------------

    /**
     * Returns the raw Field for the given site field name.
     *
     * @throws KirbyRetrievalException
     */
    public function getSiteField(string $fieldName): Field
    {
        try {
            $siteField = $this->site->content()->get($fieldName);
            if (!$siteField instanceof Field) {
                throw new KirbyRetrievalException('Site field not found');
            }
            if ($siteField->isEmpty()) {
                throw new KirbyRetrievalException('Site field is empty');
            }
            return $siteField;
        } catch (InvalidArgumentException) {
            throw new KirbyRetrievalException('Site field not found');
        }
    }

    /**
     * Returns true when the site field exists and is not empty.
     */
    public function isSiteFieldNotEmpty(string $fieldName): bool
    {
        try {
            return $this->site->content()->get($fieldName)->isNotEmpty();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Returns a site field value as a string.
     *
     * @throws KirbyRetrievalException
     */
    public function getSiteFieldAsString(string $fieldName, bool $required = false): string
    {
        try {
            $siteField = $this->getSiteField($fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $siteField->toString();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns a site field value as a boolean.
     *
     * @throws KirbyRetrievalException
     */
    public function getSiteFieldAsBool(string $fieldName, bool $required = false, bool $default = false): bool
    {
        try {
            $siteField = $this->getSiteField($fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $siteField->toBool();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return $default;
        }
    }

    /**
     * Returns a site field value as an integer.
     *
     * @param string $fieldName
     * @param bool $required
     * @param int $default
     * @return int
     * @throws KirbyRetrievalException
     */
    public function getSiteFieldAsInt(string $fieldName, bool $required = false, int $default = 0): int
    {
        try {
            $siteField = $this->getSiteField($fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $siteField->toInt();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return $default;
        }
    }

    /**
     * Returns a site field value as a Kirby Structure.
     *
     * @throws KirbyRetrievalException
     */
    public function getSiteFieldAsStructure(string $fieldName): Structure
    {
        $siteField = $this->getSiteField($fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $siteField->toStructure();
    }

    /**
     * Returns the page referenced by the site field.
     *
     * @throws KirbyRetrievalException
     */
    public function getSiteFieldAsPage(string $fieldName): Page
    {
        $siteField = $this->getSiteField($fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $siteField->toPage();
    }

    /**
     * Returns a site field value rendered as KirbyText HTML.
     *
     * @throws KirbyRetrievalException
     */
    public function getSiteFieldAsKirbyText(string $fieldName): string
    {
        $siteField = $this->getSiteField($fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $siteField->kti()->toString();
    }

    /**
     * Returns the rendered HTML of a site blocks field, or an empty string if
     * the field is empty or missing.
     */
    public function getSiteFieldAsBlocksHtml(string $fieldName): string
    {
        try {
            $siteField = $this->getSiteField($fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return (string) $siteField->toBlocks()->toHtml();
        } catch (KirbyRetrievalException) {
            return '';
        }
    }

    /**
     * Returns the file referenced by the site field.
     *
     * @throws KirbyRetrievalException
     */
    public function getSiteFieldAsFile(string $fieldName): File
    {
        $siteField = $this->getSiteField($fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        $file = $siteField->toFile();
        if ($file instanceof File) {
            return $file;
        }
        throw new KirbyRetrievalException('The site file field ' . $fieldName . ' could not be resolved');
    }

    /**
     * Returns a site field value as a split array of strings.
     *
     * @return string[]
     * @throws KirbyRetrievalException
     */
    public function getSiteFieldAsArray(string $fieldName, bool $required = false): array
    {
        try {
            $siteField = $this->getSiteField($fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $siteField->split();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return [];
        }
    }

    /**
     * Returns a site structure field as an array of 'name' strings.
     *
     * @return string[]
     * @throws KirbyRetrievalException
     */
    public function getSiteStructureFieldAsArray(string $fieldName, bool $required = false): array
    {
        try {
            $siteFieldAsStructure = $this->getSiteFieldAsStructure($fieldName);
            return $this->getStructureAsArray($siteFieldAsStructure);
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // STRUCTURE FIELDS
    // -------------------------------------------------------------------------

    /**
     * Returns the raw Field for the given field name on a structure object.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureField(StructureObject $structure, string $fieldName): Field
    {
        $structureField = $structure->content()->get($fieldName);
        if (!$structureField instanceof Field || $structureField->isEmpty()) {
            throw new KirbyRetrievalException('Structure field not found or empty');
        }
        return $structureField;
    }

    /**
     * Returns true when the structure field exists and is not empty.
     */
    public function hasStructureField(StructureObject $structure, string $fieldName): bool
    {
        $structureField = $structure->content()->get($fieldName);
        return ($structureField->isNotEmpty() && $structureField instanceof Field);
    }

    /**
     * Returns a structure field value as a string.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsString(
        StructureObject $structure,
        string          $fieldName,
        bool            $required = false,
    ): string {
        try {
            $structureField = $this->getStructureField($structure, $fieldName);
            return $structureField->toString();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns a structure field value as a boolean.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsBool(
        StructureObject $structure,
        string          $fieldName,
        bool            $required = false,
        bool            $default = false,
    ): bool {
        try {
            $structureField = $this->getStructureField($structure, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $structureField->toBool();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return $default;
        }
    }

    /**
     * Returns a structure field value as an integer.
     * NOTE: returns 0 (or $default) when the field is missing and not required.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsInt(
        StructureObject $structure,
        string          $fieldName,
        bool            $required = false,
        int             $default = 0,
    ): int {
        try {
            $structureField = $this->getStructureField($structure, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $structureField->toInt();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            //TODO: should be better than returning zero if not required. Maybe return int|null
            return $default;
        }
    }

    /**
     * Returns a structure field value as a float.
     * NOTE: returns 0 (or $default) when the field is missing and not required.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsFloat(
        StructureObject $structure,
        string          $fieldName,
        bool            $required = false,
        float           $default = 0,
    ): float {
        try {
            $structureField = $this->getStructureField($structure, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $structureField->toFloat();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return $default;
        }
    }

    /**
     * Returns a structure field value rendered as KirbyText HTML.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsKirbyText(
        StructureObject $structure,
        string          $fieldName,
        bool            $required = false,
    ): string {
        try {
            $structureField = $this->getStructureField($structure, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $structureField->kti()->toString();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns a structure field as a resolved URL string.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsLinkUrl(StructureObject $structure, string $fieldName): string
    {
        try {
            $structureField = $this->getStructureField($structure, $fieldName);
            if ($structureField->isNotEmpty()) {
                /** @noinspection PhpUndefinedMethodInspection */
                return $structureField->toUrl();
            }
        } finally {
            return '';
        }
    }

    /**
     * Returns the Kirby Page referenced by the structure field.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsPage(StructureObject $structure, string $fieldName): Page
    {
        $structureField = $this->getStructureField($structure, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        $page = $structureField->toPage();
        if ($page) {
            return $page;
        }
        throw new KirbyRetrievalException('The page field ' . $fieldName . ' does not exist');
    }

    /**
     * Returns the Kirby File referenced by the structure field.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsFile(StructureObject $structure, string $fieldName): File
    {
        $structureField = $this->getStructureField($structure, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        $file = $structureField->toFile();
        if ($file) {
            return $file;
        }
        throw new KirbyRetrievalException('The file field ' . $fieldName . ' does not exist');
    }

    /**
     * Returns the Kirby Files collection referenced by the structure field.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsFiles(StructureObject $structure, string $fieldName): Files
    {
        $structureField = $this->getStructureField($structure, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        $files = $structureField->toFiles();
        if ($files) {
            return $files;
        }
        throw new KirbyRetrievalException('The file field ' . $fieldName . ' does not exist');
    }

    /**
     * Returns the title of the page referenced by the structure field.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsPageTitle(StructureObject $structure, string $fieldName): string
    {
        $page = $this->getStructureFieldAsPage($structure, $fieldName);
        return $page->title()->value();
    }

    /**
     * Returns the structure as an array of 'name' field strings.
     *
     * @return string[]
     * @throws KirbyRetrievalException
     */
    public function getStructureAsArray(Structure $structure, bool $required = false): array
    {
        try {
            $returnArray = [];
            foreach ($structure as $structureObject) {
                $returnArray[] = $this->getStructureFieldAsString($structureObject, 'name');
            }
            return $returnArray;
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return [];
        }
    }

    /**
     * Returns the URL of the page referenced by the structure field.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsPageUrl(StructureObject $structure, string $fieldName): string
    {
        $page = $this->getStructureFieldAsPage($structure, $fieldName);
        return $page->url();
    }

    /**
     * Returns a structure field as a WebPageLink built from the referenced page.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsWebPageLink(StructureObject $structure, string $fieldName): WebPageLink
    {
        $structureField = $this->getStructureField($structure, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        $linkPage = $structureField->toPage();
        if ($linkPage->isNotEmpty()) {
            return new WebPageLink(
                $linkPage->title()->value(),
                $linkPage->url(),
                $linkPage->id(),
                $linkPage->template()->name(),
            );
        }
        $webPageLink = new WebPageLink('Not found', '', '', '');
        $webPageLink->setStatus(false);
        return $webPageLink;
    }

    /**
     * Returns block content from a structure field rendered as HTML, optionally excerpted.
     *
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsBlocksHtml(
        StructureObject $structureObject,
        string          $fieldName,
        bool            $required = false,
        int             $excerpt = 0,
    ): string {
        try {
            $structureField = $this->getStructureField($structureObject, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            $blockContent = $structureField->toBlocks()->toHtml();
            return ($excerpt === 0) ? $blockContent : Str::excerpt($blockContent, 200);
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    // -------------------------------------------------------------------------
    // ENTRIES FIELDS
    // -------------------------------------------------------------------------

    /**
     * Returns an entries field as an array of string values.
     *
     * @return string[]
     * @throws KirbyRetrievalException
     */
    public function getEntriesFieldAsStringArray(Page $page, string $fieldName): array
    {
        $field = $this->getPageField($page, $fieldName);
        $fieldAsArray = [];
        /** @noinspection PhpUndefinedMethodInspection */
        foreach ($field->toEntries() as $entry) {
            $fieldAsArray[] = $entry->toString();
        }
        return $fieldAsArray;
    }


    // -------------------------------------------------------------------------
    // BLOCK FIELDS
    // -------------------------------------------------------------------------

    /**
     * Returns the raw Field for the given field name on a block.
     *
     * @throws KirbyRetrievalException
     */
    public function getBlockField(Block $block, string $fieldName): Field
    {
        $blockField = $block->content()->get($fieldName);
        if (!$blockField instanceof Field || $blockField->isEmpty()) {
            throw new KirbyRetrievalException('Block field not found or empty');
        }
        return $blockField;
    }

    /**
     * Returns true when the block field exists and is not empty.
     */
    public function isBlockFieldNotEmpty(Block $block, string $fieldName): bool
    {
        try {
            $blockField = $this->getBlockField($block, $fieldName);
            return $blockField->isNotEmpty();
        } catch (KirbyRetrievalException) {
            return false;
        }
    }

    /**
     * Returns a block field value as a string.
     *
     * @throws KirbyRetrievalException
     */
    public function getBlockFieldAsString(Block $block, string $fieldName, bool $required = false): string
    {
        try {
            $blockField = $this->getBlockField($block, $fieldName);
            return $blockField->toString();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns a block field value decoded from YAML.
     *
     * @throws KirbyRetrievalException
     */
    public function getBlockFieldAsYaml(
        Block  $block,
        string $fieldName,
        bool   $required = false,
        array  $default = [],
    ): array {
        try {
            $blockField = $this->getBlockField($block, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $blockField->yaml();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return $default;
        }
    }

    /**
     * Returns a block field value as a Kirby Structure.
     *
     * @throws KirbyRetrievalException
     */
    public function getBlockFieldAsStructure(Block $block, string $fieldName): Structure
    {
        $blockField = $this->getBlockField($block, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $blockField->toStructure();
    }

    /**
     * Returns a block field value as an integer.
     *
     * @throws KirbyRetrievalException
     */
    public function getBlockFieldAsInt(Block $block, string $fieldName, bool $required = false): int
    {
        try {
            $blockField = $this->getBlockField($block, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $blockField->toInt();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            //TODO: do better than returning zero
            return 0;
        }
    }

    /**
     * Returns a block field value as a Kirby Blocks collection.
     *
     * @throws KirbyRetrievalException
     */
    public function getBlockFieldAsBlocks(Block $block, string $fieldName, bool $required = false): Blocks
    {
        try {
            $blockField = $this->getBlockField($block, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            return $blockField->toBlocks();
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return new Blocks();
        }
    }

    /**
     * Returns a block field rendered as HTML.
     *
     * @throws KirbyRetrievalException
     */
    public function getBlockFieldAsBlocksHtml(Block $block, string $fieldName, bool $required = false): string
    {
        try {
            $blockField = $this->getBlockField($block, $fieldName);
            $blocksHTML = '';
            /** @noinspection PhpUndefinedMethodInspection */
            foreach ($blockField->toBlocks() as $innerBlock) {
                $blocksHTML .= $this->getHTMLfromBlock($innerBlock);
            }
            return $blocksHTML;
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return '';
        }
    }

    /**
     * Returns the Kirby File referenced by the block field, or null.
     *
     * @throws KirbyRetrievalException
     */
    public function getBlockFieldAsFile(Block $block, string $fieldName): File|null
    {
        $blockField = $this->getBlockField($block, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $blockField->toFile();
    }

    /**
     * Converts a block to its HTML representation, resolving @page permalinks to URLs
     * for text and list blocks. When a GlossaryService is available, glossary links
     * are enriched with definition title attributes after permalink resolution.
     */
    public function getHTMLfromBlock(Block $block): string
    {
        if (in_array($block->type(), ['text', 'list'])) {
            /** @noinspection PhpUndefinedMethodInspection */
            $html = $block->text()->toHtml()->permalinksToUrls()->toString();
            return $this->glossaryService?->enrichHtml($html) ?? $html;
        }
        return $block->toHtml();
    }

    // -------------------------------------------------------------------------
    // USER FIELDS
    // -------------------------------------------------------------------------

    /**
     * Returns true when the user field exists and is not empty.
     */
    public function isUserFieldNotEmpty(User $user, string $fieldName): bool
    {
        return $user->{$fieldName}()->isNotEmpty();
    }

    /**
     * Returns a user field value as a string.
     */
    public function getUserFieldAsString(User $user, string $fieldName, string $default = ''): string
    {
        return $user->{$fieldName}()->value() ?? $default;
    }

    /**
     * Returns the current authenticated user's field value as a string.
     */
    public function getCurrentUserFieldAsString(string $fieldName): string
    {
        return $this->getUserFieldAsString($this->kirby->user(), $fieldName);
    }

    /**
     * Returns a user field value as a boolean.
     */
    public function getUserFieldAsBool(User $user, string $fieldName, bool $default = false): bool
    {
        return $user->{$fieldName}()->toBool() ?? $default;
    }

    /**
     * Returns the current authenticated user's field value as a boolean.
     */
    public function getCurrentUserFieldAsBool(string $fieldName): bool
    {
        return $this->getUserFieldAsBool($this->kirby->user(), $fieldName);
    }

    /**
     * Returns a user field value as a structure.
     *
     * An absent or malformed field yields an empty structure rather than an
     * error: these fields are written by the application and read on pages a user
     * needs, so a bad row should cost that row and not the page.
     */
    public function getUserFieldAsStructure(User $user, string $fieldName): Structure
    {
        try {
            return $user->{$fieldName}()->toStructure();
        } catch (Throwable) {
            return new Structure();
        }
    }

    /**
     * Returns the current authenticated user's field value as a structure.
     */
    public function getCurrentUserFieldAsStructure(string $fieldName): Structure
    {
        $user = $this->kirby->user();

        return $user instanceof User ? $this->getUserFieldAsStructure($user, $fieldName) : new Structure();
    }

    /**
     * Returns a user field value as the slug of the referenced page.
     */
    public function getUserFieldAsSlug(User $user, string $fieldName, string $default = ''): string
    {
        return $user->{$fieldName}()->toPage()->slug() ?? $default;
    }

    /**
     * Returns the current authenticated user's field value as the slug of the referenced page.
     */
    public function getCurrentUserFieldAsSlug(string $fieldName, string $default = ''): string
    {
        return $this->getUserFieldAsSlug($this->kirby->user(), $fieldName, $default);
    }

    /**
     * Returns a user field value as a Kirby Pages collection or null.
     */
    public function getUserFieldAsPages(User $user, string $fieldName): Pages|null
    {
        try {
            return $user->{$fieldName}()->toPages();
        } catch (Throwable) {
            return null;
        }
    }
}
