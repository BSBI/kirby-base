<?php

declare(strict_types=1);

namespace BSBI\WebBase\models;

/**
 * Class GlossaryItem
 * Represents a glossary term: the title is the term itself, the definition is
 * the short text shown on hover, and the slug is used as the anchor on the
 * glossary listing page.
 *
 * @package BSBI\WebBase
 */
class GlossaryItem extends BaseModel
{
    /** @var string The short definition as plain text, links and markup stripped (shown on link hover) */
    private string $definition = '';

    /** @var string The short definition as HTML, which may link to other glossary terms */
    private string $definitionHtml = '';

    /** @var string Optional extended content about the term, rendered as HTML */
    private string $extendedContentHtml = '';

    /** @var string The item page's UUID permalink (page://...) */
    private string $uuid = '';

    /** @var string The Kirby Panel URL for editing the item */
    private string $panelUrl = '';

    /** @var string The page slug, used as the anchor on the listing page */
    private string $slug = '';

    /**
     * Whether a definition has been set
     * @return bool
     */
    public function hasDefinition(): bool
    {
        return $this->definition !== '';
    }

    /**
     * Get the short definition text
     * @return string
     */
    public function getDefinition(): string
    {
        return $this->definition;
    }

    /**
     * Set the short definition text
     * @param string $definition
     * @return GlossaryItem
     */
    public function setDefinition(string $definition): self
    {
        $this->definition = $definition;
        return $this;
    }

    /**
     * Whether an HTML definition has been set
     * @return bool
     */
    public function hasDefinitionHtml(): bool
    {
        return $this->definitionHtml !== '';
    }

    /**
     * Get the definition as HTML (may contain links to other glossary terms)
     * @return string
     */
    public function getDefinitionHtml(): string
    {
        return $this->definitionHtml;
    }

    /**
     * Set the definition as HTML
     * @param string $definitionHtml
     * @return GlossaryItem
     */
    public function setDefinitionHtml(string $definitionHtml): self
    {
        $this->definitionHtml = $definitionHtml;
        return $this;
    }

    /**
     * Render this item as an enriched glossary link, matching what the
     * render-time enricher produces for links in block content: href to the
     * item page, data-glossary hook, the HTML definition (when set) for
     * front-end tooltips, and the plain definition as the title fallback.
     * Used by the glossary/glossary-link snippet.
     *
     * @param string|null $label Link text (defaults to the term itself)
     * @return string The anchor HTML
     */
    public function getLinkHtml(?string $label = null): string
    {
        $html = '<a href="' . htmlspecialchars($this->getUrl(), ENT_QUOTES) . '" data-glossary="true"';

        if ($this->hasDefinitionHtml()) {
            $html .= ' data-glossary-html="' . htmlspecialchars($this->definitionHtml, ENT_QUOTES) . '"';
        }

        if ($this->hasDefinition()) {
            $html .= ' title="' . htmlspecialchars($this->definition, ENT_QUOTES) . '"';
        }

        return $html . '>' . htmlspecialchars($label ?? $this->getTitle(), ENT_NOQUOTES) . '</a>';
    }

    /**
     * Whether extended content has been set
     * @return bool
     */
    public function hasExtendedContentHtml(): bool
    {
        return $this->extendedContentHtml !== '';
    }

    /**
     * Get the extended content as HTML
     * @return string
     */
    public function getExtendedContentHtml(): string
    {
        return $this->extendedContentHtml;
    }

    /**
     * Set the extended content as HTML
     * @param string $extendedContentHtml
     * @return GlossaryItem
     */
    public function setExtendedContentHtml(string $extendedContentHtml): self
    {
        $this->extendedContentHtml = $extendedContentHtml;
        return $this;
    }

    /**
     * Whether a UUID has been set
     * @return bool
     */
    public function hasUuid(): bool
    {
        return $this->uuid !== '';
    }

    /**
     * Get the item page's UUID permalink
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * Set the item page's UUID permalink
     * @param string $uuid
     * @return GlossaryItem
     */
    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    /**
     * Whether a panel URL has been set
     * @return bool
     */
    public function hasPanelUrl(): bool
    {
        return $this->panelUrl !== '';
    }

    /**
     * Get the Kirby Panel URL for editing the item
     * @return string
     */
    public function getPanelUrl(): string
    {
        return $this->panelUrl;
    }

    /**
     * Set the Kirby Panel URL for editing the item
     * @param string $panelUrl
     * @return GlossaryItem
     */
    public function setPanelUrl(string $panelUrl): self
    {
        $this->panelUrl = $panelUrl;
        return $this;
    }

    /**
     * Whether a slug has been set
     * @return bool
     */
    public function hasSlug(): bool
    {
        return $this->slug !== '';
    }

    /**
     * Get the page slug
     * @return string
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Set the page slug
     * @param string $slug
     * @return GlossaryItem
     */
    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }
}
