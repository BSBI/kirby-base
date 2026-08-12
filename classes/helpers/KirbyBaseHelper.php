<?php

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\forms\BaseFormDefinition;
use BSBI\WebBase\forms\FormPageInterface;
use BSBI\WebBase\models\ActionStatus;
use BSBI\WebBase\models\BaseFilter;
use BSBI\WebBase\models\BaseList;
use BSBI\WebBase\models\BaseModel;
use BSBI\WebBase\models\BaseWebPage;
use BSBI\WebBase\models\CoreLink;
use BSBI\WebBase\models\Document;
use BSBI\WebBase\models\Documents;
use BSBI\WebBase\models\FeedbackForm;
use BSBI\WebBase\models\Image;
use BSBI\WebBase\models\ImageList;
use BSBI\WebBase\models\ImageSizes;
use BSBI\WebBase\models\ImageType;
use BSBI\WebBase\models\Language;
use BSBI\WebBase\models\Languages;
use BSBI\WebBase\models\LoginDetails;
use BSBI\WebBase\models\OnThisPageLink;
use BSBI\WebBase\models\PageCreationResult;
use BSBI\WebBase\models\OnThisPageLinks;
use BSBI\WebBase\models\Pagination;
use BSBI\WebBase\models\PrevNextPageNavigation;
use BSBI\WebBase\models\WebPageBlock;
use BSBI\WebBase\models\WebPageBlocks;
use BSBI\WebBase\models\WebPageLink;
use BSBI\WebBase\models\WebPageLinks;
use BSBI\WebBase\models\User;
use BSBI\WebBase\models\UserList;
use BSBI\WebBase\models\WebPageTagLinks;
use BSBI\WebBase\models\WebPageTagLinkSet;
use BSBI\WebBase\traits\OptionsHandling;
use DateMalformedStringException;
use DateTime;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Cms\Block;
use Kirby\Cms\Blocks;
use Kirby\Cms\Responder;
use Kirby\Cms\Response;
use Kirby\Data\Data;
use Kirby\Data\Yaml;
use Kirby\Toolkit\Collection;
use Kirby\Cms\File;
use Kirby\Cms\Files;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Cms\Structure;
use Kirby\Cms\StructureObject;
use Kirby\Content\Field;
use Kirby\Exception\Exception;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Http\Cookie;
use Kirby\Toolkit\Str;
use Throwable;

/**
 *
 */
abstract class KirbyBaseHelper
{
    use OptionsHandling;
    /** @deprecated Use SearchTextHelper::STOP_WORDS instead */
    private const array STOP_WORDS = SearchTextHelper::STOP_WORDS;

    protected const string ASSETS_PATH = '/media/plugins/open-foundations/kirby-base/';

    protected KirbyFieldReader $fieldReader;
    protected GlossaryService $glossaryService;
    protected ImageService $imageService;
    protected NavigationService $navigationService;
    protected SearchService $searchService;
    protected CollectionFilterService $collectionFilterService;
    protected PageTreeService $pageTreeService;
    protected UserService $userService;
    protected FileDeliveryService $fileDeliveryService;
    private FilterResetService $filterResetService;
    private FilterCookiePolicy $filterCookiePolicy;

    #region CONSTRUCTOR
    /**
     * The Kirby object
     * @var App
     */
    protected App $kirby;
    /**
     * The Kirby site object
     * @var Site
     */
    protected Site $site;
    /**
     * The Kirby page object
     * @var Page|null
     */
    protected ?Page $page;

    const string COOKIE_CONSENT_NAME = 'cookieConsent';
    const string LEGACY_COOKIE_CONSENT_NAME = 'cookieConsentGiven';

    /**
     *
     */
    public function __construct()
    {
        $this->kirby = kirby();
        $this->site = site();
        $this->page = page();
        $this->glossaryService = new GlossaryService($this->kirby, $this->site);
        $this->fieldReader = new KirbyFieldReader($this->kirby, $this->site, $this->glossaryService);
        $this->imageService = new ImageService($this->fieldReader);
        $this->navigationService = new NavigationService(
            $this->fieldReader,
            $this->imageService,
            $this->site,
            function (string $templateName, Page $page, WebPageLink $webPageLink): WebPageLink {
                $methodName = 'getWebPageLinkFor' . ucfirst($templateName);
                if (method_exists($this, $methodName)) {
                    return $this->$methodName($page, $webPageLink);
                }
                return $webPageLink;
            }
        );
        $this->searchService = new SearchService(
            $this->site,
            $this->kirby,
            fn () => $this->hasSessionCookie(),
        );
        $this->collectionFilterService = new CollectionFilterService($this->fieldReader);
        $this->pageTreeService = new PageTreeService();
        $this->userService = new UserService(
            $this->kirby,
            $this->fieldReader,
            fn () => $this->hasSessionCookie(),
        );
        $this->fileDeliveryService = new FileDeliveryService();
    }
    #endregion

    #region PAGES

    /**
     * @return BaseWebPage
     * @noinspection PhpUnused
     */
    abstract function getBasicPage(): BaseWebPage;

    /**
     * @param Page $kirbyPage
     * @param BaseWebPage $currentPage
     * @return BaseWebPage
     * @noinspection PhpUnused
     */
    abstract function setBasicPage(Page $kirbyPage, BaseWebPage $currentPage): BaseWebPage;

    /**
     * @param string $pageClass
     * @return BaseWebPage
     */
    public function getCurrentPage(string $pageClass = BaseWebPage::class): BaseWebPage
    {
        return $this->getSpecificPage($this->page->id(), $pageClass);
    }

    /**
     * Retrieves a specific page by its ID and casts it to the specified page class type.
     * Optionally checks user roles during the process. Handles errors and allows for
     * additional processing via customisable methods.
     *
     * @param string $pageId The unique identifier of the page to be retrieved.
     * @param string $pageClass The class to which the page should be cast. Defaults to BaseWebPage.
     * @param bool $checkUserRoles Whether to validate user roles during the retrieval process. Defaults to true.
     * @return BaseWebPage The retrieved and processed page object.
     */
    public function getSpecificPage(string $pageId,
                                    string $pageClass = BaseWebPage::class,
                                    bool   $checkUserRoles = true): BaseWebPage
    {
        try {
            $kirbyPage = $this->getKirbyPage($pageId);
            $page = $this->getPage($kirbyPage, $pageClass, $checkUserRoles);

            if (method_exists($this, 'setBasicPage')) {
                $page = $this->setBasicPage($kirbyPage, $page);
            }

            $this->applyPendingFormFlash($page);

            $setPageFunction = 'set' . $this->extractClassName($pageClass);

            if (method_exists($this, $setPageFunction)) {
                $page = $this->$setPageFunction($kirbyPage, $page);
            }

        } catch (KirbyRetrievalException $e) {
            $page = $this->handlePageError($e);
        }
        return $page;
    }


    /**
     * @param Page $page
     * @param string $pageClass the type of class to return (must extend WebPage)
     * @param bool $checkUserRoles
     * @return BaseWebPage
     */
    protected function getPage(Page   $page,
                               string $pageClass = BaseWebPage::class,
                               bool   $checkUserRoles = true): BaseWebPage
    {
        try {
            if (!(is_a($pageClass, BaseWebPage::class, true))) {
                throw new KirbyRetrievalException("Page class must extend BaseWebPage.");
            }

            $pageTitle = $page->title()->toString();

            $webPage = new $pageClass(
                $pageTitle,
                $page->url(),
                $page->template()->name()
            );

            $webPage->setHtmlTitle($pageTitle . ' - ' . site()->title()->toString());

            $webPage->setPageId($page->id());

            $webPage->setUrlWithQueryString($_SERVER['REQUEST_URI']);

            $user = $this->getCurrentUser();

            $webPage->setCurrentUser($user);

            if ($checkUserRoles) {
                if (!$this->checkPagePermissions($page)) {
                    $this->redirectToLogin($page->url());
                }
            }

            if (!$this->isCurrentUserAdminOrEditor()) {
                $password = $this->getPageFieldAsString($page, 'password');
                if ($password === '') {
                    // Fall back to a template-wide password (e.g. to gate every
                    // auto-generated order page behind one temporary password).
                    $password = TemplatePasswordPolicy::passwordForTemplate(
                        $page->template()->name(),
                        $this->kirby->option('templatePasswords', [])
                    );
                }
                if (!empty($password)) {
                    $passwordFromUser = $this->getRequestAsString('password');
                    if (!empty($passwordFromUser)) {
                        if (!csrf(get('passwordCsrf')) === true) {
                            $webPage->setStatus(false);
                            $webPage->setIsCriticalError(true);
                            $webPage->addFriendlyMessage('Your security token has expired - please login again.');
                            $webPage->setIsPasswordProtected(true);
                        }
                    }
                    if (empty($passwordFromUser)) {
                        $webPage = $this->passwordProtectPage($webPage);
                    } else {
                        if ($password !== $passwordFromUser) {
                            $webPage = $this->passwordProtectPage($webPage);
                            $webPage->addFriendlyMessage('The password you entered is not correct.');
                        }
                    }
                }
            }



            $webPage->setDescription(
                $this->isPageFieldNotEmpty($page, 'description')
                    ? $this->getPageFieldAsString($page, 'description')
                    : $this->getSiteFieldAsString('description')
            );

            $webPage->setBreadcrumb($this->getBreadcrumb());

            $webPage->setMenuPages($this->getMenuPages());


            if ($webPage->doSimpleGetSubPages()) {
                $webPage->setSubPages($this->getSubPages($page, $webPage->isUsingSimpleLinksForSubPages(), null, $webPage->doesLoadSubPageGrandchildren()));
            }
            if ($this->isPageFieldNotEmpty($page, 'mainContent')) {
                $webPage->setMainContentBlocks($this->getContentBlocks($page));
            }

            if ($this->isPageFieldNotEmpty($page, 'lowerContent')) {
                $webPage->setLowerContentBlocks($this->getContentBlocks($page, 'lowerContent'));
            }

            $webPage->setOnThisPageLinks($this->getOnThisPageLinks($webPage));

            if ($this->isPageFieldNotEmpty($page, 'related')) {
                if ($this->getPageFieldType($page, 'related') === 'structure') {
                    $webPage->setRelatedContentList($this->getRelatedContentListFromStructureField(
                        $page,
                        'related',
                        ImageType::PANEL
                    ));
                }
                if ($this->getPageFieldType($page, 'related') === 'pages') {
                    $webPage->setRelatedContentList($this->getRelatedContentListFromPagesField($page));
                }
            }

            $webPage->setTagLinks($this->getTagLinks($page));

            $openGraphTitle = $this->isPageFieldNotEmpty($page, 'og_title')
                ? $this->getPageFieldAsString($page, 'og_title')
                : $page->title()->toString();
            $webPage->setOpenGraphTitle($openGraphTitle);

            $openGraphDescription = $this->isPageFieldNotEmpty($page, 'og_description')
                ? $this->getPageFieldAsString($page, 'og_description')
                : $this->getSiteFieldAsString('og_description');

            $webPage->setOpenGraphDescription($openGraphDescription);

            if ($this->isPageFieldNotEmpty($page, 'og_image')) {
                $ogImage = $this->getPageFieldAsFile($page, 'og_image');
                $webPage->setOpenGraphImage($ogImage->url());
            } else {
                $webPage->setOpenGraphImage($this->site->url() . '/assets/images/BSBI-long-colour.svg');
            }

            $webPage->setLanguages($this->getLanguages());

            $webPage->setRequiresCookieConsent($this->requiresCookieConstent());

            if ($webPage->doesRequireCookieConsent()) {
                if ($this->hasCookieConsent()) {
                    $webPage->setIsCookieConsentGiven(true);
                } elseif ($this->hasCookieConsentRejected()) {
                    $webPage->setIsCookieConsentRejected(true);
                }
                // Cookie consent UI is handled client-side via JavaScript
            }

            //add scripts for blocks
            if ($webPage->hasBlockofType('video')) {
                $webPage->addScript('lite-youtube');
            }

            // Only access session if a session cookie exists (to avoid breaking page cache)
            $sessionCookieName = $this->kirby->option('session')['cookieName'] ?? 'kirby_session';
            if (isset($_COOKIE[$sessionCookieName])) {
                $session = $this->kirby->session();
                /** @noinspection PhpUndefinedMethodInspection */
                if ($actionStatus = $session->pull('actionStatus')) {
                    /** @var ActionStatus $actionStatus */
                    $webPage->setStatus($actionStatus->getStatus());
                    $webPage->addFriendlyMessage($actionStatus->getFirstFriendlyMessage());
                }
            } elseif ($actionStatus = get('actionStatus')) {
                $webPage->setStatus($actionStatus);
                $webPage->addFriendlyMessage(get('friendlyMessage', 'Unknown status'));
            }

            $query = $this->getSearchQuery();

            if (!empty($query)) {
                $webPage->setQuery($query);
                $webPage = $this->highlightSearchQuery($webPage, $query);
            }

            // Set HTTP cache headers based on page permissions
            $this->setCacheHeaders($page);
        } catch (KirbyRetrievalException $e) {
            $webPage = $this->handlePageError($e);
        }

        return $webPage;
    }

    /**
     * @param Page $kirbyPage
     * @param string $pageClass
     * @return BaseWebPage
     */
    protected function getEmptyWebPage(Page $kirbyPage, string $pageClass = BaseWebPage::class): BaseWebPage
    {
        $webPage = new $pageClass($kirbyPage->title()->toString(), $kirbyPage->url(), $kirbyPage->template()->name());
        $webPage->setPageId($kirbyPage->id());
        $user = $this->getCurrentUser();
        $webPage->setCurrentUser($user);
        return $webPage;
    }

    /**
     * @param string $title
     * @return BaseWebPage
     * @noinspection PhpUnused
     */
    protected function findPage(string $title): BaseWebPage
    {
        $page = $this->findKirbyPage($title);
        if ($page !== null) {
            $webPage = new BaseWebPage($page->title()->toString(), $page->url(), $page->template()->name());
        } else {
            $webPage = new BaseWebPage('', '', '');
            $webPage->setStatus(false);
        }
        return $webPage;
    }

    /**
     * @param string $pageId
     * @return Page
     * @throws KirbyRetrievalException
     */
    protected function getKirbyPage(string $pageId): Page
    {
        $page = $this->kirby->page($pageId);
        if (!$page instanceof Page) {
            throw new KirbyRetrievalException('Page not found');
        }
        return $page;
    }

    /**
     * @param string $title
     * @param Page|null $parentPage
     * @return Page|null
     */
    protected function findKirbyPage(string $title, ?Page $parentPage = null): Page|null
    {
        $page = $parentPage === null
            ? $this->site->children()->find($title)
            : $parentPage->children()->find($title);
        if ($page !== null) {
            if ($page instanceof Page) {
                return $page;
            }
            if ($page instanceof Collection) {
                if ($page->first() instanceof Page) {
                    return $page->first();
                }
            }
        }
        return null;
    }

    /**
     * Finds a Kirby page by its ID, including draft pages, by walking the path
     * segment by segment. Avoids loading the full site index.
     *
     * Use this instead of page() or getKirbyPage() when the page may be a draft
     * (e.g. a form page taken offline that still has submissions to export).
     *
     * @param string $pageId Slash-delimited page ID, e.g. 'forms/contact'.
     * @return Page|null The found page, or null if not found at any draft/published level.
     */
    public function findKirbyPageOrDraft(string $pageId): ?Page
    {
        $page = $this->kirby->page($pageId);
        if ($page !== null) {
            return $page;
        }

        // kirby->page() only resolves published pages; walk each path segment so
        // we can locate draft pages without loading the entire site index.
        $parts   = explode('/', $pageId);
        $current = $this->site->children()->findBy('slug', $parts[0])
                   ?? $this->site->drafts()->findBy('slug', $parts[0]);
        for ($i = 1; $i < count($parts) && $current !== null; $i++) {
            $current = $current->children()->findBy('slug', $parts[$i])
                       ?? $current->drafts()->findBy('slug', $parts[$i]);
        }

        return $current instanceof Page ? $current : null;
    }

    /**
     * @param string $collectionName
     * @param string $sortBy
     * @return Collection
     * @throws KirbyRetrievalException
     */
    protected function getPagesFromCollection(string $collectionName, string $sortBy = ''): Collection
    {
        $pages = $this->kirby->collection($collectionName);
        if (!isset($pages)) {
            throw new KirbyRetrievalException('Collection ' . $collectionName . ' pages not found');
        }
        if (!empty($sortBy)) {
            $pages = $pages->sortBy($sortBy);
        }
        return $pages;
    }

    /**
     * @param string $collectionName
     * @param string $sortBy
     * @return array
     * @throws KirbyRetrievalException
     */
    protected function getPageTitlesFromCollection(string $collectionName, string $sortBy = ''): array
    {
        $pages = $this->getPagesFromCollection($collectionName, $sortBy);
        $fields = $pages->pluck('title');

        // Check if the result is an array and if its first element is a Field object
        if (count($fields) > 0 && $fields[0] instanceof Field) {
            // If it is, map the array to convert each Field object to a string
            return array_map(function ($field) {
                return (string)$field;
            }, $fields);
        }

        // Otherwise, return the result directly (it should already be a string array)
        return $fields;
    }

    /**
     * @param string $pageId
     * @return string
     * @noinspection PhpUnused
     */
    public function getParentPageName(string $pageId): string
    {
        $page = $this->kirby->page($pageId);
        if ($page instanceof Page) {
            $parentPage = $page->parent();
            if ($parentPage instanceof Page) {
                return $parentPage->title()->toString();
            }
        }
        return '';
    }

    private function passwordProtectPage(BaseWebPage $webPage): BaseWebPage
    {
        $webPage->setStatus(false);
        $webPage->setIsCriticalError(true);
        $webPage->addFriendlyMessage('This page is protected by a password.');
        $webPage->setIsPasswordProtected(true);
        $webPage->setPasswordCSRFToken($this->getCSFRToken());
        return $webPage;
    }

    #endregion

    #region PAGE_CREATE_AND_UPDATE

