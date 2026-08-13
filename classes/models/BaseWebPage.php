<?php

namespace BSBI\WebBase\models;

use BSBI\WebBase\traits\CoreWebPageProperties;

/**
 * Represents a web page
 *
 * @package BSBI\Web
 */
class BaseWebPage extends BaseModel
{
    use CoreWebPageProperties;

    /**
     * @var User
     */
    protected User $currentUser;

    /**
     * @var string[]
     */
    protected array $requiredUserRoles = [];

    /**
     * @var string
     */
    protected string $htmlTitle = '';

    /** @var string The public site name (og:site_name / homepage JSON-LD) */
    protected string $siteName = '';

    /** @var bool Whether this page is the site's homepage */
    protected bool $isHomePage = false;

    /** @var array<string, string> WebSite JSON-LD properties (homepage only; empty elsewhere) */
    protected array $websiteJsonLd = [];

    /**
     * @var string
     */
    protected string $openGraphTitle = '';

    /**
     * @var string
     */
    protected string $openGraphDescription = '';

    /**
     * @var string
     */
    protected string $openGraphImage = '';

    /**
     * @var string
     */
    protected string $authors = '';

    /**
     * @var Languages
     */
    protected Languages $languages;

    /**
     * @var WebPageBlocks
     */
    protected WebPageBlocks $mainContentBlocks;

    /**
     * @var WebPageBlocks
     */
    protected WebPageBlocks $lowerContentBlocks;

    /**
     * @var WebPageLinks
     */
    protected WebPageLinks $menuPages;


    /**
     * @var WebPageLinks
     */
    protected WebPageLinks $breadcrumb;

    /**
     * @var OnThisPageLinks
     */
    protected OnThisPageLinks $onThisPageLinks;

    /**
     * @var CoreLinks
     */
    protected CoreLinks $coreLinks;

    /**
     * @var string
     */
    protected string $colourMode;

    /**
     * @var string
     */
    protected string $query = '';

    /**
     * @var WebPageLinks
     */
    protected WebPageLinks $relatedContent;

    /**
     * @var WebPageTagLinks
     */
    private WebPageTagLinks $tagLinks;


    /**
     * @var string[]
     */
    protected array $attributes = [];

    /**
     * the custom scripts for the page
     * @var string[]
     */
    protected array $customScripts;

    /**
     * @var bool
     */
    protected bool $usingSimpleLinksForSubPages = false;

    /**
     * @var string
     */
    protected string $urlWithQueryString = '';

    private string $displayPageTitle;

    private string $displayPageTitleClass = '';

    private bool $requiresCookieConsent = false;

    protected bool $isCookieConsentGiven = false;

    protected bool $isCookieConsentRejected = false;

    private string $cookieConsentCSRFToken = '';

    private bool $isPasswordProtected = false;

    private string $passwordCSRFToken = '';

    /**
     * @var string
     */
    private string $cssFile = 'custom.css';


    /**
     * @param string $title
     * @param string $url
     * @param string $pageType
     */
    public function __construct(string $title, string $url, string $pageType)
    {
        $this->pageType = $pageType;
        $this->mainContentBlocks = new WebPageBlocks();
        $this->menuPages = new WebPageLinks();
        $this->subPages = new WebPageLinks();
        $this->breadcrumb = new WebPageLinks();
        $this->coreLinks = new CoreLinks();
        $this->customScripts = [];
        $this->languages = new Languages();
        parent::__construct($title, $url);
    }


    /**
     * Get the HTML document title (used in the <title> element)
     * @return string
     */
    public function getHtmlTitle(): string
    {
        return $this->htmlTitle !== '' ? $this->htmlTitle : $this->getTitle();
    }

    /**
     * Set the HTML document title (used in the <title> element)
     * @param string $htmlTitle
     * @return self
     */
    public function setHtmlTitle(string $htmlTitle): self
    {
        $this->htmlTitle = $htmlTitle;
        return $this;
    }

    /**
     * Get the public site name (feeds og:site_name and the homepage JSON-LD)
     * @return string
     */
    public function getSiteName(): string
    {
        return $this->siteName;
    }

