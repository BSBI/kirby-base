<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\CoreLink;
use BSBI\WebBase\models\ImageSizes;
use BSBI\WebBase\models\ImageType;
use BSBI\WebBase\models\WebPageLink;
use BSBI\WebBase\models\WebPageLinks;
use Closure;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Toolkit\Collection;

/**
 * Service for building navigation and link model objects from Kirby CMS pages.
 * Handles CoreLink resolution, WebPageLink building (including page_link and file_link
 * template logic), and recursive sub-page link trees.
 *
 * The optional $linkExtension closure supports the extension pattern used by consuming
 * sites: it is called with the template name, page, and WebPageLink, and may return
 * a modified WebPageLink. KirbyBaseHelper passes a closure that dispatches to
 * getWebPageLinkFor{TemplateName}() methods defined in the site-specific helper subclass.
 */
final readonly class NavigationService
{
    /**
     * @param KirbyFieldReader $fieldReader For reading raw field values from Kirby
     * @param ImageService $imageService For resolving panel images on links
     * @param Site $site The Kirby site object (for findCoreLink)
     * @param Closure|null $linkExtension Optional callback for template-specific link customisation.
     *        Signature: function(string $templateName, Page $page, WebPageLink $webPageLink): WebPageLink
     */
    public function __construct(
        private KirbyFieldReader $fieldReader,
        private ImageService     $imageService,
        private Site             $site,
        private ?Closure         $linkExtension = null,
    ) {
    }

    /**
     * @param string $title
     * @param string $linkType
     * @return CoreLink
     * @noinspection PhpUnused
     */
    public function findCoreLink(string $title, string $linkType): CoreLink
    {
        $page = $this->site->children()->find($title);
        if ($page instanceof Page) {
            $coreLink = new CoreLink($page->title()->toString(), $page->url(), $linkType);
        } else {
            $coreLink = new CoreLink('', '', 'NOT_FOUND');
            $coreLink->setStatus(false);
        }
        return $coreLink;
    }

    /**
     * @param Page $page
     * @param string $linkType
     * @return CoreLink
     * @noinspection PhpUnused
     */
    public function getCoreLink(Page $page, string $linkType): CoreLink
    {
        return new CoreLink($page->title()->toString(), $page->url(), $linkType);
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
    public function getWebPageLinks(Collection $collection,
                                    bool       $simpleLink = true,
                                    bool       $getSubPages = false,
                                    bool       $getImages = true,
                                    ImageSizes $imageSizes = ImageSizes::HALF_LARGE_SCREEN,
                                    ImageType  $imageType = ImageType::PANEL): WebPageLinks
    {
        $webPageLinks = new WebPageLinks();
        /** @var Page $collectionPage */
        foreach ($collection as $collectionPage) {
            $webPageLink = $this->getWebPageLink($collectionPage, $simpleLink, null, null, $getImages, $imageSizes, $imageType);
            if ($getSubPages) {
                $excludedTemplates = option('subPagesExclude', []);
                if (!is_array($excludedTemplates)) {
                    $excludedTemplates = [];
                }
                $subPages = $collectionPage->children()->listed()->notTemplate($excludedTemplates);
                $getSubPageImages = $webPageLink->doShowSubPageImages();
                $webPageLink->setSubPages($this->getWebPageLinks($subPages, $simpleLink, false, $getSubPageImages, ImageSizes::QUARTER_LARGE_SCREEN, ImageType::PANEL_SMALL));
            }
            $webPageLinks->addListItem($webPageLink);
        }
        return $webPageLinks;
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
    public function getWebPageLink(Page        $page,
                                   bool        $simpleLink = true,
                                   string|null $linkTitle = null,
                                   string|null $linkDescription = null,
                                   bool        $getImages = true,
                                   ImageSizes  $imageSizes = ImageSizes::HALF_LARGE_SCREEN,
                                   ImageType   $imageType = ImageType::PANEL): WebPageLink
    {
        $templateName = $page->template()->name();

        if ($templateName === 'page_link') {
            $linkType = $this->fieldReader->getLinkFieldType($page, 'redirect_link');
            if ($linkType === 'page') {
                $linkedPage = $this->fieldReader->getPageFieldAsPages($page, 'redirect_link', $simpleLink);
                $linkTitle = $page->title()->toString();
                $linkDescription = $this->fieldReader->getPageFieldAsKirbyText($page, 'panelContent');
                if ($linkedPage->first()) {
                    $page = $linkedPage->first();
                }
            } elseif ($linkType === 'url') {
                $pageUrl = $this->fieldReader->getPageFieldAsUrl($page, 'redirect_link');
            }
        }

        if ($templateName === 'file_link') {
            $file = $this->fieldReader->getPageFieldAsFile($page, 'file');
            $pageUrl = $this->imageService->getFileURL($file);
        }

        $linkDescription = empty($linkDescription)
            ? $this->fieldReader->getPageFieldAsString($page, 'panelContent') : $linkDescription;
        $linkTitle = $linkTitle ?? $page->title()->toString();
        $webPageLink = new WebPageLink($linkTitle, $pageUrl ?? $page->url(), $page->id(), $page->template()->name());
        $webPageLink->setLinkDescription($linkDescription);
        if ($this->fieldReader->isPageFieldNotEmpty($page, 'requirements')) {
            $webPageLink->setRequirements($this->fieldReader->getPageFieldAsKirbyText($page, 'requirements'));
        }

        if ($this->linkExtension !== null) {
            $webPageLink = ($this->linkExtension)($templateName, $page, $webPageLink);
        }

        $webPageLink->setShowSubPageImages($this->fieldReader->getPageFieldAsBool($page, 'showSubPageImages'));

        if ($simpleLink) {
            return $webPageLink;
        }
        if ($getImages && $this->fieldReader->isPageFieldNotEmpty($page, 'panelImage')) {
            try {
                $panelImage = $this->imageService->getImage($page, 'panelImage', 400, 300, 80, $imageType, '', $imageSizes);
                $panelImage->setClass('img-fix-size img-fix-size--four-three');
                $webPageLink->setImage($panelImage);
            } catch (KirbyRetrievalException $e) {
                // A panel image that cannot be resolved (e.g. an image-bank file
                // missing from this content tree) degrades to a link without an
                // image rather than breaking the whole page.
                KirbyBaseHelper::writeToLogFile('errors', 'Panel image skipped for ' . $page->id() . ': ' . $e->getMessage());
            }
        }

        return $webPageLink;
    }
}
