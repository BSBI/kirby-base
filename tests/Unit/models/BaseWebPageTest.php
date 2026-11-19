<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\models;

use BSBI\WebBase\models\BaseWebPage;
use BSBI\WebBase\models\CoreLinks;
use BSBI\WebBase\models\OnThisPageLink;
use BSBI\WebBase\models\OnThisPageLinks;
use BSBI\WebBase\models\User;
use BSBI\WebBase\models\WebPageBlock;
use BSBI\WebBase\models\WebPageBlocks;
use BSBI\WebBase\models\WebPageLink;
use BSBI\WebBase\models\WebPageLinks;
use PHPUnit\Framework\TestCase;

final class BaseWebPageTest extends TestCase
{
    private function createPage(
        string $title = 'Test Page',
        string $url = '/test',
        string $pageType = 'default'
    ): BaseWebPage {
        return new BaseWebPage($title, $url, $pageType);
    }

    private function createUser(string $userId = 'user1', string $userName = 'Test User', string $role = 'viewer'): User
    {
        $user = new User('');
        $user->setUserId($userId);
        $user->setUserName($userName);
        $user->setRole($role);
        return $user;
    }

    public function testConstructorSetsProperties(): void
    {
        $page = $this->createPage('Home', '/home', 'homepage');

        $this->assertSame('Home', $page->getTitle());
        $this->assertSame('/home', $page->getUrl());
        $this->assertSame('homepage', $page->getPageType());
        $this->assertTrue($page->getStatus());
    }

    public function testConstructorInitialisesCollections(): void
    {
        $page = $this->createPage();

        $this->assertInstanceOf(WebPageBlocks::class, $page->getMainContent());
        $this->assertInstanceOf(WebPageLinks::class, $page->getMenuPages());
        $this->assertInstanceOf(WebPageLinks::class, $page->getSubPages());
        $this->assertInstanceOf(WebPageLinks::class, $page->getBreadcrumb());
        $this->assertInstanceOf(CoreLinks::class, $page->getCoreLinks());
        $this->assertSame([], $page->getScripts());
    }

    // --- Main content ---

    public function testHasMainContentReturnsFalseWhenEmpty(): void
    {
        $page = $this->createPage();
        $this->assertFalse($page->hasMainContent());
    }

    public function testHasMainContentReturnsTrueWhenBlockAdded(): void
    {
        $page = $this->createPage();
        $page->addMainContentBlock(new WebPageBlock('text', '<p>Hello</p>'));
        $this->assertTrue($page->hasMainContent());
    }

    public function testHasBlockOfType(): void
    {
        $page = $this->createPage();
        $page->addMainContentBlock(new WebPageBlock('heading', '<h2>Title</h2>'));
        $page->addMainContentBlock(new WebPageBlock('text', '<p>Body</p>'));

        $this->assertTrue($page->hasBlockofType('heading'));
        $this->assertTrue($page->hasHeadingBlock());
        $this->assertFalse($page->hasBlockofType('gallery'));
    }

    public function testHasBlockTypeStarting(): void
    {
        $page = $this->createPage();
        $page->addMainContentBlock(new WebPageBlock('table_simple', '<table></table>'));

        $this->assertTrue($page->hasBlockTypeStarting('table'));
        $this->assertFalse($page->hasBlockTypeStarting('image'));
    }

    // --- Breadcrumb ---

    public function testHasBreadcrumbFalseWhenEmpty(): void
    {
        $page = $this->createPage();
        $this->assertFalse($page->hasBreadcrumb());
    }

    public function testBreadcrumbRoundTrip(): void
    {
        $page = $this->createPage();
        $breadcrumb = new WebPageLinks();
        $breadcrumb->addListItem(new WebPageLink('Home', '/', 'home', 'default'));
        $page->setBreadcrumb($breadcrumb);

        $this->assertTrue($page->hasBreadcrumb());
        $this->assertSame(1, $page->getBreadcrumb()->count());
    }

    // --- Scripts ---

    public function testAddScriptBuildsPath(): void
    {
        $page = $this->createPage();
        $page->addScript('app');

        $this->assertSame(['/assets/js/app.js'], $page->getScripts());
    }