    /**
     * Set the public site name
     * @param string $siteName
     * @return self
     */
    public function setSiteName(string $siteName): self
    {
        $this->siteName = $siteName;
        return $this;
    }

    /**
     * Is this page the site's homepage?
     * @return bool
     */
    public function isHomePage(): bool
    {
        return $this->isHomePage;
    }

    /**
     * Record whether this page is the site's homepage
     * @param bool $isHomePage
     * @return self
     */
    public function setIsHomePage(bool $isHomePage): self
    {
        $this->isHomePage = $isHomePage;
        return $this;
    }

    /**
     * Get the WebSite JSON-LD properties (empty except on the homepage)
     * @return array<string, string>
     */
    public function getWebsiteJsonLd(): array
    {
        return $this->websiteJsonLd;
    }

    /**
     * Set the WebSite JSON-LD properties
     * @param array<string, string> $websiteJsonLd
     * @return self
     */
    public function setWebsiteJsonLd(array $websiteJsonLd): self
    {
        $this->websiteJsonLd = $websiteJsonLd;
        return $this;
    }

    /**
     * @return string
     */
    public function getOpenGraphTitle(): string
    {
        return $this->openGraphTitle;
    }

    /**
     * @param string $title
     * @return $this
     */
    public function setOpenGraphTitle(string $title): self
    {
        $this->openGraphTitle = $title;
        return $this;
    }

    /**
     * @return string
     */
    public function getOpenGraphDescription(): string
    {
        return $this->openGraphDescription;
    }

    /**
     * @param string $description
     * @return $this
     */
    public function setOpenGraphDescription(string $description): self
    {
        $this->openGraphDescription = $description;
        return $this;
    }

    /**
     * @return string
     */
    public function getOpenGraphImage(): string
    {
        return $this->openGraphImage;
    }

    /**
     * @param string $imageUrl
     * @return $this
     */
    public function setOpenGraphImage(string $imageUrl): self
    {
        $this->openGraphImage = $imageUrl;
        return $this;
    }

    /**
     * @return string
     */
    public function getAuthors(): string
    {
        return $this->authors;
    }