    /**
     * @param Page $parentPage
     * @param array $pageData
     * @param bool $createAsListed
     * @return Page
     * @throws KirbyRetrievalException
     */
    protected function createPage(Page $parentPage, array $pageData, bool $createAsListed = false): Page
    {
        try {
            $createdPage = $this->kirby->impersonate('kirby', function () use ($parentPage, $pageData, $createAsListed) {
                $newPage = $parentPage->createChild($pageData);
                if ($createAsListed) {
                    $newPage = $newPage->changeStatus('listed');
                }
                return $newPage;
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
        return $createdPage;
    }

    /**
     * Creates a child page, recovering the winning page when the create loses
     * a race against a concurrent request creating the same slug.
     *
     * Kirby's createChild()/changeStatus() pair is not atomic: two overlapping
     * requests (e.g. a double-submitted form) can both take the create path and
     * the loser fails with "The page directory cannot be moved". By then the
     * page exists — this method hands it back instead of failing, flagged as
     * recovered so the caller can run its update path.
     *
     * @param Page $parentPage The parent to create the child under
     * @param array<string, mixed> $pageData Page data (slug, template, content, etc.)
     * @param bool $createAsListed Whether to publish the page immediately as listed
     * @return PageCreationResult The created or recovered page, with a recovered flag
     * @throws KirbyRetrievalException If creation failed and no page exists for the slug
     */
    protected function createOrRecoverPage(Page $parentPage, array $pageData, bool $createAsListed = false): PageCreationResult
    {
        try {
            return new PageCreationResult($this->createPage($parentPage, $pageData, $createAsListed), false);
        } catch (KirbyRetrievalException $createFailure) {
            $slug = isset($pageData['slug']) && is_string($pageData['slug']) ? $pageData['slug'] : '';
            if ($slug === '') {
                throw $createFailure;
            }
            try {
                $recovered = (new PageCreateRecovery($this->kirby))->recover($parentPage, $slug, $createAsListed);
            } catch (Throwable) {
                // Recovery is best-effort; the original failure is the story.
                throw $createFailure;
            }
            if ($recovered === null) {
                throw $createFailure;
            }
            return new PageCreationResult($recovered, true);
        }
    }

    /**
     * Creates a new top-level page directly under the site root.
     *
     * @param array $pageData Page data (slug, template, content, etc.)
     * @param bool $createAsListed Whether to publish the page immediately as listed
     * @return Page The newly created Page object
     * @throws KirbyRetrievalException If the page cannot be created
     */
    protected function createPageInSiteRoot(array $pageData, bool $createAsListed = false): Page
    {
        try {
            $createdPage = $this->kirby->impersonate('kirby', function () use ($pageData, $createAsListed) {
                $newPage = site()->createChild($pageData);
                if ($createAsListed) {
                    $newPage = $newPage->changeStatus('listed');
                }
                return $newPage;
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
        return $createdPage;
    }

    /**
     * @param Page $page
     * @param array $pageData
     * @return Page
     * @throws KirbyRetrievalException
     */
    protected function updatePage(Page $page, array $pageData): Page
    {
        try {
            return $this->kirby->impersonate('kirby', function () use ($page, $pageData) {
                return $page->update($pageData);
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    /**
     * @param Page $page
     * @return bool
     * @throws KirbyRetrievalException
     */
    protected function deletePage(Page $page): bool
    {
        try {
            return $this->kirby->impersonate('kirby', function () use ($page) {
                return $page->delete();
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    /**
     * Sends Cache-Control: no-store to prevent browsers (including bfcache) from
     * serving a stale snapshot of the current page on subsequent navigation.
     * Call from set*Page methods that display frequently-changing data.
     */
    protected function preventCaching(): void
    {
        header('Cache-Control: no-store');
    }

    /**
     * Publishes a draft page by changing its status to 'listed'.
     *
     * @param Page $page
     * @return Page
     * @throws KirbyRetrievalException
     */
    protected function publishPage(Page $page): Page
    {
        try {
            return $this->kirby->impersonate('kirby', function () use ($page) {
                return $page->changeStatus('listed');
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    /**
     * Moves a published page back to draft status.
     *
     * @param Page $page
     * @return Page
     * @throws KirbyRetrievalException
     */
    protected function unpublishPage(Page $page): Page
    {
        try {
            return $this->kirby->impersonate('kirby', function () use ($page) {
                return $page->changeStatus('draft');
            });
        } catch (Throwable $e) {
            throw new KirbyRetrievalException($e->getMessage());
        }
    }

    #endregion

    #region PAGE_FIELDS

    /**
     * Gets the field - if the field is empty, returns an empty string
     * @param string $fieldName
     * @param bool $required
     * @param string $default
     * @return string
     * @throws KirbyRetrievalException if the page or field cannot be found
     */
    public function getCurrentPageFieldAsString(string $fieldName, bool $required = false, string $default = ''): string
    {
        if (!isset($this->page)) {
            throw new KirbyRetrievalException('Current page not found');
        }
        return $this->getPageFieldAsString($this->page, $fieldName, $required, $default);
    }

    /**
     * @param string $fieldName
     * @param bool $required
     * @param string $default
     * @return string[]
     * @throws KirbyRetrievalException
     */
    public function getCurrentPageFieldAsArray(string $fieldName, bool $required = false): array
    {
        if (!isset($this->page)) {
            throw new KirbyRetrievalException('Current page not found');
        }
        return $this->getPageFieldAsArray($this->page, $fieldName, $required);
    }

    /**
     * @param string $fieldName
     * @param bool $required
     * @param bool $default
     * @return bool
     * @throws KirbyRetrievalException
     */
    public function getCurrentPageFieldAsBool(string $fieldName, bool $required = false, bool $default = false): bool
    {
        if (!isset($this->page)) {
            throw new KirbyRetrievalException('Current page not found');
        }
        return $this->getPageFieldAsBool($this->page, $fieldName, $required, $default);
    }

    /**
     * Gets the field - if the field is empty, returns an empty string
     * @return string
     * @throws KirbyRetrievalException if the page or field cannot be found
     * @noinspection PhpUnused
     */
    public function getCurrentPageTitle(): string
    {
        if (!isset($this->page)) {
            throw new KirbyRetrievalException('Current page not found');
        }
        return $this->page->title()->toString();
    }

    /**
     * Returns the page title as a string.
     * @param Page $page
     * @return string
     */
    public function getPageTitle(Page $page): string
    {
        return $this->fieldReader->getPageTitle($page);
    }

    /**
     * Returns the page URL as a string.
     * @param Page $page
     * @return string
     * @noinspection PhpUnused
     */
    public function getPageUrl(Page $page): string
    {
        return $this->fieldReader->getPageUrl($page);
    }

    /**
     * @param Page $page
     * @return string
     */
    public function getPageId(Page $page): string
    {
        return $this->fieldReader->getPageId($page);
    }

    /**
     * Returns the template name (type) of the page as a string.
     * @param Page $page
     * @return string
     */
    public function getPageType(Page $page): string
    {
        return $this->fieldReader->getPageType($page);
    }

    /**
     * Gets the field - if the field is empty, returns an empty string
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @param string $defaultValue
     * @return string
     * @throws KirbyRetrievalException if the page or field cannot be found
     */
    public function getPageFieldAsString(Page   $page,
                                         string $fieldName,
                                         bool   $required = false,
                                         string $defaultValue = ''): string
    {
        return $this->fieldReader->getPageFieldAsString($page, $fieldName, $required, $defaultValue);
    }

    /**
     * Retrieves the specified field from the page as a string. If the field is empty or an exception occurs,
     * then return the provided fallback value.
     *
     * @param Page $page The page object containing the field.
     * @param string $fieldName The name of the field to retrieve.
     * @param string $fallback The fallback value to return if the field is empty or an error occurs.
     * @return string The field value as a string, or the fallback value if the field is empty
     * or an exception is caught.
     * @noinspection PhpUnused
     */
    protected function getPageFieldAsStringWithFallback(Page $page, string $fieldName, string $fallback): string
    {
        return $this->fieldReader->getPageFieldAsStringWithFallback($page, $fieldName, $fallback);
    }

    /**
     * intended for use where the field will be output within Javascript, e.g. within a Leaflet map Javascript
     * section
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @param string $defaultValue
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsStringForJavascriptUse(Page   $page,
                                                            string $fieldName,
                                                            bool   $required = false,
                                                            string $defaultValue = ''): string
    {
        return $this->fieldReader->getPageFieldAsStringForJavascriptUse($page, $fieldName, $required, $defaultValue);
    }


    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @param int $default
     * @return int
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsInt(Page $page, string $fieldName, bool $required = false, int $default = 0): int
    {
        return $this->fieldReader->getPageFieldAsInt($page, $fieldName, $required, $default);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @return float
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getPageFieldAsFloat(Page $page, string $fieldName, bool $required = false): float
    {
        return $this->fieldReader->getPageFieldAsFloat($page, $fieldName, $required);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @param bool $default
     * @return bool
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsBool(Page   $page,
                                      string $fieldName,
                                      bool   $required = false,
                                      bool   $default = false): bool
    {
        return $this->fieldReader->getPageFieldAsBool($page, $fieldName, $required, $default);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getPageFieldAsYesNo(Page $page, string $fieldName, bool $required = false): string
    {
        return $this->fieldReader->getPageFieldAsYesNo($page, $fieldName, $required);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @return ?DateTime
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    public function getPageFieldAsDateTime(Page $page, string $fieldName, bool $required = false): ?DateTime
    {
        return $this->fieldReader->getPageFieldAsDateTime($page, $fieldName, $required);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return DateTime
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getPageFieldAsRequiredDateTime(Page $page, string $fieldName): DateTime
    {
        return $this->fieldReader->getPageFieldAsRequiredDateTime($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $isRequired
     * @return string
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsTime(Page $page, string $fieldName, bool $isRequired = false): string
    {
        return $this->fieldReader->getPageFieldAsTime($page, $fieldName, $isRequired);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $isRequired
     * @return Structure
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsStructure(Page $page, string $fieldName, bool $isRequired = false): Structure
    {
        return $this->fieldReader->getPageFieldAsStructure($page, $fieldName, $isRequired);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsUrl(Page $page, string $fieldName, bool $required = false): string
    {
        return $this->fieldReader->getPageFieldAsUrl($page, $fieldName, $required);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @param int $excerpt
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getPageFieldAsBlocksHtml(Page   $page,
                                                string $fieldName,
                                                bool   $required = false,
                                                int    $excerpt = 0): string
    {
        return $this->fieldReader->getPageFieldAsBlocksHtml($page, $fieldName, $required, $excerpt);
    }

    /**
     * Returns a plain-HTML excerpt from a blocks field, suitable for use in content index definitions.
     *
     * @param Page $page
     * @param string $fieldName
     * @param int $length Maximum character length of the excerpt (0 = no limit)
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsBlocksExcerpt(Page   $page,
                                                string $fieldName,
                                                int    $length = 200,
                                                bool   $required = false): string
    {
        return $this->fieldReader->getPageFieldAsBlocksHtml($page, $fieldName, $required, $length);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param int $length
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldTextBlocksAsExcerpt(Page   $page,
                                                       string $fieldName,
                                                       int    $length,
                                                       bool   $required = false): string
    {
        return $this->fieldReader->getPageFieldTextBlocksAsExcerpt($page, $fieldName, $length, $required);
    }


    /**
     * @param Page $page
     * @param string $fieldName
     * @return Blocks
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsBlocks(Page $page, string $fieldName): Blocks
    {
        return $this->fieldReader->getPageFieldAsBlocks($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @return string[]
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsArray(Page $page, string $fieldName, bool $required = false): array
    {
        return $this->fieldReader->getPageFieldAsArray($page, $fieldName, $required);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    public function getPageFieldAsKirbyText(Page $page, string $fieldName, bool $required = false): string
    {
        return $this->fieldReader->getPageFieldAsKirbyText($page, $fieldName, $required);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return File|null
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsFile(Page $page, string $fieldName): File|null
    {
        return $this->fieldReader->getPageFieldAsFile($page, $fieldName);
    }

    /**
     * Returns an Image model for the given Kirby File, generating thumbnails and srcsets.
     *
     * @param File $file The Kirby file to generate the image from.
     * @param int $width The desired image width in pixels.
     * @param int $height The desired image height in pixels.
     * @return Image
     */
    public function getFileAsImage(File $file, int $width, int $height): Image
    {
        return $this->imageService->getImageFromFile($file, $width, $height);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return File|null
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsFiles(Page $page, string $fieldName): Files|null
    {
        return $this->fieldReader->getPageFieldAsFiles($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $simpleLinks
     * @return WebPageLink
     * @noinspection PhpUnused
     */
    protected function getPageFieldAsWebPageLink(Page $page, string $fieldName, bool $simpleLinks = true): WebPageLink
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            $page = $pageField->toPage();
            if ($page) {
                return $this->getWebPageLink($page, $simpleLinks);
            } else {
                return (new WebPageLink('', '', '', 'error'))->recordError('Page not found: not page type');
            }
        } catch (KirbyRetrievalException $e) {
            return (new WebPageLink('', '', '', 'error'))->recordError('Page not found: ' . $e->getMessage());
        }
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $simpleLinks
     * @param bool $required
     * @return WebPageLinks
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsWebPageLinks(Page $page, string $fieldName, bool $simpleLinks = true, bool $required = false): WebPageLinks
    {
        try {
            $pageField = $this->getPageField($page, $fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            $pages = $pageField->toPages()->listed();
            return $this->getWebPageLinks($pages, $simpleLinks);
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return new WebPageLinks();
        } catch (\TypeError) {
            // Field value is not a valid page reference (e.g. structure field mistaken for pages field)
            return new WebPageLinks();
        }
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $isRequired
     * @return Pages|null
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsPages(Page $page, string $fieldName, bool $isRequired = false): Pages|null
    {
        return $this->fieldReader->getPageFieldAsPages($page, $fieldName, $isRequired);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param bool $isRequired
     * @return Page|null
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsFirstPage(Page $page, string $fieldName, bool $isRequired = false): Page|null
    {
        return $this->fieldReader->getPageFieldAsFirstPage($page, $fieldName, $isRequired);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    public function getPageFieldAsPageTitle(Page $page, string $fieldName): string
    {
        return $this->fieldReader->getPageFieldAsPageTitle($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return array
     * @throws KirbyRetrievalException
     */
    public function getPageFieldAsPageTitles(Page $page, string $fieldName): array
    {
        return $this->fieldReader->getPageFieldAsPageTitles($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getPageFieldAsPageUrl(Page $page, string $fieldName): string
    {
        return $this->fieldReader->getPageFieldAsPageUrl($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsPageId(Page $page, string $fieldName): string
    {
        return $this->fieldReader->getPageFieldAsPageId($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsPageSlug(Page $page, string $fieldName): string
    {
        return $this->fieldReader->getPageFieldAsPageSlug($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return User
     * @throws KirbyRetrievalException
     */
    protected function getPageFieldAsUser(Page $page, string $fieldName): User
    {
        $field = $this->getPageField($page, $fieldName);
        /** @noinspection PhpUndefinedMethodInspection */
        return $this->getUser($field->toUser());
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param string $title
     * @return Document
     */
    protected function getPageFieldAsDocument(Page $page, string $fieldName, string $title = 'Download'): Document
    {
        try {
            $pageFile = $this->getPageFieldAsFile($page, $fieldName);
            if ($pageFile != null) {
                return $this->getDocumentFromFile($pageFile, $title);
            }
            return (new Document(''))->recordError('Document not found');
        } catch (KirbyRetrievalException) {
            return (new Document(''))->recordError('Document not found');
        }
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param string $title
     * @return Documents
     * @noinspection PhpUnused
     */
    protected function getPageFieldAsDocuments(Page $page, string $fieldName, string $title = 'Download'): Documents
    {
        $documents = new Documents();
        try {
            $pageFiles = $this->getPageFieldAsFiles($page, $fieldName);
            if ($pageFiles != null) {
                foreach ($pageFiles as $pageFile) {
                    $documents->addListItem($this->getDocumentFromFile($pageFile, $title));
                }
                return $documents;
            }
            return (new Documents())->recordError('Documents not found');
        } catch (KirbyRetrievalException) {
            return (new Documents())->recordError('Documents not found');
        }
    }

    /**
     * @param File $pageFile
     * @param string $title
     * @return Document
     */
    private function getDocumentFromFile(File $pageFile, string $title = 'Download'): Document
    {
        return $this->imageService->getDocumentFromFile($pageFile, $title);
    }

    /**
     * @param File $file
     * @return DateTime
     * @noinspection PhpUnused
     */
    protected function getFileModifiedAsDateTime(File $file): DateTime
    {
        return $this->fieldReader->getFileModifiedAsDateTime($file);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return bool
     */
    public function isPageFieldNotEmpty(Page $page, string $fieldName): bool
    {
        return $this->fieldReader->isPageFieldNotEmpty($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return string ('page', or 'url' - blank string if neither page or url)
     * @throws KirbyRetrievalException
     */
    protected function getLinkFieldType(Page $page, string $fieldName): string
    {
        return $this->fieldReader->getLinkFieldType($page, $fieldName);
    }


    /**
     * @param Page $page
     * @param string $fieldName
     * @return Field
     * @throws KirbyRetrievalException
     */
    public function getPageField(Page $page, string $fieldName): Field
    {
        return $this->fieldReader->getPageField($page, $fieldName);
    }

    /**
     * Retrieves the type of the specified field from the page's content.
     * @param Page $page The page from which the field's type is to be retrieved.
     * @param string $fieldName The name of the field whose type is to be retrieved.
     * @return string The type of the field as a string.
     * @throws KirbyRetrievalException if the field does not exist.
     */
    protected function getPageFieldType(Page $page, string $fieldName): string
    {
        return $this->fieldReader->getPageFieldType($page, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return string
     * @noinspection PhpUnused
     */
    protected function getUserNames(Page $page, string $fieldName): string
    {
        return $this->fieldReader->getUserNames($page, $fieldName);
    }

    /**
     * Returns a comma-separated string of display names for users stored in a Kirby users field.
     *
     * Returns 'Unknown' if the field is empty, or an empty string if an error occurs.
     *
     * @param Page $page
     * @param string $fieldName
     * @return string
     */
    public function getPageFieldAsUserNames(Page $page, string $fieldName): string
    {
        return $this->fieldReader->getUserNames($page, $fieldName);
    }

    /**
     * Returns a UserList of full poster details (name, email, job title) for the
     * users stored in the given field, preserving their stored order.
     *
     * Returns an empty UserList if the field is empty or on any error.
     *
     * Each built User model is passed through {@see decoratePosterUser()}, which
     * consuming sites may override to add site-specific data (e.g. a linked
     * profile/person page).
     *
     * @param Page $page The page holding the users field.
     * @param string $fieldName The name of the users field (e.g. 'postedBy').
     * @return UserList
     */
    public function getPageFieldAsUsers(Page $page, string $fieldName): UserList
    {
        return $this->fieldReader->getUsers(
            $page,
            $fieldName,
            fn (User $userModel, \Kirby\Cms\User $kirbyUser) => $this->decoratePosterUser($userModel, $kirbyUser)
        );
    }

    /**
     * Hook for consuming sites to enrich a poster's User model with site-specific
     * data (for example a linked person/profile page that overrides the job title
     * and supplies a profile URL). No-op by default.
     *
     * @param User $userModel The model built from the Kirby user.
     * @param \Kirby\Cms\User $kirbyUser The underlying Kirby user.
     * @return void
     */
    protected function decoratePosterUser(User $userModel, \Kirby\Cms\User $kirbyUser): void
    {
    }

    /**
     * Returns the "posted by" display string for a publication page, honouring the
     * site-level `postedBySource` config option.
     *
     * With `postedBySource` = `contributors`, the names are read from the page's
     * contributor-selection field when it holds a selection; otherwise — including
     * for legacy pages credited before contributors existed — they fall back to the
     * Kirby users field. With the default `users` source the users field is always
     * used.
     *
     * @param Page $page The publication page.
     * @param string $usersField The Kirby users field name (default 'postedBy').
     * @param string $contributorsField The contributor-selection field name (default 'postedByContributors').
     * @return string
     */
    public function getPostedByNames(
        Page $page,
        string $usersField = 'postedBy',
        string $contributorsField = 'postedByContributors'
    ): string {
        if ($this->usesContributorsForPostedBy() && $this->isPageFieldNotEmpty($page, $contributorsField)) {
            return $this->fieldReader->getContributorNames($page, $contributorsField);
        }
        return $this->fieldReader->getUserNames($page, $usersField);
    }

    /**
     * Returns a UserList of posters for a publication page, honouring the
     * site-level `postedBySource` config option.
     *
     * Rich posters — those carrying an email and job title for public display —
     * are only ever sourced from contributors. With `postedBySource` =
     * `contributors`, posters are built from the page's contributor selection
     * (resolved against the site `contributors` structure) when it holds one. When
     * it does not — including legacy pages credited via the Kirby users field — an
     * empty list is returned so consumers fall back to the plain {@see getPostedByNames()}
     * string: Kirby-user emails and job titles are deliberately not published in
     * contributors mode, only curated contributor details are. With the default
     * `users` source the users field is always used and decorated via
     * {@see decoratePosterUser()}.
     *
     * @param Page $page The publication page.
     * @param string $usersField The Kirby users field name (default 'postedBy').
     * @param string $contributorsField The contributor-selection field name (default 'postedByContributors').
     * @return UserList
     * @throws KirbyRetrievalException
     */
    public function getPostedByUsers(
        Page $page,
        string $usersField = 'postedBy',
        string $contributorsField = 'postedByContributors'
    ): UserList {
        if ($this->usesContributorsForPostedBy()) {
            if (!$this->isPageFieldNotEmpty($page, $contributorsField)) {
                // No contributor selection (e.g. a legacy page credited via the
                // Kirby users field): no rich posters, so the plain name is shown.
                return new UserList();
            }
            // The master contributors list may not exist yet (no rows entered);
            // fall back to an empty structure so pages still build. Selected names
            // then resolve to name-only posters.
            try {
                $contributors = $this->getSiteFieldAsStructure('contributors');
            } catch (KirbyRetrievalException) {
                $contributors = new Structure();
            }
            return $this->fieldReader->getContributors($page, $contributorsField, $contributors);
        }
        return $this->getPageFieldAsUsers($page, $usersField);
    }

    /**
     * Whether the site is configured to source "posted by" credits from the
     * contributors structure rather than from Kirby users.
     *
     * @return bool
     */
    private function usesContributorsForPostedBy(): bool
    {
        return $this->kirby->option('postedBySource', 'users') === 'contributors';
    }



    #endregion

    #region SITE_FIELDS

    /**
     * @param string $fieldName
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getSiteFieldAsString(string $fieldName, bool $required = false): string
    {
        return $this->fieldReader->getSiteFieldAsString($fieldName, $required);
    }

    /**
     * @param string $fieldName
     * @param bool $required
     * @param bool $default
     * @return bool
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getSiteFieldAsBool(string $fieldName, bool $required = false, bool $default = false): bool
    {
        return $this->fieldReader->getSiteFieldAsBool($fieldName, $required, $default);
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
        return $this->fieldReader->getSiteFieldAsInt($fieldName, $required, $default);
    }

    /**
     * @param string $fieldName
     * @return Structure
     * @throws KirbyRetrievalException
     */
    protected function getSiteFieldAsStructure(string $fieldName): Structure
    {
        return $this->fieldReader->getSiteFieldAsStructure($fieldName);
    }

    /**
     * @param string $fieldName
     * @return Page
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getSiteFieldAsPage(string $fieldName): Page
    {
        return $this->fieldReader->getSiteFieldAsPage($fieldName);
    }

    /**
     * @param string $fieldName
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getSiteFieldAsKirbyText(string $fieldName): string
    {
        return $this->fieldReader->getSiteFieldAsKirbyText($fieldName);
    }

    /**
     * Returns the rendered HTML of a site blocks field, or an empty string if
     * the field is empty or missing.
     *
     * @param string $fieldName
     * @return string
     */
    protected function getSiteFieldAsBlocksHtml(string $fieldName): string
    {
        return $this->fieldReader->getSiteFieldAsBlocksHtml($fieldName);
    }

    /**
     * @param string $fieldName
     * @return File
     * @throws KirbyRetrievalException
     */
    protected function getSiteFieldAsFile(string $fieldName): File
    {
        return $this->fieldReader->getSiteFieldAsFile($fieldName);
    }

    /**
     * @param string $fieldName
     * @param bool $required
     * @return array
     * @throws KirbyRetrievalException
     */
    protected function getSiteFieldAsArray(string $fieldName, bool $required = false): array
    {
        return $this->fieldReader->getSiteFieldAsArray($fieldName, $required);
    }

    /**
     * @param string $fieldName
     * @param bool $required
     * @return array
     * @throws KirbyRetrievalException
     */
    protected function getSiteStructureFieldAsArray(string $fieldName, bool $required = false): array
    {
        return $this->fieldReader->getSiteStructureFieldAsArray($fieldName, $required);
    }


    /**
     * if not links found, will return empty WebPageLinks
     * @param string $fieldName
     * @return WebPageLinks
     */
    public function getSiteFieldAsWebPageLinks(string $fieldName): WebPageLinks
    {
        try {
            $siteField = $this->getSiteField($fieldName);
            /** @noinspection PhpUndefinedMethodInspection */
            $pages = $siteField->toPages();
            return $this->getWebPageLinks($pages, true);
        }
        catch (KirbyRetrievalException $e) {
            return new WebPageLinks();
        }
    }

    /**
     * @param string $fieldName
     * @return Field
     * @throws KirbyRetrievalException
     */
    protected function getSiteField(string $fieldName): Field
    {
        return $this->fieldReader->getSiteField($fieldName);
    }

    /**
     * @param string $fieldName
     * @return bool
     */
    protected function isSiteFieldNotEmpty(string $fieldName): bool
    {
        return $this->fieldReader->isSiteFieldNotEmpty($fieldName);
    }

    #endregion

    #region STRUCTURE_FIELDS

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     */
    public function getStructureFieldAsString(StructureObject $structure,
                                              string          $fieldName,
                                              bool            $required = false): string
    {
        return $this->fieldReader->getStructureFieldAsString($structure, $fieldName, $required);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @param bool $required
     * @param bool $default
     * @return bool
     * @throws KirbyRetrievalException
     */
    protected function getStructureFieldAsBool(StructureObject $structure,
                                               string          $fieldName,
                                               bool            $required = false,
                                               bool            $default = false): bool
    {
        return $this->fieldReader->getStructureFieldAsBool($structure, $fieldName, $required, $default);
    }

    /**
     * NOTE: will return 0 if required and field not found/empty
     * @param StructureObject $structure
     * @param string $fieldName
     * @param bool $required
     * @param int $default
     * @return int
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getStructureFieldAsInt(StructureObject $structure,
                                              string          $fieldName,
                                              bool            $required = false,
                                              int             $default = 0): int
    {
        return $this->fieldReader->getStructureFieldAsInt($structure, $fieldName, $required, $default);
    }

    /**
     *  NOTE: will return 0 if required and field not found/empty
     * @param StructureObject $structure
     * @param string $fieldName
     * @param bool $required
     * @param float $default
     * @return float
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getStructureFieldAsFloat(StructureObject $structure,
                                                string          $fieldName,
                                                bool            $required = false,
                                                float           $default = 0): float
    {
        return $this->fieldReader->getStructureFieldAsFloat($structure, $fieldName, $required, $default);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getStructureFieldAsKirbyText(StructureObject $structure,
                                                    string          $fieldName,
                                                    bool            $required = false): string
    {
        return $this->fieldReader->getStructureFieldAsKirbyText($structure, $fieldName, $required);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getStructureFieldAsLinkUrl(StructureObject $structure, string $fieldName): string
    {
        return $this->fieldReader->getStructureFieldAsLinkUrl($structure, $fieldName);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @return Page
     * @throws KirbyRetrievalException
     */
    protected function getStructureFieldAsPage(StructureObject $structure, string $fieldName): Page
    {
        return $this->fieldReader->getStructureFieldAsPage($structure, $fieldName);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @return File
     * @throws KirbyRetrievalException
     */
    protected function getStructureFieldAsFile(StructureObject $structure, string $fieldName): File
    {
        return $this->fieldReader->getStructureFieldAsFile($structure, $fieldName);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @return Files
     * @throws KirbyRetrievalException
     */
    protected function getStructureFieldAsFiles(StructureObject $structure, string $fieldName): Files
    {
        return $this->fieldReader->getStructureFieldAsFiles($structure, $fieldName);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getStructureFieldAsPageTitle(StructureObject $structure, string $fieldName): string
    {
        return $this->fieldReader->getStructureFieldAsPageTitle($structure, $fieldName);
    }

    /**
     * @param Structure $structure
     * @param bool $required
     * @return array
     * @throws KirbyRetrievalException
     */
    protected function getStructureAsArray(Structure $structure, bool $required = false): array
    {
        return $this->fieldReader->getStructureAsArray($structure, $required);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getStructureFieldAsPageUrl(StructureObject $structure, string $fieldName): string
    {
        return $this->fieldReader->getStructureFieldAsPageUrl($structure, $fieldName);
    }

    /**
     * Retrieves a field from a structure object and converts it to a URL if possible.
     * If the field's link object is empty, an empty string is returned.
     *
     * @param StructureObject $structure The structure object containing the field.
     * @param string $fieldName The name of the field to retrieve.
     * @return WebPageLink
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getStructureFieldAsWebPageLink(StructureObject $structure, string $fieldName): WebPageLink
    {
        return $this->fieldReader->getStructureFieldAsWebPageLink($structure, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param ImageType $imageType (optional, if image is found)
     * @param string $pageFieldName the structure field holding the linked page
     * @param ImageSizes $imageSizes responsive `sizes` hint for any card image,
     *                               so the browser picks a correctly-sized srcset
     *                               candidate for the layout (e.g. THIRD_LARGE_SCREEN
     *                               for a 3-up card row)
     * @return WebPageLinks
     * @noinspection PhpUnused
     */
    protected function getStructureAsWebPageLinks(Page       $page,
                                                  string     $fieldName = 'related',
                                                  ImageType  $imageType = ImageType::SQUARE,
                                                  string     $pageFieldName = 'page',
                                                  ImageSizes $imageSizes = ImageSizes::HALF_LARGE_SCREEN): WebPageLinks
    {
        $webPageLinks = new WebPageLinks();
        try {
            $webPageLinksStructure = $this->getPageFieldAsStructure($page, $fieldName);

            foreach ($webPageLinksStructure as $item) {
                $itemTitle = $this->getStructureFieldAsString($item, 'title');
                if ($this->hasStructureField($item, $pageFieldName)) {
                    $page = $this->getStructureFieldAsPage($item, $pageFieldName);
                    $itemTitle = empty($itemTitle) ? $page->title()->value() : $itemTitle;
                    $description = $this->getStructureFieldAsString($item, 'description');
                    $webPageLink = $this->getWebPageLink($page, true, $itemTitle, $description);
                    if ($this->hasStructureField($item, 'image')) {
                        $image = $this->getImageFromStructureField(
                            $item,
                            'image',
                            400,
                            300,
                            80,
                            $imageType,
                            '',
                            $imageSizes
                        );
                        $webPageLink->setImage($image);
                    }
                    $webPageLinks->addListItem($webPageLink);
                } else {
                    if (!empty($itemTitle)) {
                        $webPageLink = new WebPageLink(
                            $this->getStructureFieldAsString($item, 'title'),
                            $this->getStructureFieldAsLinkUrl($item, 'url'),
                            '',
                            ''
                        );
                        $webPageLinks->addListItem($webPageLink);
                    }
                }
            }
        } catch (KirbyRetrievalException $e) {
            $webPageLinks->setStatus(false);
            $webPageLinks->addErrorMessage($e->getMessage());
            $webPageLinks->addFriendlyMessage('No web page links found');
        }
        return $webPageLinks;
    }

    /**
     * @param StructureObject $structureObject
     * @param string $fieldName
     * @param bool $required
     * @param int $excerpt
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getStructureFieldAsBlocksHtml(StructureObject $structureObject,
                                                     string          $fieldName,
                                                     bool            $required = false,
                                                     int             $excerpt = 0): string
    {
        return $this->fieldReader->getStructureFieldAsBlocksHtml($structureObject, $fieldName, $required, $excerpt);
    }

    /**
     * If structure has title field, will use that for the document title
     * @param Page $page
     * @param string $fieldName
     * @return Documents
     * @noinspection PhpUnused
     */
    protected function getStructureAsDocuments(Page $page, string $fieldName): Documents
    {
        $documents = new Documents();
        try {
            $documentsStructure = $this->getPageFieldAsStructure($page, $fieldName);

            foreach ($documentsStructure as $item) {
                $docTitle = $this->getStructureFieldAsString($item, 'title');
                if ($this->hasStructureField($item, 'upload')) {
                    $file = $this->getStructureFieldAsFile($item, 'upload');
                    /** @noinspection PhpUndefinedMethodInspection */
                    $docTitle = empty($docTitle) ? $file->filename() : $docTitle;
                    $document = new Document($docTitle, $file->url());
                    $documents->addListItem($document);
                }
            }
        } catch (KirbyRetrievalException $e) {
            $documents->setStatus(false);
            $documents->addErrorMessage($e->getMessage());
            $documents->addFriendlyMessage('No documents found');
        }
        return $documents;
    }


    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @return Field
     * @throws KirbyRetrievalException
     */
    protected function getStructureField(StructureObject $structure, string $fieldName): Field
    {
        return $this->fieldReader->getStructureField($structure, $fieldName);
    }

    /**
     * @param StructureObject $structure
     * @param string $fieldName
     * @return bool
     */
    protected function hasStructureField(StructureObject $structure, string $fieldName): bool
    {
        return $this->fieldReader->hasStructureField($structure, $fieldName);
    }

    #endregion

    #region ENTRIES_FIELDS

    /**
     * @param Page $page
     * @param string $fieldName
     * @return string []
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getEntriesFieldAsStringArray(Page $page, string $fieldName): array
    {
        return $this->fieldReader->getEntriesFieldAsStringArray($page, $fieldName);
    }

    #endregion

    #region BLOCK_FIELDS

    /**
     * @param Block $block
     * @param string $fieldName
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getBlockFieldAsString(Block $block, string $fieldName, bool $required = false): string
    {
        return $this->fieldReader->getBlockFieldAsString($block, $fieldName, $required);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @param bool $required
     * @param array $default
     * @return array
     * @throws KirbyRetrievalException
     */
    protected function getBlockFieldAsYaml(Block $block, string $fieldName, bool $required = false, array $default = []): array
    {
        return $this->fieldReader->getBlockFieldAsYaml($block, $fieldName, $required, $default);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @return Structure
     * @throws KirbyRetrievalException
     */
    protected function getBlockFieldAsStructure(Block $block, string $fieldName): Structure
    {
        return $this->fieldReader->getBlockFieldAsStructure($block, $fieldName);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @param bool $required
     * @return int
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getBlockFieldAsInt(Block $block, string $fieldName, bool $required = false): int
    {
        return $this->fieldReader->getBlockFieldAsInt($block, $fieldName, $required);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @param int $width
     * @param int|null $height
     * @param ImageType $imageType
     * @param bool $fixedWidth
     * @return Image
     * @noinspection PhpUnused
     */
    protected function getBlockFieldAsImage(Block     $block,
                                            string    $fieldName,
                                            int       $width,
                                            ?int      $height,
                                            ImageType $imageType = ImageType::SQUARE,
                                            bool      $fixedWidth = false): Image
    {
        return $this->imageService->getBlockFieldAsImage($block, $fieldName, $width, $height, $imageType, $fixedWidth);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @param bool $required
     * @return Blocks
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getBlockFieldAsBlocks(Block $block, string $fieldName, bool $required = false): Blocks
    {
        return $this->fieldReader->getBlockFieldAsBlocks($block, $fieldName, $required);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @param bool $required
     * @return string
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getBlockFieldAsBlocksHtml(Block $block, string $fieldName, bool $required = false): string
    {
        return $this->fieldReader->getBlockFieldAsBlocksHtml($block, $fieldName, $required);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @return bool
     */
    protected function isBlockFieldNotEmpty(Block $block, string $fieldName): bool
    {
        return $this->fieldReader->isBlockFieldNotEmpty($block, $fieldName);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @return Field
     * @throws KirbyRetrievalException
     */
    protected function getBlockField(Block $block, string $fieldName): Field
    {
        return $this->fieldReader->getBlockField($block, $fieldName);
    }

    /**
     * @param Block $block
     * @param string $fieldName
     * @return File|null
     * @throws KirbyRetrievalException
     */
    protected function getBlockFieldAsFile(Block $block, string $fieldName): File|null
    {
        return $this->fieldReader->getBlockFieldAsFile($block, $fieldName);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @return WebPageBlocks
     */
    protected function getContentBlocks(Page $page, string $fieldName = 'mainContent'): WebPageBlocks
    {
        try {
            $pageBlocks = $this->getPageFieldAsBlocks($page, $fieldName);
            $contentBlocks = new WebPageBlocks();
            $headingNumber = 0;
            foreach ($pageBlocks as $pageBlock) {
                if ($pageBlock instanceof Block) {
                    $block = new WebPageBlock($pageBlock->type(), $this->getHTMLfromBlock($pageBlock));
                    if ($pageBlock->type() === 'heading') {
                        $block->setBlockLevel($pageBlock->content()->get('level')->toString());
                        $headingNumber++;
                        $anchor = ($this->isBlockFieldNotEmpty($pageBlock, 'anchor'))
                            ? $this->getBlockFieldAsString($pageBlock, 'anchor')
                            : 'heading' . $headingNumber;
                        $block->setAnchor($anchor);
                    }
                    if ($pageBlock->type() === 'faq') {
                        $faqTitle = $this->getBlockFieldAsString($pageBlock, 'title');
                        $block->setAnchor($faqTitle);
                    }
                    $contentBlocks->addListItem($block);
                }
            }
            return $contentBlocks;
        } catch (KirbyRetrievalException $e) {
            return (new WebPageBlocks())->recordError(
                $e->getMessage(),
                'An error occurred while retrieving the page block'
            );
        }
    }

    /**
     * Returns HTML, having converted @page permalinks to urls
     * @param Block $block
     * @return string
     */
    private function getHTMLfromBlock(Block $block): string
    {
        return $this->fieldReader->getHTMLfromBlock($block);
    }


    #endregion

    #region USER_FIELDS

    protected function isUserFieldNotEmpty(\Kirby\Cms\User $user, string $fieldName): bool
    {
        return $this->fieldReader->isUserFieldNotEmpty($user, $fieldName);
    }


    /**
     * @param \Kirby\Cms\User $user
     * @param string $fieldName
     * @param string $default
     * @return string
     */
    protected function getUserFieldAsString(\Kirby\Cms\User $user, string $fieldName, string $default = ''): string
    {
        return $this->fieldReader->getUserFieldAsString($user, $fieldName, $default);
    }

    /**
     * @param string $fieldName
     * @return string
     */
    public function getCurrentUserFieldAsString(string $fieldName): string
    {
        return $this->fieldReader->getCurrentUserFieldAsString($fieldName);
    }

    protected function getUserFieldAsBool(\Kirby\Cms\User $user, string $fieldName, bool $default = false): bool
    {
        return $this->fieldReader->getUserFieldAsBool($user, $fieldName, $default);
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $fieldName
     * @return Structure
     */
    protected function getUserFieldAsStructure(\Kirby\Cms\User $user, string $fieldName): Structure
    {
        return $this->fieldReader->getUserFieldAsStructure($user, $fieldName);
    }

    /**
     * @param string $fieldName
     * @return Structure
     */
    public function getCurrentUserFieldAsStructure(string $fieldName): Structure
    {
        return $this->fieldReader->getCurrentUserFieldAsStructure($fieldName);
    }

    /**
     * @param string $fieldName
     * @return bool
     */
    public function getCurrentUserFieldAsBool(string $fieldName): bool
    {
        return $this->fieldReader->getCurrentUserFieldAsBool($fieldName);
    }

    protected function getCurrentUserFieldAsUser(string $fieldName): User
    {
        $user = kirby()->user();
        $kirbyUser = $user->{$fieldName}()->toUser();
        if ($kirbyUser) {
            return $this->getUser($kirbyUser);
        }
        return (new User('not found'))->recordError('User not found');
    }

    protected function getUserFieldAsUser(\Kirby\Cms\User $user, string $fieldName): User
    {
        $kirbyUser = $user->{$fieldName}()->toUser();
        if ($kirbyUser) {
            return $this->getUser($kirbyUser);
        }
        return (new User('not found'))->recordError('User not found')->setUserName('User not found');
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $fieldName
     * @return string
     */
    protected function getUserFieldAsUserNames(\Kirby\Cms\User $user, string $fieldName): string
    {
        $userField = $user->{$fieldName}()->value();
        $userNames = '';
        $userNamesAsArray = explode("\n", (string) $userField);
        foreach ($userNamesAsArray as $userName) {
            $userNames .= $this->getUserName($userName) . ', ';
        }
        return trim($userNames, ', ');
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $fieldName
     * @param string $default
     * @return string
     */
    protected function getUserFieldAsSlug(\Kirby\Cms\User $user, string $fieldName, string $default = ''): string
    {
        return $this->fieldReader->getUserFieldAsSlug($user, $fieldName, $default);
    }

    /**
     * @param string $fieldName
     * @param string $default
     * @return string
     */
    protected function getCurrentUserFieldAsSlug(string $fieldName, string $default = ''): string
    {
        return $this->fieldReader->getCurrentUserFieldAsSlug($fieldName, $default);
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $fieldName
     * @return Pages|null
     */
    protected function getUserFieldAsPages(\Kirby\Cms\User $user, string $fieldName): Pages|null
    {
        return $user->{$fieldName}()->toPages();
    }

    /**
     * @param string $fieldName
     * @return Pages|null
     */
    protected function getCurrentUserFieldAsPages(string $fieldName): Pages|null
    {
        return $this->getUserFieldAsPages(kirby()->user(), $fieldName);
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $fieldName
     * @param bool $simpleLinks
     * @param bool $required
     * @return WebPageLinks
     * @throws KirbyRetrievalException
     */
    protected function getUserFieldAsWebPageLinks(\Kirby\Cms\User $user, string $fieldName, bool $simpleLinks = true, bool $required = false): WebPageLinks
    {
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $pages = $user->{$fieldName}()->toPages();
            return $this->getWebPageLinks($pages, $simpleLinks);
        } catch (KirbyRetrievalException $e) {
            if ($required) {
                throw $e;
            }
            return new WebPageLinks();
        }
    }

    /**
     * @param string $fieldName
     * @param bool $simpleLinks
     * @param bool $required
     * @return WebPageLinks
     * @throws KirbyRetrievalException
     */
    protected function getCurrentUserFieldAsWebPageLinks(string $fieldName, bool $simpleLinks = true, bool $required = false): WebPageLinks
    {
        return $this->getUserFieldAsWebPageLinks(kirby()->user(), $fieldName, $simpleLinks, $required);
    }

#endregion

#region USERS

    /**
     * @param string $userId
     * @param string $fallback
     * @noinspection PhpUnused
     * @return string
     */
    protected function getUserName(string $userId, string $fallback = 'User not found'): string
    {
        return $this->userService->getUserName($userId, $fallback);
    }

    protected function getCurrentKirbyUser(): \Kirby\Cms\User
    {
        return $this->userService->getCurrentKirbyUser();
    }

    /**
     * @return User
     */
    protected function getCurrentUser(): User
    {
        return $this->userService->getCurrentUser();
    }

    /**
     * @param \Kirby\Cms\User $kirbyUser
     * @return User
     */
    protected function getUser(\Kirby\Cms\User $kirbyUser): User
    {
        return $this->userService->getUser($kirbyUser);
    }

    /**
     * gets the current Kirby username - returns blank string if no user
     * @return string
     * @noinspection PhpUnused
     */
    protected function getCurrentUserName(): string
    {
        return $this->userService->getCurrentUserName();
    }

    /**
     * gets the current Kirby user role - returns blank string if no user
     * @return string
     * @noinspection PhpUnused
     */
    protected function getCurrentUserRole(): string
    {
        return $this->userService->getCurrentUserRole();
    }


    /**
     * @param Page $currentPage
     * @return bool
     * @throws KirbyRetrievalException
     */
    protected function checkPagePermissions(Page $currentPage): bool
    {
        return $this->userService->checkPagePermissions($currentPage);
    }

    /**
     * Checks if a Kirby session cookie exists.
     * Use this before calling kirby()->user() to avoid starting a session
     * which would prevent page caching.
     *
     * @return bool
     */
    protected function hasSessionCookie(): bool
    {
        $cookieName = $this->kirby->option('session')['cookieName'] ?? 'kirby_session';
        return isset($_COOKIE[$cookieName]);
    }

    public function isCurrentUserAdminOrEditor(): bool
    {
        return $this->userService->isCurrentUserAdminOrEditor();
    }

    /**
     * Whether the signed-in user is an administrator.
     *
     * The set already offered isCurrentUserAdminOrEditor() and
     * isCurrentUserAdminOrEditorOrHasRoles() but no plain admin check, so a consumer
     * wanting an admin-only route had to reach for the protected
     * getCurrentUserRole() and compare strings. This closes that gap.
     *
     * @return bool True when an administrator is signed in
     */
    public function isCurrentUserAdmin(): bool
    {
        return $this->userService->isCurrentUserAdmin();
    }

    /**
     * @param \Kirby\Cms\User|null $user The user to check, or null
     * @return bool True when the user is a real administrator
     */
    public function isUserAdmin(\Kirby\Cms\User|null $user): bool
    {
        return $this->userService->isUserAdmin($user);
    }

    public function isCurrentUserAdminOrEditorOrHasRoles(array $roles): bool
    {
        return $this->userService->isCurrentUserAdminOrEditorOrHasRoles($roles);
    }

    public function isUserAdminOrEditor(\Kirby\Cms\User|null $user): bool
    {
        return $this->userService->isUserAdminOrEditor($user);
    }

    /**
     * @param string $role
     * @return bool
     */
    public function doesCurrentUserHaveRole(string $role): bool
    {
        return $this->userService->doesCurrentUserHaveRole($role);
    }

    /**
     * @return bool
     * @noinspection PhpUnused
     */
    /**
     * Whether anyone is signed in.
     *
     * Public because routes need it: a route has no $this to inherit from, so a
     * protected accessor left them reaching for a bare kirby()->user(), which is
     * exactly what the helper exists to avoid.
     *
     * @return bool True when a user is signed in
     */
    public function isUserLoggedIn(): bool
    {
        return $this->userService->isUserLoggedIn();
    }

    /**
     * @return LoginDetails
     * @noinspection PhpUnused
     */
    protected function getLoginDetails(): LoginDetails
    {
        $loginDetails = new LoginDetails();
        $loginDetails->setRedirectPage(get('redirectPage', ''));
        // handle the form submission
        if ($this->kirby->request()->is('POST') && get('userName')) {
            $loginDetails->setHasBeenProcessed(true);
            // try to log the user in with the provided credentials
            try {
                // validate CSRF token
                if (csrf(get('csrf')) === true) {
                    $userName = trim((string) get('userName'));
                    $userName = str_replace(' ', '-', $userName);
                    if (!str_contains($userName, '@') && $loginDomain = option('useLoginDomain')) {
                        $userName .= '@' . $loginDomain;
                    }
                    $loginDetails->setUserName($userName);
                    $this->kirby->auth()->login($userName, trim((string) get('password')), true);
                    $loginDetails->setLoginStatus(true);
                    $loginDetails->setLoginMessage('You have successfully logged in');
                    if ($loginDetails->hasRedirectPage()) {
                        go($loginDetails->getRedirectPage());
                    }
                    $this->redirectToHome();
                } else {
                    $loginDetails->setLoginStatus(false);
                    $loginDetails->setLoginMessage('Your security token has expired - please login again.');
                }
            } catch (Exception) {
                $loginDetails->setLoginStatus(false);
                $loginDetails->setLoginMessage('Login failed. Please check your username and password.');
            }
        }

        $loginDetails->setCSRFToken($this->getCSFRToken());

        return $loginDetails;
    }

    /**
     * @param array $userData
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function createUser(array $userData): \Kirby\Cms\User
    {
        return $this->userService->createUser($userData);
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param array $updateData
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function updateUser(\Kirby\Cms\User $user, array $updateData): \Kirby\Cms\User
    {
        return $this->userService->updateUser($user, $updateData);
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $name
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function changeUserName(\Kirby\Cms\User $user, string $name): \Kirby\Cms\User
    {
        return $this->userService->changeUserName($user, $name);
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $role
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function changeUserRole(\Kirby\Cms\User $user, string $role): \Kirby\Cms\User
    {
        return $this->userService->changeUserRole($user, $role);
    }

    /**
     * @param \Kirby\Cms\User $user
     * @param string $email
     * @return \Kirby\Cms\User
     * @throws KirbyRetrievalException
     */
    public function changeUserEmail(\Kirby\Cms\User $user, string $email): \Kirby\Cms\User
    {
        return $this->userService->changeUserEmail($user, $email);
    }


    /**
     * @return string
     */
    protected function getCSFRToken(): string
    {
        return csrf();
    }


    #endregion

    #region FILES

    protected function getFileURL(File $file): string
    {
        return $this->imageService->getFileURL($file);
    }

    protected function isFileFieldNotEmtpy(File $file, string $fieldName): bool
    {
        return $this->imageService->isFileFieldNotEmpty($file, $fieldName);
    }

    protected function getFileFieldAsString(File $file, string $fieldName): string
    {
        return $this->imageService->getFileFieldAsString($file, $fieldName);
    }

    #endregion


    #region MENU PAGES/NAVIGATION


    /**
     * @return WebPageLinks
     * @throws KirbyRetrievalException
     */
    protected function getMenuPages(): WebPageLinks
    {
        /** @var Collection|null $menuPagesCollection */
        $menuPagesCollection = $this->kirby->collection('menuPages');
        return $this->getWebPageLinks($menuPagesCollection, true, true);
    }

    /**
     * @param Page $page
     * @param bool $simpleLink
     * @param array|null $templates
     * @return WebPageLinks
     * @throws KirbyRetrievalException
     */
    /**
     * @param Page $page
     * @param bool $simpleLink
     * @param array|null $templates
     * @param bool $loadGrandchildren Whether to load each sub-page's own children.
     *                                Set false for pages whose sidebar only shows direct children
     *                                (e.g. content-page / sub-pages-menu) to avoid reading
     *                                filesystem directories that are never displayed.
     * @return WebPageLinks
     */
    protected function getSubPages(Page $page, bool $simpleLink = true, array|null $templates = null, bool $loadGrandchildren = true): WebPageLinks
    {
        $subPagesCollection = $templates ?
            $this->getSubPagesUsingTemplates($page, $templates) : $this->getSubPagesAsCollection($page);
        if ($subPagesCollection instanceof Collection) {
            return $this->getWebPageLinks($subPagesCollection, $simpleLink, $loadGrandchildren);
        }
        return new WebPageLinks();
    }

    /**
     * @param bool $simpleLink
     * @param array|null $templates
     * @return WebPageLinks
     * @throws KirbyRetrievalException
     */
    protected function getSiteChildrenAsSubPages(bool $simpleLink, array|null $templates = null): WebPageLinks
    {
        $subPagesCollection = $this->site->children()->listed()->notTemplate($this->getExcludedTemplates());
        if ($templates) {
            $subPagesCollection = $subPagesCollection->template($templates);
        }
        return $this->getWebPageLinks($subPagesCollection, $simpleLink, true);
    }


    /**
     * get the pages below the current page (excluding certain template types, as specified in the
     * subPagesExclude config)
     * @param Page $page
     * @return Pages|Collection|null
     */
    protected function getSubPagesAsCollection(Page $page): Pages|Collection|null
    {
        return $page->children()->listed()->notTemplate($this->getExcludedTemplates());
    }


    /**
     * Retrieves the list of excluded templates from the configuration option subPagesExclude.
     *
     * @return array The array of excluded template names.
     */
    protected function getExcludedTemplates(): array
    {
        $excludedTemplates = option('subPagesExclude');

        // Ensure it returns an array
        if (!is_array($excludedTemplates)) {
            $excludedTemplates = [];
        }
        return $excludedTemplates;
    }

    /**
     * @param Page $page
     * @param string $template
     * @return WebPageLink
     * @noinspection PhpUnused
     */
    protected function getSubPageLink(Page $page, string $template): WebPageLink
    {
        $pagesFromKirby = $this->getSubPagesUsingTemplates($page, [$template]);
        if ($pagesFromKirby->count() > 0) {
            $pageFromKirby = $pagesFromKirby->first();
            if ($pageFromKirby instanceof Page) {
                return new WebPageLink(
                    $pageFromKirby->title()->toString(),
                    $pageFromKirby->url(),
                    $pageFromKirby->id(),
                    $pageFromKirby->template()->name()
                );
            }
        }
        return (new WebPageLink('', '', '', 'error'))->recordError('Page not found');
    }

    /**
     * get the pages below the current page
     * @param Page $page
     * @param string[] $templates
     * @param bool $childrenOnly
     * @return Pages
     */
    protected function getSubPagesUsingTemplates(Page $page, array $templates, bool $childrenOnly = true): Pages
    {
        return $childrenOnly
            ? $page->children()->listed()->template($templates)
            : $page->index()->listed()->template($templates);
    }

    /**
     * Get the pages below a page, without descending into the children of pages whose
     * intended template is listed in $leafTemplates.
     *
     * A cheaper index() for trees where the pages being looked for sit above large,
     * uninteresting subtrees (submissions beneath a form, comments beneath an article).
     * The leaf pages themselves are returned; only their subtrees are skipped, and the
     * depth-first ordering of index() is preserved.
     *
     * @param Page $page The page whose descendants are wanted
     * @param string[] $leafTemplates Template names whose children should be skipped
     * @return Pages The descendant pages, excluding anything below a leaf template
     */
    protected function getIndexExcludingChildrenOf(Page $page, array $leafTemplates): Pages
    {
        return $this->pageTreeService->indexExcludingChildrenOf($page, $leafTemplates);
    }

    /**
     * @param Page $page
     * @param array $templates
     * @return Page|null
     * @noinspection PhpUnused
     */
    protected function getFirstSubPageUsingTemplates(Page $page, array $templates): Page|null
    {
        return $this->getSubPagesUsingTemplates($page, $templates)->first();
    }

    /**
     * @param Page $page
     * @param array $templates
     * @noinspection PhpUnused
     * @return Pages
     */
    protected function getSiblingsUsingTemplates(Page $page, array $templates): Pages
    {
        /** @var Pages $siblings */
        $siblings = $page->siblings(false);
        return $siblings->filterBy('template', 'in', $templates)->listed();
    }

    /**
     * Generates previous and next page navigation details for a given page within a specified collection.
     *
     * @param Page $kirbyPage The current page for which the navigation is being generated.
     * @param string $collectionName The name of the collection from which the navigation should be computed.
     * @return PrevNextPageNavigation Object containing previous and next page navigation data.
     * @noinspection PhpUnused
     */
    protected function getPrevNextNavigation(Page $kirbyPage, string $collectionName): PrevNextPageNavigation
    {
        $navigation = new PrevNextPageNavigation();
        $contentCollection = $this->kirby->collection($collectionName, ['page' => $kirbyPage]);
        $previousPage = $this->page->prev($contentCollection);
        $nextPage = $this->page->next($contentCollection);
        if ($previousPage) {
            $navigation->setPreviousPageLink($previousPage->url());
            $navigation->setPreviousPageTitle($previousPage->title()->value());
        }
        if ($nextPage) {
            $navigation->setNextPageLink($nextPage->url());
            $navigation->setNextPageTitle($nextPage->title()->value());
        }
        return $navigation;
    }

    /**
     * @param BaseWebPage $webPage
     * @return OnThisPageLinks
     * @throws KirbyRetrievalException
     */
    protected function getOnThisPageLinks(BaseWebPage $webPage): OnThisPageLinks
    {
        $onThisPageLinks = new OnThisPageLinks();
        $mainContentBlocks = $webPage->getMainContent();
        $onThisPageLinks = $this->getOnThisPageLinksFromContentBlocks($onThisPageLinks, $mainContentBlocks, 'main');
        if ($webPage->hasLowerContentBlocks()) {
            $onThisPageLinks = $this->getOnThisPageLinksFromContentBlocks($onThisPageLinks, $webPage->getLowerContentBlocks(), 'lower');
        }
        return $onThisPageLinks;
    }

    /**
     * @param OnThisPageLinks $onThisPageLinks
     * @param WebPageBlocks $contentBlocks
     * @param string $linkArea
     * @return OnThisPageLinks
     * @throws KirbyRetrievalException
     */
    private function getOnThisPageLinksFromContentBlocks(OnThisPageLinks $onThisPageLinks, WebPageBlocks $contentBlocks, string $linkArea): OnThisPageLinks
    {

        foreach ($contentBlocks->getListItems() as $contentBlock) {

            if ($contentBlock->getBlockType() === 'heading'
                && (in_array($contentBlock->getBlockLevel(), ['h2', 'h3']))) {
                $linkTitle = strip_tags($contentBlock->getBlockContent());
                $anchorLink = $contentBlock->getAnchor();
                $linkLevel = $contentBlock->getBlockLevel();
                $onThisPageLink = new OnThisPageLink($linkTitle, $anchorLink, $linkLevel, $linkArea);
                $onThisPageLinks->addListItem($onThisPageLink);
            }

            if ($contentBlock->getBlockType() === 'faq') {
                $faqTitle = $contentBlock->getAnchor();
                $faqAnchor = str_replace(' ', '', mb_strtolower($faqTitle));
                $onThisPageLink = new OnThisPageLink($faqTitle, $faqAnchor, 'h2', $linkArea);
                $onThisPageLinks->addListItem($onThisPageLink);
            }
        }
        return $onThisPageLinks;
    }


    #endregion

    #region LINKS

    /**
     * @param string $title
     * @param string $linkType
     * @return CoreLink
     * @noinspection PhpUnused
     */
    protected function findCoreLink(string $title, string $linkType): CoreLink
    {
        return $this->navigationService->findCoreLink($title, $linkType);
    }

    /**
     * @param Page $page
     * @param string $linkType
     * @return CoreLink
     * @noinspection PhpUnused
     */
    protected function getCoreLink(Page $page, string $linkType): CoreLink
    {
        return $this->navigationService->getCoreLink($page, $linkType);
    }

    /**
     * @param Collection $collection
     * @param bool $simpleLink
     * @param bool $getSubPages
     * @param bool $getImages
     * @param ImageSizes $imageSizes The sizes attribute for responsive images
     * @param ImageType $imageType The srcset type for images
     * @return WebPageLinks
     * @throws KirbyRetrievalException
     */
    protected function getWebPageLinks(Collection $collection,
                                       bool       $simpleLink = true,
                                       bool       $getSubPages = false,
                                       bool       $getImages = true,
                                       ImageSizes $imageSizes = ImageSizes::HALF_LARGE_SCREEN,
                                       ImageType  $imageType = ImageType::PANEL): WebPageLinks
    {
        return $this->navigationService->getWebPageLinks($collection, $simpleLink, $getSubPages, $getImages, $imageSizes, $imageType);
    }

    /**
     * @param Page $page
     * @param bool $simpleLink
     * @param string|null $linkTitle
     * @param string|null $linkDescription
     * @param bool $getImages
     * @param ImageSizes $imageSizes The sizes attribute for responsive images
     * @param ImageType $imageType The srcset type for images
     * @return WebPageLink
     * @throws KirbyRetrievalException
     */
    protected function getWebPageLink(Page        $page,
                                      bool        $simpleLink = true,
                                      string|null $linkTitle = null,
                                      string|null $linkDescription = null,
                                      bool        $getImages = true,
                                      ImageSizes  $imageSizes = ImageSizes::HALF_LARGE_SCREEN,
                                      ImageType   $imageType = ImageType::PANEL): WebPageLink
    {
        return $this->navigationService->getWebPageLink($page, $simpleLink, $linkTitle, $linkDescription, $getImages, $imageSizes, $imageType);
    }

    #endregion

    #region MODELS
    /**
     * @template T of BaseModel
     * @param string $pageId
     * @param class-string<T> $modelClass
     * @return T
     */
    public function getSpecificModel(string $pageId, string $modelClass): mixed
    {
        try {
            $kirbyPage = $this->getKirbyPage($pageId);
            if (!(is_a($modelClass, BaseModel::class, true))) {
                throw new KirbyRetrievalException("Page class must extend BaseModel.");
            }


            $model = new $modelClass($kirbyPage->title()->toString(), $kirbyPage->url(), $kirbyPage->template()->name());

            $setModelFunction = 'set' . $this->extractClassName($modelClass);

            if (method_exists($this, $setModelFunction)) {
                $model = $this->$setModelFunction($kirbyPage, $model);
            }
        } catch (KirbyRetrievalException $e) {
            $model = $this->recordModelError($e, $modelClass);
        }
        return $model;
    }

    /**
     * @param string $modelListClass
     * @param BaseFilter|null $filter
     * @param string|null $collection
     * @param array|null $templates
     * @param Page|null $parentPage
     * @param bool $childrenOnly
     * @param string $sortBy (default is no sorting applied)
     * @param string $sortDirection
     * @param string[] $sortableColumns Allowed sort column keys; stored on the list and used to preserve sort state in pagination URLs
     * @return BaseList
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getSpecificModelList(string          $modelListClass = BaseList::class,
                                            BaseFilter|null $filter = null,
                                            string|null     $collection = null,
                                            array|null      $templates = null,
                                            Page|null       $parentPage = null,
                                            bool            $childrenOnly = true,
                                            string          $sortBy = '',
                                            string          $sortDirection = '',
                                            array           $sortableColumns = []
    ): BaseList
    {

        // Ensure $pageClass is a subclass of WebPage
        if (!(is_a($modelListClass, BaseList::class, true))) {
            throw new KirbyRetrievalException("Model list class must extend BaseList.");
        }

        $modelList = new $modelListClass();


        $modelClassName = $modelList->getItemType();
        $modelClass = $modelClassName;
        // Ensure $modelClass is a subclass of BaseModel
        if (!(is_a($modelClass, BaseModel::class, true))) {
            throw new KirbyRetrievalException("Model class must extend BaseModel.");
        }

        $filterClassName = $this->extractClassName($modelList->getFilterType());
        $filterClass = $filterClassName;

        if (isset($templates)) {
            $parentPage = $parentPage ?? $this->page;
            $collectionPages = $this->getSubPagesUsingTemplates($parentPage, $templates, $childrenOnly);
        } else {
            if ($collection === null) {
                $collection = str_replace("List", "", $this->extractClassName($modelListClass));
                $collection = lcfirst($collection);
            }
            $collectionPages = $this->kirby->collection($collection);
            if (!isset($collectionPages)) {
                throw new KirbyRetrievalException('Collection ' . $collection . ' pages not found');
            }
        }

        if (!empty($sortBy)) {
            if (str_contains($sortBy, ',')) {
                $fields = array_map('trim', explode(',', $sortBy));
                $sortArguments = [];
                foreach ($fields as $field) {
                    $sortArguments[] = $field;
                    $sortArguments[] = $sortDirection;
                }
                $collectionPages = $collectionPages->sortBy(...$sortArguments);
            } else {
                $collectionPages = $collectionPages->sortBy($sortBy, $sortDirection);
            }
        }

        if ($filter === null) {
            $setFilterFunction = 'set' . $this->extractClassName($filterClass);
            if (method_exists($this, $setFilterFunction)) {
                $filter = $this->$setFilterFunction();
            }
        }
        if ($filter) {
            $modelList->setFilters($filter);
            $filterFunction = 'filter' . $this->extractClassName($modelListClass);

            if (method_exists($this, $filterFunction)) {
                $collectionPages = $this->$filterFunction($collectionPages, $filter);
            }
        }

        if (!empty($sortableColumns)) {
            $currentUrl = (string) $this->kirby->request()->url();
            $baseUrl = preg_replace('/([?&])(page|sort_by|sort_dir)=[^&]*(&|$)/', '$1', $currentUrl) ?? $currentUrl;
            $baseUrl = preg_replace('/[?&]$/', '', $baseUrl) ?? $baseUrl;

            $modelList->setSortableColumns($sortableColumns)
                      ->setSortBy($sortBy)
                      ->setSortDirection($sortDirection)
                      ->setSortBaseUrl($baseUrl);
        }

        if ($modelList->usePagination() && !$filter->doStopPagination()) {

            // A paginated list reached via POST can only be a filter submission
            // (pagination links are GET), so canonicalise to a fresh GET on page 1
            // via Post/Redirect/Get (issue #559). No-op on GET.
            $this->redirectAfterFilterSubmission();

            $collectionPages = $collectionPages->paginate($modelList->getPaginatePerPage());
            $paginationFromKirby = $collectionPages->pagination();

            if (isset($paginationFromKirby) && $paginationFromKirby->pages() > 0) {
                $pagination = $this->getPagination($paginationFromKirby);

                if (!empty($sortBy)) {
                    $pagination->appendQueryParams('sort_by=' . urlencode($sortBy) . '&sort_dir=' . urlencode($sortDirection));
                }

                $modelList->setPagination($pagination);
            }
        }

        /** @var Page $collectionPage */
        foreach ($collectionPages as $collectionPage) {
            $model = $this->getSpecificModel($collectionPage, $modelClass);
            $modelList->addListItem($model);
        }

        return $modelList;
    }

    /**
     * Build a model list from a content index query instead of loading Kirby pages.
     *
     * This is the fast path: rows are read from SQLite and hydrated directly into
     * model objects via hydrateModelFromIndexRow(), bypassing Kirby's flat-file reads.
     *
     * @param string $modelListClass The BaseList subclass to instantiate
     * @param ContentIndexQuery $query A pre-built query (filters already applied)
     * @param BaseFilter|null $filter Optional filter to attach to the list for description display
     * @return BaseList The populated model list with optional pagination
     * @throws KirbyRetrievalException If the model list class is invalid or hydration fails
     */
    protected function getSpecificModelListFromIndex(
        string            $modelListClass,
        ContentIndexQuery $query,
        ?BaseFilter       $filter = null,
    ): BaseList {
        if (!(is_a($modelListClass, BaseList::class, true))) {
            throw new KirbyRetrievalException("Model list class must extend BaseList.");
        }

        /** @var BaseList $modelList */
        $modelList = new $modelListClass();
        $modelClassName = $modelList->getItemType();

        if ($filter !== null) {
            $modelList->setFilters($filter);
        }

        // Handle pagination if the list supports it
        if ($modelList->usePagination() && ($filter === null || !$filter->doStopPagination())) {
            // A paginated list reached via POST can only be a filter submission
            // (pagination links are GET), so canonicalise to a fresh GET on page 1
            // via Post/Redirect/Get (issue #559). No-op on GET.
            $this->redirectAfterFilterSubmission();

            $totalCount = $query->count();
            $perPage = $modelList->getPaginatePerPage();
            $currentPage = max(1, (int)($this->kirby->request()->get('page', 1)));
            $totalPages = max(1, (int)ceil($totalCount / $perPage));
            $offset = ($currentPage - 1) * $perPage;

            $query->limit($perPage)->offset($offset);

            $pagination = new Pagination();
            $pagination->setCurrentPage($currentPage);
            $pagination->setPageCount($totalPages);
            $pagination->setHasPreviousPage($currentPage > 1);
            $pagination->setHasNextPage($currentPage < $totalPages);

            if ($currentPage > 1) {
                $pagination->setPreviousPageUrl('?page=' . ($currentPage - 1));
            } else {
                $pagination->setPreviousPageUrl('');
            }
            if ($currentPage < $totalPages) {
                $pagination->setNextPageUrl('?page=' . ($currentPage + 1));
            } else {
                $pagination->setNextPageUrl('');
            }

            for ($i = 1; $i <= $totalPages; $i++) {
                $pagination->addPageUrl('?page=' . $i);
            }

            $modelList->setPagination($pagination);
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            $model = $this->hydrateModelFromIndexRow($row, $modelClassName);
            $modelList->addListItem($model);
        }

        return $modelList;
    }

    /**
     * Hydrate a model object from an index row.
     *
     * Override this method in site-specific KirbyHelper to handle specific model types.
     * The default implementation throws an exception.
     *
     * @param array<string, mixed> $row The database row as an associative array
     * @param string $modelClass The fully qualified model class name
     * @return BaseModel The hydrated model instance
     * @throws KirbyRetrievalException Always, unless overridden
     */
    protected function hydrateModelFromIndexRow(array $row, string $modelClass): BaseModel
    {
        throw new KirbyRetrievalException(
            "hydrateModelFromIndexRow() must be overridden to support model class: $modelClass"
        );
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param string $modelListClass
     * @return BaseList
     * @throws KirbyRetrievalException
     */
    protected function getSpecificModelListFromPageField(Page   $page,
                                                         string $fieldName,
                                                         string $modelListClass = BaseList::class): BaseList
    {

        if (!(is_a($modelListClass, BaseList::class, true))) {
            throw new KirbyRetrievalException("Model list class must extend BaseList.");
        }

        $modelList = new $modelListClass();

        $modelClassName = $modelList->getItemType();
        $modelClass = $modelClassName;

        if (!(is_a($modelClass, BaseModel::class, true))) {
            throw new KirbyRetrievalException("Model class must extend BaseModel.");
        }

        $pages = $this->getPageFieldAsPages($page, $fieldName);
        foreach ($pages as $page) {
            $model = $this->getSpecificModel($page, $modelClass);
            $modelList->addListItem($model);
        }
        return $modelList;
    }

    #endregion

    #region IMAGES

    /**
     * @param Page $page
     * @param string $fieldName
     * @param int $width
     * @param int|null $height
     * @param int $quality
     * @param ImageType $imageType
     * @param string $imageFormat
     * @param ImageSizes $imageSizes
     * @param bool $crop
     * @param string $imageClass
     * @return Image
     * @throws KirbyRetrievalException
     */
    /**
     * Returns an Image model for an SVG file field, using the raw file URL without
     * any thumbnail processing.
     *
     * @param Page $page
     * @param string $fieldName
     * @param string $imageClass
     * @return Image
     * @throws KirbyRetrievalException
     */
    protected function getSvgImage(Page $page, string $fieldName, string $imageClass = ''): Image
    {
        return $this->imageService->getSvgImage($page, $fieldName, $imageClass);
    }

    public function getImage(Page       $page,
                                string     $fieldName,
                                int        $width,
                                ?int       $height,
                                int        $quality = 90,
                                ImageType  $imageType = ImageType::SQUARE,
                                string     $imageFormat = '',
                                ImageSizes $imageSizes = ImageSizes::NOT_SPECIFIED,
                                bool       $crop = true,
                                string     $imageClass = ''): Image
    {
        return $this->imageService->getImage($page, $fieldName, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param int $width
     * @param int|null $height
     * @param int $quality
     * @param ImageType $imageType
     * @param string $imageFormat
     * @param ImageSizes $imageSizes
     * @param bool $crop
     * @param string $imageClass
     * @return ImageList
     * @throws KirbyRetrievalException
     */
    protected function getImages(Page       $page,
                                 string     $fieldName,
                                 int        $width,
                                 ?int       $height,
                                 int        $quality = 90,
                                 ImageType  $imageType = ImageType::SQUARE,
                                 string     $imageFormat = '',
                                 ImageSizes $imageSizes = ImageSizes::NOT_SPECIFIED,
                                 bool       $crop = true,
                                 string     $imageClass = ''): ImageList
    {
        return $this->imageService->getImages($page, $fieldName, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
    }

    /**
     * @param StructureObject $structureObject
     * @param string $fieldName
     * @param int $width
     * @param int $height
     * @param int $quality
     * @param ImageType $imageType
     * @param string $imageFormat
     * @param ImageSizes $imageSizes
     * @param bool $crop
     * @param string $imageClass
     * @return Image
     * @throws KirbyRetrievalException
     */
    protected function getImageFromStructureField(StructureObject $structureObject,
                                                  string          $fieldName,
                                                  int             $width,
                                                  int             $height,
                                                  int             $quality = 90,
                                                  ImageType       $imageType = ImageType::SQUARE,
                                                  string          $imageFormat = '',
                                                  ImageSizes      $imageSizes = ImageSizes::NOT_SPECIFIED,
                                                  bool            $crop = true,
                                                  string          $imageClass = ''): Image
    {
        return $this->imageService->getImageFromStructureField($structureObject, $fieldName, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
    }

    /**
     * @param StructureObject $structureObject
     * @param string $fieldName
     * @param int $width
     * @param int $height
     * @param int $quality
     * @param ImageType $imageType
     * @param string $imageFormat
     * @param ImageSizes $imageSizes
     * @param bool $crop
     * @param string $imageClass
     * @return ImageList
     * @throws KirbyRetrievalException
     */
    protected function getImagesFromStructureField(StructureObject $structureObject,
                                                   string          $fieldName,
                                                   int             $width,
                                                   int             $height,
                                                   int             $quality = 90,
                                                   ImageType       $imageType = ImageType::SQUARE,
                                                   string          $imageFormat = '',
                                                   ImageSizes      $imageSizes = ImageSizes::NOT_SPECIFIED,
                                                   bool            $crop = true,
                                                   string          $imageClass = ''): ImageList
    {
        return $this->imageService->getImagesFromStructureField($structureObject, $fieldName, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
    }

    /**
     * @param string $fieldName
     * @param int $width
     * @param int $height
     * @param int $quality
     * @param ImageType $imageType
     * @param string $imageFormat
     * @param ImageSizes $imageSizes
     * @param bool $crop
     * @param string $imageClass
     * @return Image
     * @throws KirbyRetrievalException
     */
    protected function getImageFromSiteField(string     $fieldName,
                                             int        $width,
                                             int        $height,
                                             int        $quality = 90,
                                             ImageType  $imageType = ImageType::SQUARE,
                                             string     $imageFormat = '',
                                             ImageSizes $imageSizes = ImageSizes::NOT_SPECIFIED,
                                             bool       $crop = true,
                                             string     $imageClass = ''): Image
    {
        return $this->imageService->getImageFromSiteField($fieldName, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
    }

    /**
     * @param File $image
     * @param int $width
     * @param ?int $height
     * @param int $quality
     * @param ImageType $imageType
     * @param string $imageFormat (e.g. webp)
     * @param ImageSizes $imageSizes
     * @param bool $crop
     * @param string $imageClass
     * @return Image
     * @throws KirbyRetrievalException
     */
    protected function getImageFromFile(File       $image,
                                        int        $width,
                                        ?int       $height,
                                        int        $quality = 90,
                                        ImageType  $imageType = ImageType::SQUARE,
                                        string     $imageFormat = '',
                                        ImageSizes $imageSizes = ImageSizes::NOT_SPECIFIED,
                                        bool       $crop = true,
                                        string     $imageClass = ''): Image
    {
        return $this->imageService->getImageFromFile($image, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
    }

    #endregion


    #region RELATED CONTENT

    /**
     * Retrieves a related content list from a structure field of the provided page.
     * Iterates through the structure field items, extracting and converting relevant data
     * to create a list of related content.
     *
     * @param Page $page The page object containing the structure field.
     * @param string $fieldName The name of the structure field to retrieve data from.
     * @return WebPageLinks The assembled list of related content items.
     * @throws KirbyRetrievalException
     */
    protected function getRelatedContentListFromPagesField(Page $page, string $fieldName = 'related'): WebPageLinks
    {
        $relatedContent = $this->getPageFieldAsPages($page, $fieldName);
        $relatedContentList = new WebPageLinks();
        foreach ($relatedContent as $item) {
            $itemTitle = $this->getPageTitle($item);
            if (!empty($itemTitle)) {
                $content = new WebPageLink(
                    $itemTitle,
                    $item->url(),
                    $item->id(),
                    $item->template()->name()
                );
                $relatedContentList->addListItem($content);
            }
        }
        return $relatedContentList;
    }

    /**
     * @param Page $page
     * @param string $fieldName
     * @param ImageType $imageType
     * @return WebPageLinks
     */
    protected function getRelatedContentListFromStructureField(Page      $page,
                                                               string    $fieldName = 'related',
                                                               ImageType $imageType = ImageType::FIXED): WebPageLinks
    {
        $webPageLinks = new WebPageLinks();
        try {
            $relatedLinksStructure = $this->getPageFieldAsStructure($page, $fieldName);

            foreach ($relatedLinksStructure as $item) {
                $itemTitle = $this->getStructureFieldAsString($item, 'title');
                if ($this->hasStructureField($item, 'url')) {
                    $page = $this->getStructureFieldAsPage($item, 'url');
                    $itemTitle = empty($itemTitle) ? $page->title()->value() : $itemTitle;
                    $description = $this->getStructureFieldAsString($item, 'description');
                    $webPageLink = $this->getWebPageLink($page, true, $itemTitle, $description);
                    $openInNewTab = $this->getStructureFieldAsBool($item, 'openInNewTab');
                    $webPageLink->setOpenInNewTab($openInNewTab);
                    if ($this->isPageFieldNotEmpty($page, 'panelImage')) {
                        $image = $this->getImage($page,
                            'panelImage',
                            400,
                            300,
                            80,
                            $imageType,
                            '',
                            ImageSizes::HALF_LARGE_SCREEN);
                        $webPageLink->setImage($image);
                    } elseif ($this->isSiteFieldNotEmpty('placeholderImage')) {
                        $image = $this->getImageFromSiteField(
                            'placeholderImage',
                            400,
                            300,
                            80,
                            $imageType,
                            '',
                            ImageSizes::HALF_LARGE_SCREEN);
                        $webPageLink->setImage($image);
                    }
                    $webPageLinks->addListItem($webPageLink);
                } else {
                    if (!empty($itemTitle)) {
                        $webPageLink = new WebPageLink(
                            $this->getStructureFieldAsString($item, 'title'),
                            $this->getStructureFieldAsLinkUrl($item, 'url'),
                            '',
                            ''
                        );
                        $webPageLinks->addListItem($webPageLink);
                    }
                }
            }
        } catch (KirbyRetrievalException $e) {
            $webPageLinks->setStatus(false);
            $webPageLinks->addErrorMessage($e->getMessage());
            $webPageLinks->addFriendlyMessage('No web page links found');
        }
        return $webPageLinks;
    }



    #endregion

    #region SESSIONS

    /**
     * @param string $key
     * @param Object $value
     * @return void
     */
    public function setSessionObject(string $key, object $value): void
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $this->kirby->session()->set($key, $value);
    }

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function setSessionString(string $key, string $value): void
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $this->kirby->session()->set($key, $value);
    }

    /**
     * @param string $key
     * @return object
     */
    public function pullSessionObject(string $key): object
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return $this->kirby->session()->pull($key);
    }

    /**
     * @param string $key
     * @return string
     */
    public function pullSessionString(string $key): string
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return $this->kirby->session()->pull($key);
    }

    #endregion

    #region REQUESTS

    /**
     * @return bool
     * @noinspection PhpUnused
     */
    protected function hasPostRequest(): bool
    {
        return ($this->kirby->request()->is('POST'));
    }

    protected function hasGetRequest(): bool
    {
        return ($this->kirby->request()->is('GET'));
    }

    /**
     * Post/Redirect/Get for filter forms.
     *
     * Filter forms are submitted with POST to the current URL, which may still
     * carry a ?page=N (or /page:N) from the pagination page the user was viewing.
     * Once the POSTed filter values have been persisted (e.g. to cookies) this
     * redirects to the same page with the pagination position dropped, so the
     * filtered results are rendered from page 1 via a fresh GET (issue #559).
     * Following the Post/Redirect/Get pattern also means a browser refresh does
     * not re-submit the filter.
     *
     * Only the pagination position is stripped (the `page` query param and any
     * `page:N` route param); other query params such as sort_by/sort_dir are
     * preserved so re-filtering does not reset an active sort.
     *
     * This is called from the shared pagination code paths, so every filtered
     * list inherits the behaviour without per-page wiring. It is a no-op on a
     * non-POST request.
     *
     * @return void
     */
    protected function redirectAfterFilterSubmission(): void
    {
        if (!$this->hasPostRequest()) {
            return;
        }

        $query = $this->kirby->request()->query()->toArray();
        unset($query['page']);
        // A fresh filter submission supersedes any pending reset request;
        // otherwise the redirected GET would immediately clear the new filters.
        unset($query[FilterResetService::RESET_PARAM]);

        $url = $this->kirby->request()->url()->clone(['query' => $query, 'params' => []])->toString();
        go($url);
    }

    /**
     * Checks if a request exists for the given key.
     *
     * @param string $key The key to check in the request.
     * @return bool Returns true if the request with the given key exists, false otherwise.
     * @noinspection PhpUnused
     */
    protected function hasRequest(string $key): bool
    {
        return get($key) !== null;
    }

    /**
     * @param string $key
     * @param string $fallback
     * @return string
     * @noinspection PhpUnused
     */
    protected function getRequestAsString(string $key, string $fallback = ''): string
    {
        return $this->asString(get($key), $fallback);
    }

    /**
     * Will return zero if no request is found
     * @param string $key
     * @return int
     */
    protected function getRequestAsInt(string $key): int
    {
        return (int) get($key, 0);
    }


    /**
     * Retrieves a request parameter or cookie value by the given key,
     * returning a fallback value if neither is available.
     *
     * @param string $key The key to look for in request parameters or cookies.
     * @param string $fallBack The fallback value to return if the key does not exist in both request and cookie data.
     * @return string The value retrieved from the request parameter, cookie, or the provided fallback.
     * @noinspection PhpUnused
     */
    protected function getRequestOrCookie(string $key, string $fallBack): string
    {
        return get($key) ?: cookie::get($key) ?: $fallBack;
    }

    /**
     * Read and validate sort parameters from the current request.
     *
     * Reads 'sort_by' and 'sort_dir' GET parameters and validates them against
     * an allowed-columns whitelist to prevent arbitrary field injection.
     * Falls back to the supplied defaults when parameters are absent or invalid.
     *
     * @param string[] $allowedColumns Column keys that may be sorted on
     * @param string $defaultSortBy Default column key when none is supplied or the supplied value is invalid
     * @param string $defaultSortDir Default direction ('asc' or 'desc')
     * @return array{0: string, 1: string} Tuple of [sortBy, sortDir]
     */
    protected function readSortFromRequest(
        array  $allowedColumns,
        string $defaultSortBy = '',
        string $defaultSortDir = 'asc'
    ): array {
        $sortBy  = $this->getRequestAsString('sort_by', $defaultSortBy);
        $sortDir = $this->getRequestAsString('sort_dir', $defaultSortDir);

        if (!in_array($sortBy, $allowedColumns, true)) {
            $sortBy = $defaultSortBy;
        }

        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = $defaultSortDir;
        }

        return [$sortBy, $sortDir];
    }


    #endregion

    #region EMAIL

    /**
     * Send one templated email.
     *
     * Returns whether the message actually reached the mailer, so a caller
     * sending in a loop can report which recipients missed out rather than
     * discovering it in a log afterwards. Failures are still logged here; the
     * return value is in addition to that, not instead of it.
     *
     * **False covers both a rejection and a send suppressed by the environment**,
     * because the question a caller is asking is "did this person receive it?"
     * and the answer in both cases is no. Reporting suppression as success would
     * let a caller record somebody as notified when nothing was sent — which is
     * exactly the kind of quiet wrong answer this return value exists to
     * prevent. A caller that wants to explain *why* nothing went should check
     * the environment before calling, where it can say so precisely.
     *
     * @param string $template The email template name
     * @param string $from The sender address
     * @param string $replyTo The reply-to address
     * @param string $to One address, or several separated by commas
     * @param string $subject The subject line
     * @param array<string, mixed> $data Values the template renders from
     * @return bool True only when the message reached the mailer; false when it
     *              was rejected or suppressed by the environment
     */
    protected function sendEmail(string $template,
                                 string $from,
                                 string $replyTo,
                                 string $to,
                                 string $subject,
                                 array  $data): bool
    {
        if (!self::environmentSendsEmail()) {
            return false;
        }

        $recipients = str_contains($to, ',') ? Str::split($to) : $to;

        try {
            $this->kirby->email([
                'template' => $template,
                'from' => $from,
                'replyTo' => $replyTo,
                'to' => $recipients,
                'subject' => $subject,
                'data' => $data
            ]);
        } catch (Throwable $error) {
            $this->writeToLog('errors', $error->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Whether this environment sends outbound email at all.
     *
     * This is a backstop, not the gate a site should rely on. It exists so that
     * a send from a context with no configured environment still refuses on a
     * developer machine; a site that cares should decide before calling here.
     *
     * Three sources, in order:
     *
     * 1. `environment.type` as a plain string, the shape EnvironmentGuard
     *    documents.
     * 2. `environment` as a plain string, for sites that configure it flat.
     * 3. The HTTP host, as this method always did.
     *
     * A site may instead resolve `environment` to an object — capsella sets it
     * to a resolved Environment instance — in which case neither option matches
     * and the host check applies. That is deliberate rather than an oversight:
     * kirby-base does not know that class, and such a site is already deciding
     * for itself before it calls this. What matters is that the fallback still
     * refuses on localhost, which it does.
     *
     * The host check now tolerates a missing HTTP_HOST rather than warning about
     * an undefined key and then sending anyway, which is what it did under CLI
     * and cron.
     *
     * @return bool True when email may be sent from here
     */
    protected static function environmentSendsEmail(): bool
    {
        foreach ([option('environment.type'), option('environment')] as $configured) {
            if (is_string($configured) && $configured !== '') {
                return $configured !== 'local';
            }
        }

        return !str_starts_with((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost');
    }

    #endregion

    #region COOKIES

    /**
     * Sets a cookie using PHP's setcookie() directly to avoid triggering
     * Kirby's cache tracking which would mark the response as private
     * and prevent page caching.
     *
     * @param string $key The cookie name
     * @param string $value The cookie value
     * @param int $days Number of days until expiry
     * @return void
     */
    protected function setCookie(string $key, string $value, int $days = 90): void
    {
        //allow insecure cookies on localhost only
        $secure = !(str_starts_with((string) $_SERVER['HTTP_HOST'], 'localhost'));
        $expires = $days > 0 ? time() + ($days * 24 * 60 * 60) : 0;

        // Use PHP's setcookie() directly instead of Kirby's Cookie::set()
        // to avoid registering the cookie with the Responder's trackUsage(),
        // which would mark the response as private and prevent page caching.
        setcookie($key, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite' => 'Lax'
        ]);

        // Also set in $_COOKIE so it's available in the current request
        $_COOKIE[$key] = $value;
    }

    /**
     * Deletes a cookie by setting its expiry in the past.
     *
     * @param string $key The cookie name
     * @return void
     */
    protected function deleteCookie(string $key): void
    {
        $secure = !(str_starts_with((string) $_SERVER['HTTP_HOST'], 'localhost'));
        setcookie($key, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite' => 'Lax'
        ]);
        unset($_COOKIE[$key]);
    }

    /**
     * Check if a cookie exists.
     * Uses $_COOKIE directly to avoid triggering Kirby's cache tracking.
     *
     * @param string $key The cookie name
     * @return bool True if the cookie exists
     */
    protected function hasCookie(string $key): bool
    {
        return isset($_COOKIE[$key]);
    }

    /**
     * Gets a cookie value.
     * Uses $_COOKIE directly to avoid triggering Kirby's cache tracking.
     *
     * @param string $key The cookie name
     * @param string $fallback Default value if cookie doesn't exist
     * @return string The cookie value or fallback
     */
    protected function getCookieAsString(string $key, string $fallback = ''): string
    {
        return $this->asString($_COOKIE[$key] ?? $fallback);
    }

    /**
     * True when the current request asks for all user-driven filters to be
     * reset (the "Remove all filters" link on the filter warning).
     *
     * @return bool
     */
    protected function isFilterResetRequested(): bool
    {
        return $this->getFilterResetService()->isResetRequested();
    }

    /**
     * Persists a per-user filter selection.
     *
     * ALWAYS set filter cookies through this method (never the raw
     * {@see self::setCookie()}). It namespaces the cookie with
     * {@see FilterCookiePolicy::PREFIX} ('flt_') so the page cache can detect an
     * actively-filtered request and skip caching it. A filter cookie set without
     * the prefix is invisible to {@see self::isPageCacheable()} and would let a
     * cached filtered listing leak between visitors / survive a reset (#603).
     * See documentation/caching.md.
     *
     * @param string $key The filter key (unprefixed), e.g. 'newsExternalKeywords'
     * @param string $value The value to persist
     * @param int $days Number of days until expiry
     * @return void
     */
    protected function setFilterCookie(string $key, string $value, int $days = 90): void
    {
        $this->setCookie($this->getFilterCookiePolicy()->prefixedName($key), $value, $days);
    }

    /**
     * Deletes a per-user filter cookie (the prefixed one written by
     * {@see self::setFilterCookie()}).
     *
     * @param string $key The filter key (unprefixed)
     * @return void
     */
    protected function deleteFilterCookie(string $key): void
    {
        $this->deleteCookie($this->getFilterCookiePolicy()->prefixedName($key));
    }

    /**
     * Reads a persisted filter cookie, honouring a filter reset request:
     * when the current request carries the clearFilters parameter the cookie
     * is deleted and the fallback returned, so the filter reverts to the same
     * default a fresh visitor (with no cookies) sees.
     *
     * Filter cookies are namespaced with {@see FilterCookiePolicy::PREFIX} so
     * that {@see self::isPageCacheable()} can spot a filtered request. A legacy
     * un-prefixed cookie (set before the prefix was introduced) is adopted once
     * on read — re-persisted under the prefixed name and the orphan expired — so
     * existing visitors keep their saved filters. That transition fallback can
     * be removed once legacy cookies have aged out (~90 days). See
     * documentation/caching.md.
     *
     * @param string $key The filter key (unprefixed)
     * @param string $fallback Default value the filter reverts to
     * @return string The persisted value, or the fallback on reset/missing cookie
     */
    protected function getFilterCookieAsString(string $key, string $fallback = ''): string
    {
        $prefixedKey = $this->getFilterCookiePolicy()->prefixedName($key);

        // Transition fallback: migrate a legacy un-prefixed filter cookie to the
        // prefixed name once, then expire the orphan. setCookie()/deleteCookie()
        // update $_COOKIE in place, so the migrated value is readable via the
        // prefixed key immediately below within this same request.
        if (!$this->hasCookie($prefixedKey) && $this->hasCookie($key)) {
            $this->setFilterCookie($key, $this->getCookieAsString($key, $fallback));
            $this->deleteCookie($key);
        }

        return $this->getFilterResetService()->resolve(
            $this->getCookieAsString($prefixedKey, $fallback),
            $fallback,
            fn () => $this->deleteFilterCookie($key)
        );
    }

    /**
     * Lazily builds the filter-cookie namespacing/cache policy.
     *
     * @return FilterCookiePolicy
     */
    private function getFilterCookiePolicy(): FilterCookiePolicy
    {
        return $this->filterCookiePolicy ??= new FilterCookiePolicy();
    }

    /**
     * Lazily builds the filter reset service from the current request.
     *
     * @return FilterResetService
     */
    private function getFilterResetService(): FilterResetService
    {
        return $this->filterResetService ??= FilterResetService::fromRequestValue(
            $this->getRequestAsString(FilterResetService::RESET_PARAM)
        );
    }

    /**
     * Gets a cookie value directly from $_COOKIE without triggering Kirby's
     * cache tracking. Use this for cookies that don't change the page content
     * (e.g., cookie consent that only affects client-side JS behavior).
     *
     * @param string $key The cookie name
     * @param string $fallback Default value if cookie doesn't exist
     * @return string The cookie value or fallback
     */
    protected function getCookieCacheSafe(string $key, string $fallback = ''): string
    {
        return $this->asString($_COOKIE[$key] ?? $fallback);
    }

    /**
     * Processes cookie consent form submission (accept or reject).
     * Sets the cookie consent value to 'accepted' or 'rejected' and deletes
     * any legacy cookie. Redirects to the referring page or the home page.
     *
     * @return void
     */
    public function processCookieConsent(): void
    {
        // Note: Cookie consent is now handled client-side via JavaScript.
        // This server-side method is kept for backwards compatibility but is largely unused.
        // The JavaScript sets 'accepted' or 'rejected' values directly.
        if (csrf(get('csrf')) === true) {
            $consent = $this->getRequestAsString('consent');
            $cookieValue = ($consent === 'accepted') ? 'accepted' : 'rejected';
            $this->setCookie(self::COOKIE_CONSENT_NAME, $cookieValue, 365);
            $this->deleteCookie(self::LEGACY_COOKIE_CONSENT_NAME);
            $referringPage = $this->getRequestAsString('referringPage');
            if (!empty($referringPage)) {
                go($referringPage);
            }
        }
        go();
    }

    /**
     * @return bool
     */
    public function requiresCookieConstent(): bool
    {
        return option('cookieConsentRequired', false);
    }

    /**
     * Checks if cookie consent has been given.
     * Recognises current value ('accepted') and legacy values ('yes', 'true', '1')
     * from earlier cookie name ('cookieConsentGiven') and value formats.
     * Uses cache-safe cookie reading since the consent state only affects
     * client-side JS behavior, not the page HTML content.
     *
     * @return bool
     */
    public function hasCookieConsent(): bool
    {
        $value = $this->getCookieCacheSafe(self::COOKIE_CONSENT_NAME);
        if ($value === '') {
            $value = $this->getCookieCacheSafe(self::LEGACY_COOKIE_CONSENT_NAME);
        }
        return in_array($value, ['accepted', 'yes', 'true', '1'], true);
    }

    /**
     * Checks if cookie consent has been explicitly rejected.
     * Recognises current value ('rejected') and legacy value ('no').
     * Uses cache-safe cookie reading since the consent state only affects
     * client-side JS behavior, not the page HTML content.
     *
     * @return bool
     */
    public function hasCookieConsentRejected(): bool
    {
        $value = $this->getCookieCacheSafe(self::COOKIE_CONSENT_NAME);
        if ($value === '') {
            $value = $this->getCookieCacheSafe(self::LEGACY_COOKIE_CONSENT_NAME);
        }
        return in_array($value, ['rejected', 'no'], true);
    }

    #endregion

    #region ERROR HANDLING

    /**
     * writes to error log and sends error email
     * @param Exception $e
     * @return void
     */
    public function handleError(Exception $e): void {
        $this->writeToErrorLog($e->getMessage());
        $this->sendErrorEmail(new KirbyRetrievalException($e->getMessage()));
    }

    /**
     * writes to error log and sends error email
     * @param KirbyRetrievalException $e
     * @return void
     */
    public function handleKirbyRetrievalException(KirbyRetrievalException $e): void {
        $this->writeToErrorLog($e->getMessage());
        $this->sendErrorEmail($e);
    }

    public function writeToErrorLog($message): void {
        $this->writeToLog('errors', $message);
    }

    /**
     * @param string $logFile .log is added
     * @param string $message
     * @param bool $overwrite
     * @return void
     */
    public function writeToLog(string $logFile, string $message, bool $overwrite = false): void {
        self::writeToLogFile($logFile, $message, $overwrite);
    }

    /**
     * Write a message to a named log file (static version for use in hooks and other static contexts).
     *
     * @param string $logFile Log file name without extension (.log is appended)
     * @param string $message Message to write
     * @param bool $overwrite If true, overwrites the log file; otherwise appends
     * @return void
     */
    public static function writeToLogFile(string $logFile, string $message, bool $overwrite = false): void {
        $logDir = kirby()->root('logs');

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logPath = $logDir . '/' . $logFile . '.log';

        $date = new DateTime();
        $dateStr = $date->format("y:m:d h:i:s");

        $flags = $overwrite ? 0 : FILE_APPEND;
        file_put_contents($logPath, $dateStr . ' ' . $message . PHP_EOL, $flags);
    }

    /**
     * @param KirbyRetrievalException $e
     * @param string $friendlyMessage
     * @return ActionStatus
     * @noinspection PhpUnused
     */
    protected function actionStatusError(KirbyRetrievalException $e, string $friendlyMessage): ActionStatus
    {
        return (new ActionStatus(false, $e->getMessage(), $friendlyMessage, $e));
    }

    /**
     * @param KirbyRetrievalException $e
     * @return BaseWebPage
     */
    protected function handlePageError(KirbyRetrievalException $e): BaseWebPage
    {
        $this->handleKirbyRetrievalException($e);
        $this->setSessionString('exceptionAsString', $this->getExceptionDetails($e));
        go('500');
    }

    /**
     * @param ActionStatus $actionStatus
     * @param class-string<BaseWebPage> $pageClass
     * @return BaseWebPage
     * @noinspection PhpUnused
     */
    protected function recordActionStatusError(ActionStatus $actionStatus,
                                               string       $pageClass = BaseWebPage::class): BaseWebPage
    {
        $webPage = $this->getErrorPage($pageClass);
        $webPage
            ->recordError(
                $actionStatus->getFirstErrorMessage(),
                'An error occurred while retrieving the ' . $webPage->getTitle() . ' page.',
            );
        $this->sendErrorEmail($actionStatus->getException());
        return $webPage;
    }


    /**
     * @param string $pageClass
     * @return BaseWebPage
     */
    protected function getErrorPage(string $pageClass = BaseWebPage::class) : BaseWebPage {
        /** @var BaseWebPage $webPage */
        $pageName = $this->extractClassName($pageClass);
        $webPage = new $pageClass($pageName, '', 'error');
        $user = $this->getCurrentUser();
        $webPage->setCurrentUser($user);
        return $webPage;
    }

    /**
     * @param KirbyRetrievalException $e
     * @param class-string<BaseModel> $modelClass
     * @return BaseModel
     */
    protected function recordModelError(KirbyRetrievalException $e, string $modelClass = BaseModel::class): BaseModel
    {
        /** @var BaseModel $model */
        $model = new $modelClass('Error', '', 'error');
        $modelName = str_replace('BSBI\\models\\', '', $modelClass);
        $model
            ->recordError(
                $e->getMessage(),
                'An error occurred while retrieving the ' . $modelName . '.',
            );
        $this->sendErrorEmail($e);
        return $model;
    }



    /**
     * @param KirbyRetrievalException $e
     * @return void
     */
    public function sendErrorEmail(KirbyRetrievalException $e): void
    {

        $exceptionAsString =  "Message: " . $e->getMessage() . "\n" .
            "File:" . $e->getFile() . "'\n" .
            "Line:" . $e->getLine() . "\n" .
            "Trace:" . $e->getTraceAsString();
        $this->writeToLog('errors', $exceptionAsString);
        $from = option('defaultEmail');
        $to = option('adminEmail');
        if (!is_string($from) || $from === '' || !is_string($to) || $to === '') {
            // Without notification addresses the log entry above is the record;
            // the error path must never itself throw.
            return;
        }
        $this->sendEmail('error-notification',
            $from,
            $from,
            $to,
            'Website Exception: ' .$e->getMessage(),
            [
                'errorMessage' => $this->getExceptionDetails($e),
            ]
        );

    }

    /**
     * Will send email from defaultEmail in config
     * @param string $template
     * @param string $to
     * @param string $subject
     * @param array $content
     * @return void
     */
    public function sendNotificationEmail(string $template, string $to, string $subject, array $content): void
    {
        $this->sendEmail($template,
            option('defaultEmail'),
            option('defaultEmail'),
            $to,
            $subject,
            $content
        );

    }

    /**
     * @param KirbyRetrievalException $exception
     * @return string
     */
    protected function getExceptionDetails(KirbyRetrievalException $exception): string
    {
        $details = "An exception was thrown in your application:<br><br>";
        $details .= $this->getExceptionDetail($exception);

        // Only access user if session cookie exists to avoid starting a session (which prevents caching)
        $userLoggedIn = $this->hasSessionCookie() ? $this->kirby->user() : null;
        $userId = ($userLoggedIn) ? $userLoggedIn->id() : '';

        // Capture previous exceptions if they exist
        $previous = $exception->getPrevious();
        while ($previous) {
            $details .= "<b>Previous Exception:</b><br>";
            $details .= $this->getExceptionDetail($previous);
            $previous = $previous->getPrevious();
        }
        $details .= "<b>Request Details: </b>\n";
        $details .= "<b>URL: </b>" . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '') . "<br>";
        $details .= "<b>Method: </b>" . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . "<br>";
        $details .= "<b>IP Address: </b>" . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN') . "<br>";
        $details .= "<b>User Agent: </b>" . ($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN') . "<br><br>";
        $details .= "<b>Kirby User ID: </b>" . $userId . "<br><br>";

        return $details;
    }

    /**
     * @param Throwable $exception
     * @return string
     */
    private function getExceptionDetail(Throwable $exception): string
    {
        $detail = "<b>Message:</b> " . $exception->getMessage() . "<br>";
        $detail .= "<b>Code:</b> " . $exception->getCode() . "<br>";
        $detail .= "<b>File:</b> " . $exception->getFile() . "<br>";
        $detail .= "<b>Line:</b> " . $exception->getLine() . "<br><br>";
        $detail .= "<b>Stack Trace</b> :\n" . $exception->getTraceAsString() . "<br><br>";
        return $detail;
    }




    #endregion

    #region BREADCRUMB

    /**
     * @return bool
     */
    protected function hasBreadcrumb(): bool
    {
        return (isset($this->page)
            && !$this->page->isHomePage()
            && (!($this->page->template()->name() === 'search'))
            && ($this->page->depth() > 1));
    }

    /**
     * @return WebPageLinks
     * @throws KirbyRetrievalException
     */
    protected function getBreadcrumb(): WebPageLinks
    {
        if (!isset($this->page) || !$this->hasBreadcrumb()) {
            return new WebPageLinks();
        }
        $breadcrumbPagesCollection = $this->getBreadcrumbAsCollection();
        return $this->getWebPageLinks($breadcrumbPagesCollection);
    }

    /**
     * get breadcrumb, filtering out home page
     * @return Collection
     */
    private function getBreadcrumbAsCollection(): Collection
    {
        return $this->site->breadcrumb()
            ->filterBy('template', '!=', 'home')
            ->filterBy('isListed', true)->not($this->page);
    }

    /**
     * @param string $pageId
     * @param string $delimiter
     * @return string
     * @throws InvalidArgumentException
     */
    protected function getPageBreadcrumbAsString(string $pageId, string $delimiter = ' | '): string
    {
        $page = page($pageId);
        if (!$page) {
            return '';
        }
        $breadcrumbPages = $page->parents()->flip();
        $titles = $breadcrumbPages->map(function ($p) {
            return $p->title()->value();
        })->values();
        return implode($delimiter, $titles);
    }


    #endregion

    #region SEARCH

    /**
     * Get the query string (q)
     * @return string
     */
    protected function getSearchQuery(): string
    {
        return $this->searchService->getSearchQuery();
    }

    /**
     * @param string $query
     * @return WebPageLinks
     * @noinspection PhpUnused
     */
    protected function search(string $query): WebPageLinks
    {
        return $this->getSearchResults($query);
    }

    /**
     * if $specialSearchType is supplied, the function will look for a getWebPageLinksFor{$specialSearchType} function
     * if not, or if not matching function is provided, it will use getWebPageLinks
     * Will record a log entry in the SQLite-backed search log store, if enabled
     * @param string $query
     * @param Collection|null $collection
     * @param string $specialSearchType
     * @return WebPageLinks
     */
    protected function getSearchResults(string      $query,
                                        ?Collection $collection = null,
                                        string      $specialSearchType = ''): WebPageLinks
    {
        $searchResults = new WebPageLinks();
        if (!empty($query)) {
            try {
                if ($collection === null) {
                    $collection = $this->getSearchCollection($query);
                }
                $doingDefaultSearch = true;
                if (!empty($specialSearchType)) {
                    $getWebPageLinksFunction = 'getWebPageLinksFor' . $specialSearchType;
                    if (method_exists($this, $getWebPageLinksFunction)) {
                        $searchResults = $this->$getWebPageLinksFunction($collection);
                        $doingDefaultSearch = false;
                    }
                }
                if ($doingDefaultSearch) {
                    $searchResults = $this->getWebPageLinks($collection);
                }
                foreach ($searchResults->getListItems() as $searchResult) {
                    $highlightedTitle = SearchTextHelper::highlightTerm($searchResult->getTitle(), $query);
                    $highlightedDescription = SearchTextHelper::highlightTerm($searchResult->getDescription(), $query);
                    $searchResult->setTitle($highlightedTitle);
                    $searchResult->setDescription($highlightedDescription);
                    $searchResult->setBreadcrumb($this->getPageBreadcrumbAsString($searchResult->getPageId()));
                }

                $paginationFromKirby = $collection->pagination();

                if (isset($paginationFromKirby)) {
                    $pagination = $this->getPagination($paginationFromKirby);
                    $searchResults->setPagination($pagination);
                    if ($pagination->getCurrentPage() === 1) {
                        $this->logSearchQuery($query);
                    }
                }
            } catch (\Exception $e) {
                $searchResults->recordError($e->getMessage(), 'An error occurred while retrieving the search results');
            }
        }

        return $searchResults;
    }

    /** @var string Default search log database path, relative to the `logs` root */
    private const string DEFAULT_SEARCH_LOG_DATABASE_PATH = '/search/search-log.sqlite';

    /**
     * Logs a search query via SearchQueryLogger, honouring the
     * `search.logQueries` config option (default true) as a kill-switch.
     *
     * The kill-switch is checked before the SQLite store is opened at all,
     * not just passed through to the logger, so a disabled site never even
     * creates the log database file.
     *
     * Any failure is written to the error log so that logging problems
     * never break the search itself.
     *
     * @param string $query The search query to log
     * @return void
     */
    private function logSearchQuery(string $query): void
    {
        try {
            $logQueries = (bool)option('search.logQueries', true);
            if (!$logQueries) {
                return;
            }

            $logDatabasePath = (string)option('search.logDatabasePath', self::DEFAULT_SEARCH_LOG_DATABASE_PATH);
            $store = SearchLogStore::open($this->kirby->root('logs') . $logDatabasePath);
            $logger = new SearchQueryLogger($store, $logQueries, (int)option('search.logRetentionMonths', 24));
            $logger->log($query);
        } catch (Throwable $e) {
            $this->writeToErrorLog($e->getMessage());
        }
    }

    /**
     * Get top search terms by frequency
     *
     * @param int $limit Number of results to return
     * @return array<array{term: string, count: int}> Array of search terms with counts
     */
    public function getTopSearchTerms(int $limit = 20): array
    {
        return $this->searchService->getTopSearchTerms($limit);
    }

    /**
     * Get top search keywords by frequency (parsed from queries, stop words removed)
     *
     * @param int $limit Number of results to return
     * @return array<array{keyword: string, count: int}> Array of keywords with counts
     */
    public function getTopSearchKeywords(int $limit = 20): array
    {
        return $this->searchService->getTopSearchKeywords($limit);
    }

    /**
     * Get search analytics summary
     *
     * @return array{totalSearches: int, uniqueTerms: int, dateRange: array{from: string|null, to: string|null}}
     */
    public function getSearchAnalyticsSummary(): array
    {
        return $this->searchService->getSearchAnalyticsSummary();
    }

    /**
     * @param \Kirby\Cms\Pagination|\Kirby\Toolkit\Pagination $paginationFromKirby
     * @return Pagination
     */
    protected function getPagination(\Kirby\Toolkit\Pagination|\Kirby\Cms\Pagination $paginationFromKirby): Pagination
    {
        $pagination = new Pagination();
        $pagination->setHasPreviousPage($paginationFromKirby->hasPrevPage());
        $pagination->setPreviousPageUrl($paginationFromKirby->prevPageURL() ?? '');
        $pagination->setHasNextPage($paginationFromKirby->hasNextPage());
        $pagination->setNextPageUrl($paginationFromKirby->nextPageURL() ?? '');
        $pagination->setCurrentPage($paginationFromKirby->page());
        $pagination->setPageCount($paginationFromKirby->pages());

        for ($i = 1; $i <= $paginationFromKirby->pages(); $i++) {
            $pagination->addPageUrl($paginationFromKirby->pageUrl($i) ?? '');
        }
        return $pagination;
    }

    /**
     * Search the specified Kirby collection
     * @param string|null $query
     * @param string $params
     * @param int $perPage the number of records per field
     * @param Collection|null $collection
     * @return Collection
     */
    protected function getSearchCollection(
        ?string     $query = null,
        string      $params = 'title|mainContent|description|keywords',
        int         $perPage = 10,
        ?Collection $collection = null
    ): Collection {
        return $this->searchService->getSearchCollection($query, $params, $perPage, $collection);
    }

    /**
     * Search using SQLite FTS5 index with fallback to in-memory search
     *
     * @param string|null $query Search query
     * @param int $perPage Results per page
     * @param string|null $templates Optional comma-delimited template names to filter results
     * @return Collection
     */
    protected function getSearchCollectionSqlite(
        ?string $query = null,
        int     $perPage = 10,
        ?string $templates = null
    ): Collection {
        return $this->searchService->getSearchCollectionSqlite($query, $perPage, $templates);
    }

    /**
     * Get the available content type filter options for search
     *
     * @return array Array of content type option names
     */
    protected function getSearchContentTypeOptions(): array
    {
        return $this->searchService->getSearchContentTypeOptions();
    }

    /**
     * @param BaseWebPage $page
     * @param string $query
     * @return BaseWebPage
     */
    protected function highlightSearchQuery(BaseWebPage $page, string $query): BaseWebPage
    {
        return $this->searchService->highlightSearchQuery($page, $query);
    }

    #endregion


    #region FEEDBACK


    /**
     * @return FeedbackForm
     * @throws KirbyRetrievalException
     * @noinspection PhpUnused
     */
    protected function getFeedbackForm(): FeedbackForm
    {
        $feedbackForm = new FeedbackForm();
        $feedbackForm->setTurnstileSiteKey($this->getTurnstileSiteKey());
        if ($this->kirby->request()->is('POST') && get('submit')) {

            $name = get('name', '');
            $email = get('email', '');
            $feedback = get('feedback', '');
            $fromPage = get('page', '');

            $data = [
                'name' => is_string($name) ? $name : '',
                'email' => is_string($email) ? $email : '',
                'feedback' => is_string($feedback) ? $feedback : '',
                'feedbackPage' => is_string($fromPage) ? $fromPage : '',
            ];

            $turnstileStatus = $this->verifyTurnstile();

            if (!$turnstileStatus->getStatus()) {
                return $feedbackForm
                    ->setNameValue($data['name'])
                    ->setEmailValue($data['email'])
                    ->setFeedbackValue($data['feedback'])
                    ->setStatus(false)
                    ->addFriendlyMessage($turnstileStatus->getFirstFriendlyMessage());
            }

            $rules = [
                'name' => ['required', 'minLength' => 3],
                'email' => ['required', 'email'],
                'feedback' => ['required', 'minLength' => 3, 'maxLength' => 3000],
            ];

            $messages = [
                'name' => 'Please enter a valid name',
                'email' => 'Please enter a valid email address',
                'feedback' => 'Please enter a text between 3 and 3000 characters'
            ];

            if ($invalid = invalid($data, $rules, $messages)) {
                $feedbackForm
                    ->setNameValue($data['name'])
                    ->setNameAlert($invalid['name'] ?? '')
                    ->setEmailValue($data['email'])
                    ->setEmailAlert($invalid['email'] ?? '')
                    ->setFeedbackValue($data['feedback'])
                    ->setFeedbackAlert($invalid['feedback'] ?? '')
                    ->setStatus(false)
                    ->addFriendlyMessage(
                        'The form was not sent.  Please review the messages below.'
                    );
            } else {
                $emailData = [
                    'text' => esc($data['feedback']),
                    'sender' => esc($data['name']),
                    'email' => esc($data['email']),
                    'feedbackPage' => esc($data['feedbackPage'])
                ];

                if ($feedbackEmail = option('feedbackEmail')) {
                    $recipients = $feedbackEmail . ',' . option('adminEmail');
                } else
                {
                    $recipients = option('adminEmail');
                }

                $this->sendEmail('feedback',
                    option('defaultEmail'),
                    $data['email'],
                    $recipients,
                    esc($data['name']) . ' sent you feedback from the BSBI website',
                    $emailData
                );
                $feedbackForm
                    ->addFriendlyMessage(
                    'Your feedback has been sent, thank you.'
                    )
                    ->setStatus(true);
            }
        }
        return $feedbackForm;
    }
    #endregion

    #region TAGS_AND_FILTERS

    /**
     * @param Collection $pages
     * @param string $tagName
     * @param string $tagValue
     * @return Collection
     * @noinspection PhpUnused
     */
    public function filterByPagesTag(Collection $pages, string $tagName, string $tagValue): Collection
    {
        return $this->collectionFilterService->filterByPagesTag($pages, $tagName, $tagValue);
    }

    /**
     * @param Structure $structure
     * @param string $tagName
     * @param string $tagValue
     * @return Structure
     * @noinspection PhpUnused
     */
    public function filterStructureByPagesTag(Structure $structure, string $tagName, string $tagValue): Structure
    {
        return $this->collectionFilterService->filterStructureByPagesTag($structure, $tagName, $tagValue);
    }

    /**
     * @param Collection $pages
     * @param string $fieldName
     * @param string $value
     * @return Collection
     */
    public function filterByEqualsValue(Collection $pages, string $fieldName, string $value): Collection
    {
        return $this->collectionFilterService->filterByEqualsValue($pages, $fieldName, $value);
    }

    /**
     * @param Collection $pages
     * @param string $fieldName
     * @param string $value
     * @return Collection
     */
    public function filterByContainsValue(Collection $pages, string $fieldName, string $value): Collection
    {
        return $this->collectionFilterService->filterByContainsValue($pages, $fieldName, $value);
    }

    /**
     * @param Collection $pages
     * @param string $fieldName
     * @param array $values
     * @param bool $includeIfEmpty
     * @return Collection
     */
    public function filterByContainsValues(
        Collection $pages,
        string     $fieldName,
        array      $values,
        bool       $includeIfEmpty = false
    ): Collection {
        return $this->collectionFilterService->filterByContainsValues($pages, $fieldName, $values, $includeIfEmpty);
    }

    /**
     * @param Collection $pages
     * @param string $fieldName
     * @param string $pageFieldName
     * @param string $value
     * @return Collection
     */
    public function filterByContainsPageTitle(
        Collection $pages,
        string     $fieldName,
        string     $pageFieldName,
        string     $value
    ): Collection {
        return $this->collectionFilterService->filterByContainsPageTitle($pages, $fieldName, $pageFieldName, $value);
    }

    /**
     * Filter pages where a page-reference field contains any of the given values (OR logic).
     *
     * @param Collection $pages
     * @param string $fieldName
     * @param string $pageFieldName
     * @param string[] $values
     * @return Collection
     */
    public function filterByContainsPageTitleAny(
        Collection $pages,
        string     $fieldName,
        string     $pageFieldName,
        array      $values
    ): Collection {
        return $this->collectionFilterService->filterByContainsPageTitleAny($pages, $fieldName, $pageFieldName, $values);
    }

    /**
     * Filter pages where a page-reference field contains pages whose own title
     * matches any of the given values (OR logic).
     *
     * @param Collection $pages
     * @param string $fieldName
     * @param string[] $values
     * @return Collection
     */
    public function filterByLinkedPageTitleAny(Collection $pages, string $fieldName, array $values): Collection
    {
        return $this->collectionFilterService->filterByLinkedPageTitleAny($pages, $fieldName, $values);
    }

    /**
     * @param Page $kirbyPage
     * @param string $tagType
     * @param string $fieldName
     * @return WebPageTagLinkSet
     * @throws KirbyRetrievalException
     */
    protected function getWebPageTagLinkSet(Page $kirbyPage, string $tagType, string $fieldName): WebPageTagLinkSet
    {
        $tagLinkSet = new WebPageTagLinkSet('tagLinkSet');
        $tagLinkSet->setTagType($tagType);
        $tagLinkSet->setLinks($this->getPageFieldAsWebPageLinks($kirbyPage,$fieldName));
        return $tagLinkSet;
    }

    /**
     * @param Page $kirbyPage
     * @return WebPageTagLinks
     * @throws KirbyRetrievalException
     */
    protected function getTagLinks(Page $kirbyPage) : WebPageTagLinks {
        $tagFields = $this->getFieldsInSection($kirbyPage, 'tags');
        $tags = new WebPageTagLinks();
        foreach ($tagFields as $tagField) {
            $tagLinks = $this->getWebPageTagLinkSet($kirbyPage, $tagField['label'], $tagField['name']);
            if ($tagLinks->hasLinks()) {
                $tags->addListItem($tagLinks);
            }
        }
        return $tags;
    }

    /**
     * @param Page $kirbyPage
     * @param string $fieldName
     * @param string $modelListClass
     * @param BaseFilter|null $filter
     * @return BaseList
     * @throws KirbyRetrievalException
     */
    protected function getCustomTagList(Page   $kirbyPage,
                                        string $fieldName,
                                        string $modelListClass = BaseList::class,
                                        ?BaseFilter $filter = null): BaseList
    {
        $modelList = new $modelListClass();
        $modelClassName = $modelList->getItemType();
        $kirbyPages = $this->getPageFieldAsPages($kirbyPage,$fieldName);

        if ($kirbyPages) {
            // Exclude draft pages — toPages() resolves linked IDs and can return drafts
            $kirbyPages = $kirbyPages->filter(fn($page) => !$page->isDraft());

            if ($filter) {
                $modelList->setFilters($filter);
                $filterFunction = 'filter' . $this->extractClassName($modelListClass);

                if (method_exists($this, $filterFunction)) {
                    $kirbyPages = $this->$filterFunction($kirbyPages, $filter);
                }
            }

            foreach ($kirbyPages as $kirbyPage) {
                $model = $this->getSpecificModel($kirbyPage, $modelClassName);
                $modelList->addListItem($model);
            }
        }
        if ($filter !== null) {
            $modelList->setFilters($filter);
        }
        return $modelList;
    }


    /**
     * @throws KirbyRetrievalException
     * @throws InvalidArgumentException
     * @noinspection PhpUnused
     */
    public function syncTags($template) : string {
        $logFile = 'tag-sync';

        $this->writeToLog( $logFile, 'Starting sync of tags for template: '.$template);


        $tagMapping = option('tagMapping');
        $sitePages = $this->site->index()->filterBy('template', $template); //['product'])

        $i = 0;
        foreach ($sitePages as $page) {
            if (array_key_exists($page->template()->name(), $tagMapping))
            {
                /** @noinspection PhpUndefinedMethodInspection */
                $lastSyncTimestamp = $page->lastTagSync()->toTimestamp();
                $currentTime = time();
                // Calculate the time difference in minutes
                $timeDifferenceMinutes = round(abs($currentTime - $lastSyncTimestamp) / 60);
                if ($lastSyncTimestamp > 0 && $timeDifferenceMinutes < 30) {
                    $logMessage = "Skipped (synced " . $timeDifferenceMinutes . " mins ago)";
                } else {
                    $logMessage = $this->handleTwoWayTagging($page, null, false);

                }
                $this->writeToLog($logFile, $page->title() . '-' . $logMessage . "\n");

                if (($i % 50) === 0) { // Example: run GC every 50 pages
                    $collected = gc_collect_cycles();
                    $this->writeToLog(
                        $logFile,
                        "GC collected $collected cycles. Current memory: "
                        . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n",
                    );
                }
                $i++;
            }
        }
        $this->writetoLog($logFile, "Completed sync of tags...");
        return 'COMPLETE';
    }


    /**
     * Generic helper function to manage two-way tagging between pages.
     * When the 'taggingPage' (the page being created/updated) uses `taggingField`
     * to link to other pages, this function ensures those 'tagged pages' have their
     * `taggedField` updated to reflect the link to the 'taggingPage'.
     *
     * @param Page $taggingPage The page that was created or updated (e.g. a vacancy page).
     * @param Page|null $oldTaggingPage The old version of the tagging page (null for creation).
     * @param bool $singlePage
     * @return string
     * @throws InvalidArgumentException
     * @throws KirbyRetrievalException
     */
    public function handleTwoWayTagging(
        Page  $taggingPage,
        ?Page $oldTaggingPage = null,
        bool  $singlePage = true
    ):string {
        // Static flag to prevent re-entry for the current request lifecycle
        static $isSyncing = false;

        if ($this->getSiteFieldAsBool('pauseTaggingSync')) {
            return '';
        }

        // If the function is already running, exit to prevent infinite loops/re-entry
        if ($isSyncing) {
            return 'Already syncing, skipping re-entry.';
        }

        $isSyncing = true; // Set the flag

        $log = '';

        if ($singlePage) {
            kirby()->cache('pages')->flush();
        }

        // Get the unique ID of the page that was created or updated
        $taggingPageId = $taggingPage->id();

        $taggingFields = $this->getFieldsInSection($taggingPage, 'tags-fields');

        if (count($taggingFields) === 0) {
            $taggingFields = $this->getFieldsInSection($taggingPage, 'tags');
        }

        if (count($taggingFields) === 0) {
            return $log;
        }

        try {
            $tagMapping = option('tagMapping');
        }
        catch (Throwable) {
            $isSyncing = false;
            throw new KirbyRetrievalException('Tag mapping config not set up');
        }

        try {
            $taggedByField = $tagMapping[$taggingPage->template()->name()];
        } catch (Throwable) {
            $isSyncing = false;
            throw new KirbyRetrievalException('Tag mapping config not set up for ' . $taggingPage->template()->name());
        }

        foreach ($taggingFields as $taggingField) {
            $taggingFieldName = $taggingField['name'];

            // Get the current list of linked page IDs from the $taggingPage
            $newLinkedPageIds = $taggingPage->{$taggingFieldName}()->isNotEmpty()
                ? $taggingPage->{$taggingFieldName}()->toPages()->pluck('id')
                : [];

            // Get the old list of linked page IDs from $oldTaggingPage for removals
            // If $oldTaggingPage is not provided, assume no old links for removal tracking
            $oldLinkedPageIds = ($oldTaggingPage && $oldTaggingPage->{$taggingFieldName}()->isNotEmpty())
                ? $oldTaggingPage->{$taggingFieldName}()->toPages()->pluck('id')
                : [];

            // Determine which links were removed based on $oldTaggingPage
            $removedLinkedPageIds = array_diff($oldLinkedPageIds, $newLinkedPageIds);

            // --- Handle all current links on $taggingPage (add if not present) ---
            foreach ($newLinkedPageIds as $linkedPageId) {
                $linkedPage = kirby()->page($linkedPageId);

                if ($linkedPage) {
                    // Get existing IDs from the linked page's `taggedField`
                    $existingTaggingPageIds = $linkedPage->{$taggedByField}()->isNotEmpty()
                        ? $linkedPage->{$taggedByField}()->toPages()->pluck('id')
                        : [];

                    // If the $taggingPageId is not already in the linked page's field, add it
                    if (!in_array($taggingPageId, $existingTaggingPageIds)) {
                        $existingTaggingPageIds[] = $taggingPageId;

                        $blueprint = $linkedPage->blueprint(); // Get the blueprint object for the page
                        $fields = $blueprint->fields();        // Get all fields defined in the blueprint

                        // Check if the field exists within the blueprint's fields array
                        if (isset($fields[$taggedByField])) {
                            // Update the linked page with the modified list of IDs
                            try {
                                $linkedPage->update([
                                    $taggedByField => array_unique($existingTaggingPageIds) // Ensure uniqueness
                                ]);
                                $log .= "$taggingPageId added to $taggedByField on $linkedPageId";
                            } catch (Throwable $e) {
                                $isSyncing = false;
                                throw new KirbyRetrievalException(
                                    "Error adding $taggingPageId to $taggedByField on $linkedPageId: "
                                    . $e->getMessage()
                                );
                            }
                        } else {
                            $isSyncing = false;
                            throw new KirbyRetrievalException(
                                'Tag field '.$taggedByField
                                .' has not been set up in the '.$linkedPage->template()->name()
                                . ' blueprint  linked page '.$linkedPage->title()
                            );
                        }
                    }
                } else {
                    // Log or handle cases where a linked page from $newLinkedPageIds is not found
                    $log.= "Warning: Linked page with ID '$linkedPageId' not found for tagging page '$taggingPageId'.";
                }
            }

            // --- Handle links that were removed (based on $oldTaggingPage) ---
            foreach ($removedLinkedPageIds as $linkedPageId) {
                // Attempt to find the corresponding page by its ID
                $linkedPage = kirby()->page($linkedPageId);

                // If the linked page exists, update its `taggedField`
                if ($linkedPage) {
                    // Get existing IDs from the linked page's `taggedField`
                    $existingTaggingPageIds = $linkedPage->{$taggedByField}()->isNotEmpty()
                        ? $linkedPage->{$taggedByField}()->toPages()->pluck('id')
                        : [];

                    // Filter out the current $taggingPageId from the list
                    // Only remove if it was truly linked by this tagging page
                    if (in_array($taggingPageId, $existingTaggingPageIds)) {
                        $updatedTaggingPageIds = array_filter(
                            $existingTaggingPageIds, fn($id) => $id !== $taggingPageId)
                        ;

                        $blueprint = $linkedPage->blueprint(); // Get the blueprint object for the page
                        $fields = $blueprint->fields();        // Get all fields defined in the blueprint

                        // Check if the field exists within the blueprint's fields array
                        if (isset($fields[$taggedByField])) {


                            // Update the linked page with the modified list of IDs
                            try {
                                $linkedPage->update([
                                    $taggedByField => array_unique($updatedTaggingPageIds)
                                ]);
                                $log .= "$taggingPageId removed from $taggedByField on $linkedPageId";
                            } catch (Throwable $e) {
                                $isSyncing = false;
                                throw new KirbyRetrievalException(
                                    "Error removing $taggingPageId from $taggedByField on $linkedPageId: "
                                    . $e->getMessage()
                                );
                            }
                        } else {
                            $isSyncing = false;
                            throw new KirbyRetrievalException(
                                'Tag field '.$taggedByField.' has not been set up on linked page '
                                . $linkedPage->title()
                            );
                        }
                    }
                } else {
                    // Log or handle cases where a linked page from $removedLinkedPageIds is not found
                    $log.= "Warning: Linked page with ID '$linkedPageId' not found for tagging page '$taggingPageId'";
                }
            }
        }
        if (!$singlePage) {
            try {
                // Re-fetch the taggingPage to ensure it's the latest version
                $taggingPage = kirby()->page($taggingPage->id());
                $taggingPage->update([
                    'lastTagSync' => date('Y-m-d H:i:s') // Use a format suitable for the datetime field
                ]);
            } catch (Throwable $e) {
                $isSyncing = false;
                throw new KirbyRetrievalException(
                    'Error updating lastTagSync on ' . $taggingPage->title() . ': ' . $e->getMessage()
                );
            }
        }
        $isSyncing = false;
        return $log;
    }

    /**
     * @param int $limit
     * @param Collection $pages
     * @return Collection
     * @noinspection PhpUnused
     */
    public function applyLimit(Collection $pages, int $limit): Collection
    {
        return $this->collectionFilterService->applyLimit($pages, $limit);
    }


    #endregion

    #region LANGUAGES

    /**
     * @return Languages
     * @throws KirbyRetrievalException
     */
    protected function getLanguages() : Languages {

        $defaultLanguage = $this->kirby->defaultLanguage();
        $languages = new Languages();
        if ($defaultLanguage !== null) {
            $languages->setUsingDefaultLanguage($this->kirby->language()->code() === $defaultLanguage->code());
            $languages->setEnabled(true);
            $languages->setCurrentLanguage($this->kirby->language()->name());
            $languagesFromKirby = $this->kirby->languages();

            foreach ($languagesFromKirby as $lang) {
                try {
                    $translatedPage = $this->page->translation($lang->code());
                } catch (Throwable $e) {
                    throw new KirbyRetrievalException('The language could not be found: '.$e->getMessage());
                }

                if ($translatedPage->exists()) {
                    $language = new Language();
                    $language->setIsDefault($lang->code() === $defaultLanguage->code());
                    if (!$language->isDefault()) {
                        $languages->setIsPageTranslatedInCurrentLanguage(true);
                    }
                    $language->setIsActivePage($lang->code() === kirby()->language()->code());
                    $language->setCode($lang->code());
                    $language->setName($lang->name());
                    /** @var Page $pageModel */
                    $pageModel = $translatedPage->model();
                    $language->setCurrentPageUrl($pageModel->url($lang->code()));
                    $languages->addLanguage($language);
                }

            }
        }
        return $languages;
    }
    #endregion

    #region CACHING
    /**
     * @param Page $page
     * @return string
     * @noinspection PhpUnused
     */
    public function handleCaches(Page $page) : string {
        $cacheName = option('cacheName');
        try {
            $cache = $this->kirby->cache($cacheName);
        } catch (Throwable) {
            return 'Failed to get cache';
        }
        $cacheMapping = option('cacheMapping');
        if ($cacheMapping) {
            if (array_key_exists($page->template()->name(), $cacheMapping)) {
                $cacheKeys = $cacheMapping[$page->template()->name()];
                $cacheKeysAsArray = Str::split($cacheKeys);
                foreach ($cacheKeysAsArray as $cacheKey) {
                    $cache->remove($cacheKey);
                }

            }
        }
        if ($searchCacheKeys = option('searchCacheKeys')) {
            $searchCacheKeysAsArray = Str::split($searchCacheKeys);
            foreach ($searchCacheKeysAsArray as $searchCacheKey) {
                $cache->remove($searchCacheKey);
            }
        }
        return 'Success';
    }

    /**
     * Determines if a page should be cached (by Kirby and CDN).
     * Pages with role restrictions or password protection should not be cached.
     *
     * @param Page $page The page to check
     * @return bool True if the page can be cached, false if it should not be cached
     */
    public function isPageCacheable(Page $page): bool
    {
        $excludedTemplates = option('excludeTemplatesFromCache', []);

        // Merge with hardcoded non-cacheable templates
        $allExcludedTemplates = array_merge($excludedTemplates, [
            'file_archive',
            'file_link',
            'form_submission',
            'image_bank',
            'page_link',
            'login',
            'reset_password',
            'reset_password_verification',
            'search_log'
        ]);

        // Don't cache login/auth related pages or config-excluded templates
        if (in_array($page->template()->name(), $allExcludedTemplates)) {
            return false;
        }

        // Don't cache an actively-filtered listing. Filter selections are
        // persisted in per-user 'flt_'-prefixed cookies (see setFilterCookie /
        // getFilterCookieAsString), but the page cache is keyed on URL only.
        // Caching a filtered render would serve it to other visitors and would
        // survive the visitor clearing the filter (#603), so any request that
        // carries an active filter cookie is rendered fresh and marked private.
        if ($this->getFilterCookiePolicy()->requestCarriesActiveFilter($_COOKIE)) {
            return false;
        }

        try {
            // Don't cache if site requires roles (whole site is members-only)
            $siteRoles = $this->getSiteFieldAsString('requiredRoles');
            if (!empty($siteRoles)) {
                return false;
            }

            // Don't cache pages with password protection
            $password = $this->getPageFieldAsString($page, 'password');
            if (!empty($password)) {
                return false;
            }

            // Don't cache if page or any ancestor has requiredRoles
            $checkPage = $page;
            while ($checkPage) {
                $requiredRoles = $this->getPageFieldAsArray($checkPage, 'requiredRoles');
                if (!empty($requiredRoles)) {
                    return false;
                }
                $checkPage = $checkPage->parent();
            }
        } catch (KirbyRetrievalException) {
            // If we can't determine cacheability, err on the side of caution
            return false;
        }

        return true;
    }

    /**
     * Sets appropriate HTTP cache headers based on page cacheability.
     * For protected pages: prevents caching by CDN and browsers.
     * For public pages: allows CDN and browser caching with configurable TTLs.
     *
     * @param Page $page The page to set cache headers for
     * @param int $cdnMaxAge Max age in seconds for CDN caching (default 1 hour)
     * @param int $browserMaxAge Max age in seconds for browser caching (default 5 minutes)
     * @return Responder
     */
    public function setCacheHeaders(Page $page, int $cdnMaxAge = 3600, int $browserMaxAge = 300): Responder
    {
        $responder = kirby()->response();

        if ($this->isPageCacheable($page)) {
            return $responder->header('Cache-Control', "public, s-maxage=$cdnMaxAge, max-age=$browserMaxAge");
        }

        return $responder->headers([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0'
        ]);
    }
    #endregion

    #region REDIRECTS

    /**
     * @param Page $page
     * @param string $fieldName
     * @return void
     */
    /**
     * Send the user to (or serve) the file held in a page field.
     *
     * By default this issues an HTTP redirect to the file's media URL, so the file
     * is served statically by the web server / CDN and the address bar changes.
     * When $stream is true the file's bytes are served in-place instead, so the
     * current page URL is retained; $forceDownload then controls whether the file
     * is shown inline (e.g. a PDF opens in the browser) or sent as a download.
     *
     * @param Page $page The page holding the file field
     * @param string $fieldName The file field name
     * @param bool $stream When true, serve the file in-place (keep the page URL)
     *                      instead of redirecting to its media URL
     * @param bool $forceDownload When streaming, send the file as a download
     *                            (attachment) rather than serving it inline
     * @return void
     * @throws Exception If a forced download is requested but the file is missing
     */
    public function redirectToFile(
        Page $page,
        string $fieldName = 'file',
        bool $stream = false,
        bool $forceDownload = false
    ): void {
        if ($stream) {
            $file = $this->getPageFieldAsFile($page, $fieldName);
            if ($file !== null) {
                echo $this->fileDeliveryService
                    ->buildResponse($file->root(), $file->filename(), $forceDownload)
                    ->send();
                exit;
            }
            go('error');
            return;
        }

        $document = $this->getPageFieldAsDocument($page, $fieldName);
        if ($document->hasUrl()) {
            go($document->getURL());
        } else {
            go('error');
        }
    }

    /**
     * @param Page $page
     * @return void
     * @throws KirbyRetrievalException
     */
    public function redirectToPage(Page $page):void {
        $redirectLink = $this->getPageFieldAsUrl($page,'redirect_link', true);
        go($redirectLink );
    }


    /**
     * looks for a page with the title 'login' in the site root
     * @param string $redirectPage
     * @return void
     */
    protected function redirectToLogin(string $redirectPage=''): void
    {
        $loginPage = $this->findKirbyPage('login');
        $url = $loginPage->url();

        if (!empty($redirectPage)) {
            $url .= '?' . http_build_query(['redirectPage' => $redirectPage]);
        }
        go($url);
    }


    /**
     * @return void
     */
    protected function redirectToHome(): void
    {
        $homePage = $this->findKirbyPage('home');
        $homePage->go();
    }

    #endregion

    #region FORMS

    /**
     * Processes a POST form submission, stores it as a form_submission child page,
     * and optionally sends an email notification to the configured recipient.
     *
     * @param Page   $parentPage The page under which the submission child page is created
     * @param string $formType   Short identifier for the form type (e.g. 'training_feedback').
     *                           Stored on the submission for filtering and export.
     *                           Pass an empty string for generic/untyped forms.
     * @return ActionStatus
     * @throws KirbyRetrievalException
     */
    protected function createFormSubmission(Page $parentPage, string $formType = ''): ActionStatus
    {
        if ($this->kirby->request()->is('POST')) {
            if (csrf(get('csrf')) === true) {
                $excludeFields = ['submit', 'csrf'];
                $formSubmission = [];
                $formData = $this->kirby->request()->data();

                foreach ($formData as $inputName => $inputValue) {

                    if (in_array($inputName, $excludeFields) || empty($inputName)) {
                        continue;
                    }
                    $spacedString = str_replace(['-', '_'], ' ', $inputName);
                    $questionTitle = ucwords($spacedString);

                    $formSubmission[] = [
                        'question' => $questionTitle,
                        'answer' => is_array($inputValue) ? implode(', ', $inputValue) : $inputValue,
                    ];
                }

                $slug = date('M-j-H.i.s');

                if ($parentPage->findPageOrDraft($slug)) {
                    $counter = 1;
                    while ($parentPage->findPageOrDraft($slug . '-' . $counter)) {
                        $counter++;
                    }
                    $slug = $slug . '-' . $counter;
                }

                $content = [
                    'title'      => 'Submission: ' . $slug,
                    'submission' => Data::encode($formSubmission, 'yaml'),
                ];

                if ($formType !== '') {
                    $content['form_type'] = $formType;
                }

                $this->createPage($parentPage,
                    [
                        'slug'     => $slug,
                        'template' => 'form_submission',
                        'content'  => $content,
                    ],
                    true
                );

                if ($this->isPageFieldNotEmpty($parentPage, 'emailRecepient')) {
                    try {
                        $htmlBody = '
    <ul style="list-style: none; padding: 0;">
';

                        foreach ($formSubmission as $item) {
                            $htmlBody .= '
        <li style="margin-bottom: 15px;">
            <strong style="display: block; font-size: 14px; color: #333;">' . htmlspecialchars(trim($item['question'])) . '</strong>
            <span style="display: block; font-size: 16px; color: #555;">' . nl2br(htmlspecialchars(trim((string) $item['answer']))) . '</span>
        </li>
    ';
                        }

                        $htmlBody .= '
    </ul>
';

                        $emailRecipient = $this->getPageFieldAsString($parentPage, 'emailRecepient');
                        $this->sendEmail(
                            'form-notification',
                            option('defaultEmail'),
                            option('defaultEmail'),
                            $emailRecipient,
                            'Form submission: ' . $this->getPageTitle($parentPage),
                            [
                                'content' => $htmlBody,
                            ]
                        );
                    } catch (KirbyRetrievalException $e) {
                        $this->writeToErrorLog($e->getMessage());
                    }
                }
            } else {
                return (new ActionStatus(false,'', 'Your security token has expired.  Please try again.'));
            }

            return (new ActionStatus(
                true,
                '',
                'Thank you, your submission has been received.')
            );
        }
        return (new ActionStatus(true));
    }

    /**
     * @return string
     * @throws KirbyRetrievalException
     */
    protected function getTurnstileSiteKey(): string {
        $turnstileSiteKey = (string) option('turnstile.siteKey');

        if (empty($turnstileSiteKey)) {
            throw new KirbyRetrievalException('The Turnstile site key for Uniform is not configured');
        }
        return $turnstileSiteKey;
    }

    /**
     * Verifies the Turnstile challenge token on the current request.
     *
     * A missing or rejected token is an everyday event (bots POSTing the form
     * directly, or a user whose Turnstile widget failed to load), so it is
     * returned as a failed ActionStatus for the form to display — only a
     * missing secret key (a configuration error) throws.
     *
     * @return ActionStatus success, or failure with a user-facing friendly message
     * @throws KirbyRetrievalException if the Turnstile secret key is not configured
     */
    protected function verifyTurnstile(): ActionStatus {
        $secretKey = option('turnstile.secretKey');
        $challenge = $this->kirby->request()->get(TurnstileVerifier::FIELD_NAME);

        $verifier = new TurnstileVerifier(is_string($secretKey) ? $secretKey : null);

        return $verifier->verify(is_string($challenge) ? $challenge : null);
    }

    /**
     * Verifies the Turnstile challenge token, throwing on any failure.
     *
     * Prefer verifyTurnstile(), which lets the form report the failure to the
     * user instead of surfacing an exception page.
     *
     * @throws KirbyRetrievalException
     */
    protected function getTurnstileResponse(): void {
        $status = $this->verifyTurnstile();

        if (!$status->getStatus()) {
            throw new KirbyRetrievalException($status->getFirstErrorMessage());
        }
    }


    #endregion

    #region MISC

    /**
     * @param string $fullClassName
     * @return string
     */
    private function extractClassName(string $fullClassName ): string {
        $afterLastBackslash = strrchr($fullClassName, '\\');
        if ($afterLastBackslash === false) {
            // No backslash found, the string is the content
            return $fullClassName;
        } else {
            // Remove the leading backslash
            return substr($afterLastBackslash, 1);
        }
    }


    /**
     * @param string $optionKey
     * @return string
     * @noinspection PhpUnused
     */
    public function getOption(string $optionKey): string
    {
        $optionValue = $this->kirby->option($optionKey);
        return $this->asString($optionValue);
    }

    /**
     * @param Page $page
     * @param string $sectionName
     * @return array
     */
    protected function getFieldsInSection(Page $page, string $sectionName): array
    {

        $blueprint = $page->blueprint();

        $fieldNamesInSection = [];

        try {
            $sections = $blueprint->sections();

            if (isset($sections[$sectionName])) {
                $section = $sections[$sectionName];
            } else if (isset($sections[$sectionName . '-fields'])) {
                $section = $sections[$sectionName . '-fields'];
            }
            if (isset($section) && $section->fields()) {

                foreach ($section->fields() as $fieldName => $fieldDefinition) {
                    $customTag = $fieldDefinition['customTag'] ?? false;
                    if ($fieldDefinition['type'] !== 'info' && !$customTag) {
                        $fieldNamesInSection[] = ['name' => $fieldName, 'label' => $fieldDefinition['label']];
                    }
                }
            }
        } catch (Throwable) {
            // carry on if issue with blueprint
        }
        return $fieldNamesInSection;

    }


    /**
     * @param mixed $value
     * @param string $fallback
     * @return string
     */
    protected function asString(mixed $value, string $fallback = ''): string
    {
        if (is_string($value)) {
            return $value;
        } else if (is_array($value)) {
            return implode(', ', $value);
        } elseif (is_null($value)) {
            return $fallback;
        } else {
            return (string) $value;
        }
    }


    /**
     * Get the default fallback datetime
     * where a date is required, but the overall
     * operation has failed.
     * @return DateTime
     * @noinspection PhpUnused
     */
    protected function getFallBackDateTime(): DateTime
    {
        return new DateTime('1970-01-01 00:00:00');
    }

    /**
     * @return void
     * @noinspection PhpUnused
     */
    protected function logCurrentTime(): void
    {
        $microtime = microtime(true);
        $milliseconds = sprintf("%03d", ($microtime - floor($microtime)) * 1000);
        $timestamp = (new DateTime())->setTimestamp((int)$microtime)->format("Y-m-d H:i:s");
        echo "[$timestamp.$milliseconds]<br>";
    }

    /**
     * @return string
     * @throws InvalidArgumentException
     * @throws DateMalformedStringException
     * @throws Throwable
     */
    public function publishScheduledPages(): string
    {
        try {

            if ($this->isSiteFieldNotEmpty('scheduled')) {
                $scheduledEntries = $this->getSiteFieldAsStructure('scheduled');
                $updatedList = [];
                $publishedCount = 0;

                $this->kirby->impersonate('kirby');

                foreach ($scheduledEntries as $entry) {
                    // Get the collection of Page objects from the field
                    /** @noinspection PhpUndefinedMethodInspection */
                    $pages = $entry->page()->toPages();

                    // If there's at least one page selected
                    if ($pages->isNotEmpty()) {
                        /** @noinspection PhpUndefinedMethodInspection */
                        $scheduledDate = $entry->scheduledPublishDate()->value();
                        /** @noinspection PhpUndefinedMethodInspection */
                        $scheduledTime = $entry->scheduledPublishTime()->value();

                        // If a date and time exist for the entry
                        if ($scheduledDate && $scheduledTime) {
                            $timezone = new DateTimeZone('Europe/London');
                            $scheduledDateTime = new DateTime(
                                $scheduledDate . ' ' . $scheduledTime,
                                $timezone
                            );
                            $currentDateTime = new DateTime('now', $timezone);

                            // The comparison should now work reliably
                            if ($currentDateTime >= $scheduledDateTime) {
                                // Loop through each selected page
                                foreach ($pages as $page) {
                                    try {
                                        if ($page) {
                                            if ($page->isListed()) {
                                                $this->writeToLog(
                                                    'scheduledPublish',
                                                    'Page already published: ' . $page->title()
                                                    . ' at ' . $currentDateTime->format('Y-m-d H:i:s') . PHP_EOL
                                                );
                                                // Continue to the next page in the loop
                                                continue;
                                            }
                                            // If not already listed, change the status
                                            $page->changeStatus('listed');
                                            $this->writeToLog('scheduledPublish',
                                                'Published ' . $page->title() . ' at '
                                                . $currentDateTime->format('Y-m-d H:i:s') . PHP_EOL
                                            );
                                            $publishedCount++;
                                        }
                                    } catch (\Exception $e) {
                                        $this->writeToLog('scheduledPublish',
                                            'Error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString() . PHP_EOL);
                                        return 'Error:' . $e->getMessage() . ' - ' . $e->getTraceAsString() . PHP_EOL;
                                    }
                                }
                            } else {
                                // Keep the entry in the list if it's not ready to be published
                                $updatedList[] = $entry->content()->toArray();
                            }
                        }
                    }
                }

                // Encode and save the new, updated list back to the site file
                $this->site->update([
                    'scheduled' => Yaml::encode($updatedList),
                ]);

                return 'Scheduled pages processed. Published ' . $publishedCount . ' pages.';
            }
            return '';
        } catch (Throwable $e) {
            return 'Error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString() . PHP_EOL;
        }
    }

    /**
     * Adds an OnThisPageLink for each category returned by groupByCategory(),
     * in the order they appear. Intended for pages that render grouped content
     * with per-category headings (e.g. policies, vacancies).
     *
     * @param BaseWebPage $webPage Page to add links to
     * @param BaseList $list List whose groupByCategory() drives the links
     * @param string $headingLevel HTML heading level used in the snippet (e.g. 'h2', 'h3')
     * @param string $uncategorisedLabel Label for items with no category (default 'Other')
     */
    protected function addGroupedCategoryOnPageLinks(
        BaseWebPage $webPage,
        BaseList $list,
        string $headingLevel = 'h2',
        string $uncategorisedLabel = 'Other'
    ): void {
        foreach (array_keys($list->groupByCategory($uncategorisedLabel)) as $category) {
            $webPage->addOnPageLinkAfterMain(
                new OnThisPageLink($category, Str::slug($category), $headingLevel, 'main')
            );
        }
    }

    #endregion

    #region FORMS

    /**
     * Stores a success message in the Kirby session so it can be displayed on
     * the next page load (e.g. after a post-submission redirect).
     *
     * @param string $message The friendly message to carry across the redirect.
     */
    protected function storeFormFlashMessage(string $message): void
    {
        kirby()->session()->set('form_flash_message', $message);
    }

    /**
     * Reads any pending form flash message from the session, applies it to the
     * page model as a friendly message, then immediately removes it so it only
     * appears once.
     *
     * Called from getSpecificPage() on every page load so any destination page
     * (not just form pages) can display the confirmation banner.
     *
     * @param BaseWebPage $page The page model to apply the message to.
     */
    protected function applyPendingFormFlash(BaseWebPage $page): void
    {
        $message = kirby()->session()->get('form_flash_message');
        if (is_string($message) && $message !== '') {
            $page->addFriendlyMessage($message);
            kirby()->session()->remove('form_flash_message');
        }
    }

    /**
     * Populates a FormPage model with resolved fields and custom blocks from
     * the given BaseFormDefinition.  Call this from your form-specific setter
     * before delegating to setFormPage():
     *
     *   protected function setMyFormPage(Page $page, MyFormPage $myFormPage): MyFormPage
     *   {
     *       $definition = new MyFormDefinition();
     *       $this->populateFormPage($page, $myFormPage, $definition);
     *       $this->setFormPage($page, $myFormPage, $definition->getFormType());
     *       return $myFormPage;
     *   }
     *
     * @param Page               $page       Kirby page that holds panel override values
     * @param FormPageInterface  $formPage   The form page model to populate
     * @param BaseFormDefinition $definition The form definition to resolve against the page
     */
    protected function populateFormPage(
        Page $page,
        FormPageInterface $formPage,
        BaseFormDefinition $definition
    ): void {
        $formPage->setFormFields($definition->getFields($page));
        $formPage->setFormFieldGroups($definition->getFieldGroups($page));

        if ($this->isPageFieldNotEmpty($page, 'customFormElements')) {
            $formPage->setCustomFormBlocks($this->getPageFieldAsBlocks($page, 'customFormElements'));
        }
    }

    #endregion

    // ── Geo / postcode helpers ─────────────────────────────────────────────────

    /**
     * Looks up WGS84 lat/lng for a UK postcode (including Northern Ireland) via
     * the free postcodes.io API. Returns null if the postcode is not found or the
     * request fails.
     *
     * @param string $postcode UK postcode (e.g. "SW1A 1AA" or "BT1 1AA")
     * @return array{0: float, 1: float}|null [latitude, longitude] or null
     */
    protected function getLatLngFromPostcodesIO(string $postcode): ?array
    {
        $url = 'https://api.postcodes.io/postcodes/' . rawurlencode(trim($postcode));

        try {
            $response = \Kirby\Http\Remote::request($url, [
                'method'  => 'GET',
                'headers' => ['User-Agent' => 'BSBI Web/1.0'],
                'timeout' => 5,
            ]);
        } catch (\Throwable $e) {
            $this->writeToLogFile('errors', 'getLatLngFromPostcodesIO: request failed for ' . $postcode . ': ' . $e->getMessage());
            return null;
        }

        if ($response->code() !== 200) {
            $this->writeToLogFile('errors', 'getLatLngFromPostcodesIO: HTTP ' . $response->code() . ' for ' . $postcode);
            return null;
        }

        $rawContent = $response->content();
        if ($rawContent === null) {
            return null;
        }

        /** @var array<mixed>|null $data */
        $data = json_decode($rawContent, true);
        if (!is_array($data) || ($data['status'] ?? 0) !== 200) {
            return null;
        }

        $lat = $data['result']['latitude'] ?? null;
        $lon = $data['result']['longitude'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return [(float) $lat, (float) $lon];
    }
}