    public function testAddScriptCustomPathAndExtension(): void
    {
        $page = $this->createPage();
        $page->addScript('styles', '/assets/css/', '.css');

        $this->assertSame(['/assets/css/styles.css'], $page->getScripts());
    }

    public function testAddScriptDeduplicates(): void
    {
        $page = $this->createPage();
        $page->addScript('app');
        $page->addScript('app');

        $this->assertCount(1, $page->getScripts());
    }

    // --- User roles ---

    public function testCheckRoleAgainstRequiredRolesPassesWhenNoRolesRequired(): void
    {
        $page = $this->createPage();
        $page->setCurrentUser($this->createUser());

        $this->assertTrue($page->checkRoleAgainstRequiredRoles());
    }

    public function testCheckRoleAgainstRequiredRolesPassesWhenRoleMatches(): void
    {
        $page = $this->createPage();
        $page->setCurrentUser($this->createUser(role: 'member'));
        $page->setRequiredUserRoles(['member', 'admin']);

        $this->assertTrue($page->checkRoleAgainstRequiredRoles());
        $this->assertTrue($page->checkUserAgainstRequiredRoles());
    }

    public function testCheckRoleAgainstRequiredRolesFailsWhenRoleMismatches(): void
    {
        $page = $this->createPage();
        $page->setCurrentUser($this->createUser(role: 'guest'));
        $page->setRequiredUserRoles(['member', 'admin']);

        $this->assertFalse($page->checkRoleAgainstRequiredRoles());
    }

    public function testIsAdminEditor(): void
    {
        $page = $this->createPage();

        $page->setCurrentUser($this->createUser(role: 'admin'));
        $this->assertTrue($page->isAdminEditor());

        $page->setCurrentUser($this->createUser(role: 'editor'));
        $this->assertTrue($page->isAdminEditor());

        $page->setCurrentUser($this->createUser(role: 'viewer'));
        $this->assertFalse($page->isAdminEditor());
    }

    public function testHasRoleOrIsAdminEditor(): void
    {
        $page = $this->createPage();

        $page->setCurrentUser($this->createUser(role: 'recorder'));
        $this->assertTrue($page->hasRoleOrIsAdminEditor('recorder'));
        $this->assertFalse($page->hasRoleOrIsAdminEditor('referee'));

        $page->setCurrentUser($this->createUser(role: 'admin'));
        $this->assertTrue($page->hasRoleOrIsAdminEditor('recorder'));
    }

    public function testHasRolesOrIsAdminEditor(): void
    {
        $page = $this->createPage();

        $page->setCurrentUser($this->createUser(role: 'recorder'));
        $this->assertTrue($page->hasRolesOrIsAdminEditor(['recorder', 'referee']));
        $this->assertFalse($page->hasRolesOrIsAdminEditor(['referee', 'verifier']));

        $page->setCurrentUser($this->createUser(role: 'editor'));
        $this->assertTrue($page->hasRolesOrIsAdminEditor(['referee', 'verifier']));
    }

    // --- Query string ---

    public function testAddQueryStringAppendsWithQuestionMark(): void
    {
        $page = $this->createPage();
        $page->setUrlWithQueryString('/search');

        $this->assertSame('/search?q=test', $page->addQueryString('q=test'));
    }

    public function testAddQueryStringAppendsWithAmpersand(): void
    {
        $page = $this->createPage();
        $page->setUrlWithQueryString('/search?q=test');

        $this->assertSame('/search?q=test&page=2', $page->addQueryString('page=2'));
    }

    // --- Display title ---

    public function testDisplayPageTitleFallsBackToTitle(): void
    {
        $page = $this->createPage('Fallback Title');

        $this->assertSame('Fallback Title', $page->getDisplayPageTitle());
    }

    public function testDisplayPageTitleUsesOverride(): void
    {
        $page = $this->createPage('Original');
        $page->setDisplayPageTitle('Override');

        $this->assertSame('Override', $page->getDisplayPageTitle());
    }

    // --- OpenGraph ---

    public function testOpenGraphProperties(): void
    {
        $page = $this->createPage();
        $page->setOpenGraphTitle('OG Title');
        $page->setOpenGraphDescription('OG Desc');
        $page->setOpenGraphImage('https://example.com/img.jpg');

        $this->assertSame('OG Title', $page->getOpenGraphTitle());
        $this->assertSame('OG Desc', $page->getOpenGraphDescription());
        $this->assertSame('https://example.com/img.jpg', $page->getOpenGraphImage());
    }

