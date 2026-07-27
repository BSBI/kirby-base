<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use BSBI\WebBase\models\Document;
use BSBI\WebBase\models\Image;
use BSBI\WebBase\models\ImageList;
use BSBI\WebBase\models\ImageSizes;
use BSBI\WebBase\models\ImageType;
use Exception;
use Kirby\Cms\Block;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\StructureObject;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\Asset;
use Throwable;

/**
 * Service for building image and document model objects from Kirby CMS files.
 * Handles image processing (thumbnails, srcsets, WebP/AVIF), document URL resolution,
 * and file metadata extraction.
 */
final readonly class ImageService
{
    /**
     * Minimum SVG file size (bytes) before we inspect it for an embedded raster.
     * Genuine icon/vector SVGs sit well under this; only bloated raster-wrapped
     * exports exceed it, so this cheap check avoids reading every SVG on render.
     */
    private const int RASTER_WRAPPED_SVG_THRESHOLD = 102400; // 100 KB

    /**
     * @param KirbyFieldReader $fieldReader For reading raw file/field values from Kirby
     * @param SvgRasterExtractor $svgRasterExtractor Pulls embedded bitmaps out of raster-wrapped SVGs
     */
    public function __construct(
        private KirbyFieldReader $fieldReader,
        private SvgRasterExtractor $svgRasterExtractor = new SvgRasterExtractor()
    ) {
    }

    // region IMAGES

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
        $pageImage = $this->fieldReader->getPageFieldAsFile($page, $fieldName);
        if ($pageImage === null) {
            throw new KirbyRetrievalException(
                'The image for field "' . $fieldName . '" on page "' . $page->id() . '" could not be resolved'
                . ' (the referenced file does not exist in this content tree)'
            );
        }
        return $this->getImageFromFile($pageImage, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
    }

    /**
     * @param Page $pages
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
    public function getImages(Page       $page,
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
        $pageImages = $this->fieldReader->getPageFieldAsFiles($page, $fieldName);
        $imageList = new ImageList();
        if ($pageImages === null) {
            return $imageList;
        }
        foreach ($pageImages as $image) {
            $imageList->addListItem($this->getImageFromFile($image, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass));
        }
        return $imageList;
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
    public function getImageFromStructureField(StructureObject $structureObject,
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
        $structureImage = $this->fieldReader->getStructureFieldAsFile($structureObject, $fieldName);
        return $this->getImageFromFile($structureImage, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
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
    public function getImagesFromStructureField(StructureObject $structureObject,
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
        $structureImages = $this->fieldReader->getStructureFieldAsFiles($structureObject, $fieldName);
        $imageList = new ImageList();
        foreach ($structureImages as $image) {
            $imageList->addListItem($this->getImageFromFile($image, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass));
        }
        return $imageList;
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
    public function getImageFromSiteField(string     $fieldName,
                                          int        $width,
                                          int        $height,
                                          int        $quality = 90,
                                          ImageType  $imageType = ImageType::SQUARE,
                                          string     $imageFormat = '',
                                          ImageSizes $imageSizes = ImageSizes::NOT_SPECIFIED,
                                          bool       $crop = true,
                                          string     $imageClass = ''): Image
    {
        $siteImage = $this->fieldReader->getSiteFieldAsFile($fieldName);
        return $this->getImageFromFile($siteImage, $width, $height, $quality, $imageType, $imageFormat, $imageSizes, $crop, $imageClass);
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
    public function getImageFromFile(File       $image,
                                     int        $width,
                                     ?int       $height,
                                     int        $quality = 90,
                                     ImageType  $imageType = ImageType::SQUARE,
                                     string     $imageFormat = '',
                                     ImageSizes $imageSizes = ImageSizes::NOT_SPECIFIED,
                                     bool       $crop = true,
                                     string     $imageClass = ''): Image
    {
        $thumbOptions = [
            'width' => $width,
            'height' => $height,
            'quality' => $quality,
            'crop' => $crop
        ];

        if (!empty($imageFormat)) {
            $thumbOptions['format'] = $imageFormat;
        }

        // SVGs that are really base64-embedded bitmaps cannot be resized by
        // Kirby, so swap in a rasterised replacement for thumbnail/srcset
        // generation. Everything else (and genuine vector SVGs) is unchanged.
        $source = $this->resolveRasterSource($image);

        try {
            $src = $source->thumb($thumbOptions)->url();
            $webpThumbOptions = array_merge($thumbOptions, ['format' => 'webp']);
            $webpSrc = $source->thumb($webpThumbOptions)->url();
        } catch (InvalidArgumentException $e) {
            throw new KirbyRetrievalException('The image could not be retrieved: ' . $e->getMessage());
        }

        $avifSrc = '';
        try {
            $avifThumbOptions = array_merge($thumbOptions, ['format' => 'avif']);
            $avifSrc = $source->thumb($avifThumbOptions)->url();
        } catch (Exception) {
            // AVIF not supported on this server - continue without it
        }

        $srcSetType = strtolower($imageType->value);
        $srcSet = $source->srcset($srcSetType);
        $webpSrcSet = $source->srcset($srcSetType . '-webp');

        if (empty($srcSet) && $srcSetType !== 'panel') {
            $srcSet = $source->srcset('panel');
            $webpSrcSet = $source->srcset('panel-webp');
        }

        $avifSrcSet = '';
        try {
            $avifSrcSet = $source->srcset($srcSetType . '-avif');
        } catch (Exception) {
            // AVIF not supported on this server - continue without it
        }

        /** @noinspection PhpUndefinedMethodInspection */
        $alt = $image->alt()->isNotEmpty() ? $image->alt()->value() : '';
        $caption = $this->getCaptionForImage($image);
        if ($src !== null && $srcSet !== null && $webpSrcSet !== null) {
            $imageObj = (new Image($src, $srcSet, $webpSrcSet, $alt, $width, $height))
                ->setSizes($imageSizes->value)
                ->setClass($imageClass)
                ->setCaption($caption)
                ->setWebpSrc($webpSrc);
            if (!empty($avifSrc)) {
                $imageObj->setAvifSrc($avifSrc);
            }
            if (!empty($avifSrcSet)) {
                $imageObj->setAvifSrcset($avifSrcSet);
            }
            return $imageObj;
        }
        return (new Image())->recordError('Image not found');
    }

    /**
     * Returns an Image model for an SVG file field, using the raw file URL without
     * any thumbnail processing (SVGs are vector files and do not need rasterisation).
     *
     * @param Page $page
     * @param string $fieldName
     * @param string $imageClass
     * @return Image
     * @throws KirbyRetrievalException
     */
    /**
     * Returns an Image model for an SVG file field, using the raw file URL without
     * any thumbnail processing (SVGs are vector files and do not need rasterisation).
     *
     * @param Page $page
     * @param string $fieldName
     * @param string $imageClass
     * @return Image
     * @throws KirbyRetrievalException
     */
    public function getSvgImage(Page $page, string $fieldName, string $imageClass = ''): Image
    {
        $file = $this->fieldReader->getPageFieldAsFile($page, $fieldName);
        if ($file === null) {
            return (new Image())->recordError('SVG file not found');
        }
        return $this->getSvgImageFromFile($file, $imageClass);
    }

    /**
     * Builds an Image model directly from a Kirby File, using the raw URL without
     * thumbnail processing. Suitable for SVG files.
     *
     * @param File $file
     * @param string $imageClass
     * @return Image
     */
    public function getSvgImageFromFile(File $file, string $imageClass = ''): Image
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $alt = $file->alt()->isNotEmpty() ? $file->alt()->value() : '';
        return (new Image($file->url(), '', '', $alt))->setClass($imageClass);
    }

    // endregion

    // region FILES

    /**
     * @param File $file
     * @return string
     */
    public function getFileURL(File $file): string
    {
        if ($this->isFileFieldNotEmpty($file, 'permanentUrl')) {
            $slug = $this->getFileFieldAsString($file, 'permanentUrl');
            return kirby()->url() . '/files/' . $slug;
        }
        return $file->url();
    }

    /**
     * @param File $file
     * @param string $fieldName
     * @return bool
     */
    public function isFileFieldNotEmpty(File $file, string $fieldName): bool
    {
        return $file->{$fieldName}()->isNotEmpty();
    }

    /**
     * @param File $file
     * @param string $fieldName
     * @return string
     */
    public function getFileFieldAsString(File $file, string $fieldName): string
    {
        return $file->{$fieldName}()->value();
    }

    // endregion

    // region DOCUMENTS

    /**
     * @param File $pageFile
     * @param string $title
     * @return Document
     */
    public function getDocumentFromFile(File $pageFile, string $title = 'Download'): Document
    {
        $url = $this->getFileURL($pageFile);
        /** @noinspection PhpUndefinedMethodInspection */
        $size = $pageFile->niceSize();
        $document = new Document($title, $url);
        $document->setSize($size);
        return $document;
    }

    // endregion

    // region BLOCK IMAGES

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
    public function getBlockFieldAsImage(Block     $block,
                                         string    $fieldName,
                                         int       $width,
                                         ?int      $height,
                                         ImageType $imageType = ImageType::SQUARE,
                                         bool      $fixedWidth = false): Image
    {
        try {
            $blockImage = $this->fieldReader->getBlockFieldAsFile($block, $fieldName);
            if ($blockImage != null) {
                if ($fixedWidth) {
                    /** @noinspection PhpUndefinedMethodInspection */
                    if ($blockImage->width() < $width) {
                        /** @noinspection PhpUndefinedMethodInspection */
                        $width = $blockImage->width();
                    }
                    $resizedImage = $blockImage->resize($width);
                    $src = $resizedImage->url();
                    $srcSet = $blockImage->srcset([
                        '1x' => ['width' => $width],
                        '2x' => ['width' => $width * 2],
                        '3x' => ['width' => $width * 3],
                    ]);
                    /** @noinspection PhpUndefinedMethodInspection */
                    $height = $resizedImage->height();
                    $webpSrcSet = $blockImage->srcset([
                        '1x' => ['width' => $width, 'format' => 'webp'],
                        '2x' => ['width' => $width * 2, 'format' => 'webp'],
                        '3x' => ['width' => $width * 3, 'format' => 'webp'],
                    ]);
                } else {
                    $srcSetType = ($imageType === ImageType::SQUARE) ? 'square' : 'main';
                    $src = $blockImage->crop($width, $height)->url();
                    $srcSet = $blockImage->srcset($srcSetType);
                    $webpSrcSet = $blockImage->srcset($srcSetType . '-webp');
                }
                /** @noinspection PhpUndefinedMethodInspection */
                $alt = $blockImage->alt()->isNotEmpty() ? $blockImage->alt()->value() : '';
                if ($src !== null && $srcSet !== null && $webpSrcSet !== null) {
                    return new Image($src, $srcSet, $webpSrcSet, $alt, $width, $height);
                }
            }
            return (new Image())->recordError('Image not found');
        } catch (Throwable) {
            return (new Image())->recordError('Image not found');
        }
    }

    // endregion

    // region RASTER-WRAPPED SVG HANDLING

    /**
     * Returns a thumbnail-able source for the given file.
     *
     * Most files are returned unchanged. The exception is an oversized SVG that
     * is really a base64-embedded bitmap ("raster-wrapped" SVG): Kirby cannot
     * resize an SVG, so such a file would otherwise ship at full size. For those
     * the embedded bitmap is extracted once, cached under the media folder and
     * returned as a Kirby Asset so the normal thumbnail/srcset pipeline can
     * shrink it. Genuine vector SVGs (no embedded raster) are left untouched.
     *
     * Fails safe: on any problem the original file is returned, so rendering is
     * never broken by this optimisation.
     *
     * @param File $file The original Kirby file.
     * @return File|Asset The original file, or a rasterised Asset replacement.
     */
    public function resolveRasterSource(File $file): File|Asset
    {
        try {
            if (strtolower($file->extension()) !== 'svg') {
                return $file;
            }
            if ($file->size() < self::RASTER_WRAPPED_SVG_THRESHOLD) {
                return $file;
            }

            $cached = $this->cachedRasterAsset($file);
            if ($cached !== null) {
                return $cached;
            }

            $contents = $file->read();
            if ($contents === false || !$this->svgRasterExtractor->isRasterWrappedSvg($contents)) {
                return $file;
            }

            $raster = $this->svgRasterExtractor->extractLargestRaster($contents);
            if ($raster === null) {
                return $file;
            }

            return $this->writeRasterAsset($file, $raster) ?? $file;
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile(
                'image-errors',
                'Failed to rasterise SVG ' . $file->id() . ': ' . $e->getMessage()
            );
            return $file;
        }
    }

    /**
     * Deterministic cache key for a file's extracted raster, busting when the
     * source SVG is modified.
     *
     * @param File $file
     * @return string
     */
    private function rasterCacheBaseName(File $file): string
    {
        return 'media/rasterised/' . md5($file->root() . '|' . $file->modified());
    }

    /**
     * Returns a previously cached rasterised Asset for the file, if one exists,
     * without reading the (potentially large) SVG.
     *
     * @param File $file
     * @return Asset|null
     */
    private function cachedRasterAsset(File $file): ?Asset
    {
        $base      = $this->rasterCacheBaseName($file);
        $indexRoot = kirby()->root('index');
        foreach (['png', 'jpg', 'gif', 'webp'] as $extension) {
            $relativePath = $base . '.' . $extension;
            if (is_file($indexRoot . '/' . $relativePath)) {
                return new Asset($relativePath);
            }
        }
        return null;
    }

    /**
     * Writes the extracted raster to the media cache and returns it as an Asset.
     *
     * @param File $file The source SVG file.
     * @param ExtractedRaster $raster The bitmap extracted from it.
     * @return Asset|null The cached Asset, or null if it could not be written.
     */
    private function writeRasterAsset(File $file, ExtractedRaster $raster): ?Asset
    {
        $relativePath = $this->rasterCacheBaseName($file) . '.' . $raster->extension;
        $absolutePath = kirby()->root('index') . '/' . $relativePath;
        $directory    = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }
        if (file_put_contents($absolutePath, $raster->bytes) === false) {
            return null;
        }
        return new Asset($relativePath);
    }

    // endregion

    // region PRIVATE HELPERS

    /**
     * @param File $image
     * @return string
     */
    private function getCaptionForImage(File $image): string
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $captionField = $image->caption()->isNotEmpty() ? $image->caption()->kt() : $image->alt();
        $caption = $captionField->value() ?? '';
        /** @noinspection PhpUndefinedMethodInspection */
        if ($image->photographer()->isNotEmpty()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $caption .= ' Photographer: ' . $image->photographer()->value();
        }
        /** @noinspection PhpUndefinedMethodInspection */
        if ($image->license()->isNotEmpty()) {
            /** @noinspection PhpUndefinedMethodInspection */
            $caption .= ' License: ' . $image->license()->value();
        }
        return $caption;
    }

    // endregion
}