    /**
     * @param string $authors
     * @return $this
     */
    public function setAuthors(string $authors): BaseWebPage
    {
        $this->authors = $authors;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasMainContent(): bool {
        return isset($this->mainContentBlocks) && $this->mainContentBlocks->count()>0;
    }



    /**
     * @return WebPageBlocks
     */
    public function getMainContent(): WebPageBlocks
    {
        return $this->mainContentBlocks;
    }

    /**
     * @param WebPageBlock $block
     * @return $this
     */
    public function addMainContentBlock(WebPageBlock $block): self
    {
        $this->mainContentBlocks->addListItem($block);
        return $this;
    }


    /**
     * @param WebPageBlocks $mainContentBlocks
     * @return $this
     */
    public function setMainContentBlocks(WebPageBlocks $mainContentBlocks): self
    {
        $this->mainContentBlocks = $mainContentBlocks;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasLowerContentBlocks(): bool
    {
        return isset($this->lowerContentBlocks) && $this->lowerContentBlocks->count()>0;
    }

    /**
     * @return WebPageBlocks
     */
    public function getLowerContentBlocks(): WebPageBlocks
    {
        return $this->lowerContentBlocks;
    }

    /**
     * @param WebPageBlocks $lowerContentBlocks
     * @return void
     */
    public function setLowerContentBlocks(WebPageBlocks $lowerContentBlocks): void
    {
        $this->lowerContentBlocks = $lowerContentBlocks;
    }

    /**
     * @param string $blockType
     * @return bool
     */
    public function hasBlockofType(string $blockType): bool {
        return $this->mainContentBlocks->hasBlockOfType($blockType);
    }

    public function hasBlockTypeStarting(string $blockTypeStart): bool {
        return $this->mainContentBlocks->hasBlockTypeStarting($blockTypeStart);
    }

    /**
     * @return bool
     */
    public function hasHeadingBlock(): bool {
        return $this->mainContentBlocks->hasBlockOfType('heading');
    }

    /**
     * @return bool
     */
    public function hasBreadcrumb(): bool
    {
        return (isset($this->breadcrumb) && $this->breadcrumb->hasListItems());
    }

    /**
     * @return WebPageLinks
     */
    public function getBreadcrumb(): WebPageLinks
    {
        return $this->breadcrumb;
    }

    /**
     * @param WebPageLinks $breadcrumb
     * @return $this
     */
    public function setBreadcrumb(WebPageLinks $breadcrumb): self
    {
        $this->breadcrumb = $breadcrumb;
        return $this;
    }

    /**
     * @return WebPageLinks
     */
    public function getMenuPages(): WebPageLinks
    {
        return $this->menuPages ?? new WebPageLinks();
    }

    /**
     * @param WebPageLinks $menuPages
     * @return $this
     */
    public function setMenuPages(WebPageLinks $menuPages): self
    {
        $this->menuPages = $menuPages;
        return $this;
    }

    public function hasOnThisPageLinks(): bool {
        return isset($this->onThisPageLinks) && $this->onThisPageLinks->count()>0;
    }

    /**
     * @return OnThisPageLinks
     */
    public function getOnThisPageLinks(): OnThisPageLinks
    {
        return $this->onThisPageLinks;
    }

    /**
     * @param OnThisPageLinks $onThisPageLinks
     * @return BaseWebPage
     */
    public function setOnThisPageLinks(OnThisPageLinks $onThisPageLinks): BaseWebPage
    {
        $this->onThisPageLinks = $onThisPageLinks;
        return $this;
    }

    public function addOnPageLink(OnThisPageLink $onPageLink): BaseWebPage
    {
        $this->onThisPageLinks->addListItem($onPageLink);
        return $this;
    }

    public function addOnPageLinkAfterMain(OnThisPageLink $onPageLink): BaseWebPage
    {
        $this->onThisPageLinks->addListItemAfterMain($onPageLink);
        return $this;
    }



    /**
     * @return CoreLinks
     */
    public function getCoreLinks(): CoreLinks
    {
        return $this->coreLinks;
    }


    /**
     * @param CoreLink $coreLink
     * @return $this
     */
    public function addCoreLink(CoreLink $coreLink): self {
        $this->coreLinks->addListItem($coreLink);
        return $this;
    }

    /**
     * @param CoreLinks $coreLinks
     * @return $this
     */
    public function setCoreLinks(CoreLinks $coreLinks): self
    {
        $this->coreLinks = $coreLinks;
        return $this;
    }

    /**
     * has any related links (tags or related content)
     * @return bool
     */
    public function hasRelatedLinks(): bool {
        return $this->hasRelatedContent()
            || $this->hasTagLinks();
    }

    /**
     * @param WebPageLink $relatedContent
     * @return $this
     */
    public function addRelatedContent(WebPageLink $relatedContent): self
    {
        $this->relatedContent->addListItem($relatedContent);
        return $this;
    }

    /**
     * @return bool
     */
    public function hasRelatedContent(): bool {
        return isset($this->relatedContent)&&$this->relatedContent->count()>0;
    }

    /**
     * @return $this
     */
    public function setRelatedContentList(WebPageLinks $relatedContentList): self {
        $this->relatedContent = $relatedContentList;
        return $this;
    }

    /**
     * @return WebPageLinks
     */
    public function getRelatedContentList(): WebPageLinks {
        return $this->relatedContent;
    }


    /**
     * @return bool
     */
    public function hasTagLinks(): bool {
        return isset($this->tagLinks) && $this->tagLinks->count()>0;
    }

    /**
     * @return WebPageTagLinks
     */
    public function getTagLinks(): WebPageTagLinks
    {
        return $this->tagLinks;
    }

    /**
     * @param WebPageTagLinks $tagLinks
     * @return $this
     */
    public function setTagLinks(WebPageTagLinks $tagLinks): self
    {
        $this->tagLinks = $tagLinks;
        return $this;
    }

    /**
     * @return Languages
     */
    public function getLanguages(): Languages
    {
        return $this->languages;
    }

    /**
     * @param Languages $languages
     * @return $this
     */
    public function setLanguages(Languages $languages): self
    {
        $this->languages = $languages;
        return $this;
    }


    /**
     * @return string
     */
    public function getColourMode(): string
    {
        return $this->colourMode ?? 'light';
    }

    /**
     * @param string $colourMode
     * @return $this
     */
    public function setColourMode(string $colourMode): self
    {
        $this->colourMode = $colourMode;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasQuery(): bool
    {
        return !empty($this->query);
    }

    /**
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @param string $query
     * @return $this
     */
    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }


    /**
     * add a script to the page
     * @param string $script
     * @return self
     */
    public function addScript(string $script, string $path = '/assets/js/', $fileType='.js'): self
    {
        $fullPath = $path . $script . $fileType;
        if (!in_array($fullPath, $this->customScripts)) {
            $this->customScripts[] = $fullPath;
        }
        return $this;
    }

    /**
     * get all scripts for the page
     * @return string[]
     */
    public function getScripts(): array
    {
        return $this->customScripts;
    }

    /**
     * @return bool
     */
    public function hasCurrentUser(): bool {
        return isset($this->currentUser) && $this->currentUser->isLoggedIn();
    }

    /**
     * @return User
     */
    public function getCurrentUser(): User
    {
        return $this->currentUser;
    }

    /**
     * @param User $user
     * @return self
     */
    public function setCurrentUser(User $user): self
    {
        $this->currentUser = $user;
        return $this;
    }

    /**
     * @return string
     */
    public function getCurrentUserRole(): string
    {
        return $this->currentUser->getRole();
    }

    /**
     * @return string[]
     */
    public function getRequiredUserRoles(): array
    {
        return $this->requiredUserRoles;
    }

    /**
     * @param string[] $requiredUserRoles
     * @return void
     */
    public function setRequiredUserRoles(array $requiredUserRoles): void
    {
        $this->requiredUserRoles = $requiredUserRoles;
    }

    /**
     * @return bool
     */
    public function checkUserAgainstRequiredRoles(): bool
    {
        return ($this->checkRoleAgainstRequiredRoles());
    }

    /**
     * @return bool
     */
    public function checkRoleAgainstRequiredRoles(): bool
    {
        if (count($this->requiredUserRoles) === 0) {
            return true;
        }
        return (in_array($this->currentUser->getRole(), $this->requiredUserRoles, true));
    }

    /**
     * @return bool
     */
    public function isAdminEditor(): bool {
        $currentRole = $this->currentUser->getRole();
        return ($currentRole === 'admin' || $currentRole === 'editor');
    }

    /**
     * @param string $role
     * @return bool
     */
    public function hasRoleOrIsAdminEditor(string $role): bool {
        $currentRole = $this->currentUser->getRole();
        return ($currentRole === $role || $currentRole === 'admin' || $currentRole === 'editor');
    }

    /**
     * @param string $role
     * @return bool
     */
    public function hasRolesOrIsAdminEditor(array $roles): bool {
        $currentRole = $this->currentUser->getRole();
        return (in_array($currentRole,$roles)|| $currentRole === 'admin' || $currentRole === 'editor');
    }

    /**
     * @return string[]
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param string[] $attributes
     * @return self
     */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * @param string $attributeType
     * @return bool
     */
    public function hasAttribute(string $attributeType): bool
    {
        return in_array($attributeType, $this->attributes, true);
    }

    /**
     * @return bool
     */
    public function isUsingSimpleLinksForSubPages(): bool
    {
        return $this->usingSimpleLinksForSubPages;
    }

    /**
     * @return string
     */
    public function getUrlWithQueryString(): string
    {
        return $this->urlWithQueryString;
    }

    /**
     * @param string $urlWithQueryString
     * @return $this
     */
    public function setUrlWithQueryString(string $urlWithQueryString): BaseWebPage
    {
        $this->urlWithQueryString = $urlWithQueryString;
        return $this;
    }


    /**
     * @param string $queryString
     * @return string
     */
    public function addQueryString(string $queryString): string {
        $separator = str_contains($this->urlWithQueryString, '?') ? '&' : '?';
        return $this->urlWithQueryString . $separator . $queryString;
    }

    /**
     * override to stop KirbyBaseHelper getting subPages
     * @return bool
     */
    public function doSimpleGetSubPages(): bool {
        return true;
    }

    /**
     * Override to false for pages whose sidebar only shows direct children
     * (e.g. content-page.php / sub-pages-menu.php). Prevents loading all
     * grandchild pages just to satisfy sub-pages-panel.php, which those
     * pages never use.
     * @return bool
     */
    public function doesLoadSubPageGrandchildren(): bool {
        return true;
    }

    /**
     * @return string
     */
    public function getDisplayPageTitle(): string
    {
        return $this->displayPageTitle ?? $this->title;
    }

    /**
     * @param string $displayPageTitle
     * @return BaseWebPage
     */
    public function setDisplayPageTitle(string $displayPageTitle): BaseWebPage
    {
        $this->displayPageTitle = $displayPageTitle;
        return $this;
    }

    /**
     * @return string
     */
    public function getDisplayPageTitleClass(): string
    {
        return $this->displayPageTitleClass;
    }

    public function doesNotRequireCookieConsent(): bool
    {
        return !($this->requiresCookieConsent);
    }


    public function doesRequireCookieConsent(): bool
    {
        return $this->requiresCookieConsent;
    }

    public function setRequiresCookieConsent(bool $requiresCookieConsent): void
    {
        $this->requiresCookieConsent = $requiresCookieConsent;
    }



    /**
     * @param string $displayPageTitleClass
     * @return BaseWebPage
     */
    public function setDisplayPageTitleClass(string $displayPageTitleClass): BaseWebPage
    {
        $this->displayPageTitleClass = $displayPageTitleClass;
        return $this;
    }

    /**
     * @return bool
     */
    public function isCookieConsentGiven(): bool
    {
        return $this->isCookieConsentGiven;
    }

    /**
     * @param bool $isCookieConsentGiven
     * @return static
     */
    public function setIsCookieConsentGiven(bool $isCookieConsentGiven): static
    {
        $this->isCookieConsentGiven = $isCookieConsentGiven;
        return $this;
    }

    /**
     * @return bool
     */
    public function isCookieConsentRejected(): bool
    {
        return $this->isCookieConsentRejected;
    }

    /**
     * @param bool $isCookieConsentRejected
     * @return static
     */
    public function setIsCookieConsentRejected(bool $isCookieConsentRejected): static
    {
        $this->isCookieConsentRejected = $isCookieConsentRejected;
        return $this;
    }

    /**
     * @return string
     */
    public function getCookieConsentCSRFToken(): string
    {
        return $this->cookieConsentCSRFToken;
    }

    /**
     * @param string $cookieConsentCSRFToken
     * @return static
     */
    public function setCookieConsentCSRFToken(string $cookieConsentCSRFToken): static
    {
        $this->cookieConsentCSRFToken = $cookieConsentCSRFToken;
        return $this;
    }

    /**
     * @param bool $isPasswordProtected
     * @return $this
     */
    public function setIsPasswordProtected(bool $isPasswordProtected): static
    {
        $this->isPasswordProtected = $isPasswordProtected;
        return $this;
    }

    public function isPasswordProtected(): bool {
        return ($this->isPasswordProtected);
    }

    /**
     * @return string
     */
    public function getPasswordCSRFToken(): string
    {
        return $this->passwordCSRFToken;
    }

    /**
     * @param string $passwordCSRFToken
     * @return BaseWebPage
     */
    public function setPasswordCSRFToken(string $passwordCSRFToken): BaseWebPage
    {
        $this->passwordCSRFToken = $passwordCSRFToken;
        return $this;
    }

    /**
     * Returns the CSS filename to use for this page (relative to /assets/css/).
     * Defaults to 'custom.css'; override per page or course for alternative design systems.
     *
     * @return string
     */
    public function getCssFile(): string
    {
        return $this->cssFile;
    }

    /**
     * @param string $cssFile
     * @return BaseWebPage
     */
    public function setCssFile(string $cssFile): BaseWebPage
    {
        $this->cssFile = $cssFile;
        return $this;
    }


}