    public function testOpenGraphDescriptionPrefersThePagesOwn(): void
    {
        $page = $this->createPage();
        $page->setOpenGraphDescription('Own share text');
        $page->setDescription('Page description');
        $page->setDefaultOpenGraphDescription('Site default');

        $this->assertSame('Own share text', $page->getOpenGraphDescription());
    }

    public function testOpenGraphDescriptionFallsBackToThePageDescription(): void
    {
        $page = $this->createPage();
        $page->setOpenGraphDescription('');
        $page->setDescription('Page description');
        $page->setDefaultOpenGraphDescription('Site default');

        $this->assertSame('Page description', $page->getOpenGraphDescription());
    }

    public function testOpenGraphDescriptionFallsBackToTheSiteDefault(): void
    {
        $page = $this->createPage();
        $page->setDescription('');
        $page->setDefaultOpenGraphDescription('Site default');

        $this->assertSame('Site default', $page->getOpenGraphDescription());
    }

    public function testOpenGraphDescriptionUsesTheSiteDefaultWhenNoDescriptionWasSet(): void
    {
        $page = $this->createPage();
        $page->setDefaultOpenGraphDescription('Site default');

        $this->assertSame('Site default', $page->getOpenGraphDescription());
    }

    public function testOpenGraphDescriptionIsEmptyOnlyWhenEverythingIs(): void
    {
        $page = $this->createPage();
        $page->setDescription('   ');

        $this->assertSame('', $page->getOpenGraphDescription());
    }

    // --- Cookie consent ---

    public function testCookieConsentDefaults(): void
    {
        $page = $this->createPage();

        $this->assertTrue($page->doesNotRequireCookieConsent());
        $this->assertFalse($page->doesRequireCookieConsent());
        $this->assertFalse($page->isCookieConsentGiven());
        $this->assertFalse($page->isCookieConsentRejected());
    }

    public function testCookieConsentRoundTrip(): void
    {
        $page = $this->createPage();
        $page->setRequiresCookieConsent(true);
        $page->setIsCookieConsentGiven(true);
        $page->setCookieConsentCSRFToken('token123');

        $this->assertTrue($page->doesRequireCookieConsent());
        $this->assertTrue($page->isCookieConsentGiven());
        $this->assertSame('token123', $page->getCookieConsentCSRFToken());
    }

    // --- Password protection ---

    public function testPasswordProtectionDefaults(): void
    {
        $page = $this->createPage();
        $this->assertFalse($page->isPasswordProtected());
    }

    public function testPasswordProtectionRoundTrip(): void
    {
        $page = $this->createPage();
        $page->setIsPasswordProtected(true);
        $page->setPasswordCSRFToken('pw-token');

        $this->assertTrue($page->isPasswordProtected());
        $this->assertSame('pw-token', $page->getPasswordCSRFToken());
    }

    // --- Colour mode ---

    public function testColourModeDefaultsToLight(): void
    {
        $page = $this->createPage();
        $this->assertSame('light', $page->getColourMode());
    }

    public function testColourModeRoundTrip(): void
    {
        $page = $this->createPage();
        $page->setColourMode('dark');
        $this->assertSame('dark', $page->getColourMode());
    }

    // --- Attributes ---

    public function testAttributeHandling(): void
    {
        $page = $this->createPage();
        $page->setAttributes(['featured', 'pinned']);

        $this->assertTrue($page->hasAttribute('featured'));
        $this->assertTrue($page->hasAttribute('pinned'));
        $this->assertFalse($page->hasAttribute('archived'));
    }

    // --- CSS file ---

    public function testCssFileDefaultsToCustomCss(): void
    {
        $page = $this->createPage();
        $this->assertSame('custom.css', $page->getCssFile());
    }

    public function testSetCssFileOverridesDefault(): void
    {
        $page = $this->createPage();
        $result = $page->setCssFile('glaucous.css');

        $this->assertSame($page, $result);
        $this->assertSame('glaucous.css', $page->getCssFile());
    }
}
