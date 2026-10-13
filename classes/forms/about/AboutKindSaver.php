<?php

declare(strict_types=1);

namespace BSBI\WebBase\forms\about;

use BSBI\WebBase\forms\FormBuilderOptions;
use BSBI\WebBase\forms\panel\PanelContent;
use BSBI\WebBase\helpers\KirbyBaseHelper;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Throwable;

/**
 * Saves a kind in a form's "Feedback is about" ticks (`aboutKinds`) when an
 * editor picks a page of that kind for an about link, so the link works
 * without the editor remembering to save the form first.
 *
 * Only that field is saved, to the page's published content; the editor's
 * other unsaved changes stay unsaved. The field is the same in every language,
 * so it is saved in the default language.
 */
final readonly class AboutKindSaver
{
    /** The kind was already saved on the form. */
    public const ALREADY = 'already';

    /** The kind has just been saved on the form. */
    public const SAVED = 'saved';

    /** The kind could not be saved: not a known kind, not a form, not allowed, or the save failed. */
    public const REFUSED = 'refused';

    /** The form's field holding its ticked kinds. */
    private const FIELD = 'aboutKinds';

    /**
     * @param App        $kirby The Kirby app (its user is the one saving)
     * @param AboutKinds $kinds The configured kinds
     */
    public function __construct(private App $kirby, private AboutKinds $kinds)
    {
    }

    /**
     * Makes sure the form accepts the kind, saving the tick if it isn't saved.
     *
     * @param Page   $form A panel-built form page
     * @param string $kind A configured kind
     * @return string One of ALREADY, SAVED or REFUSED
     */
    public function accept(Page $form, string $kind): string
    {
        if (in_array($kind, $this->kinds->forForm($form), true)) {
            return self::ALREADY;
        }
        if (
            !array_key_exists($kind, $this->kinds->all())
            || !in_array($form->intendedTemplate()->name(), FormBuilderOptions::builderTemplates($this->kirby), true)
            || !$form->permissions()->can('update')
        ) {
            return self::REFUSED;
        }

        // Keep every saved tick, known kinds or not, and add this one. The
        // field isn't translated, so it is read and saved in the default language.
        $language = $this->kirby->defaultLanguage()?->code();
        $ticked = array_filter(
            array_map('trim', explode(',', PanelContent::text($form->content($language), self::FIELD))),
            static fn(string $tick): bool => $tick !== ''
        );
        $ticked[] = $kind;

        try {
            $form->update([self::FIELD => implode(', ', $ticked)], $language);
        } catch (Throwable $e) {
            KirbyBaseHelper::writeToLogFile('forms', 'Could not save the about kind ' . $kind . ' on ' . $form->id() . ': ' . $e->getMessage());
            return self::REFUSED;
        }
        return self::SAVED;
    }
}
