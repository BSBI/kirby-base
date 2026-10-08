# Forms

The form system lets you define structured feedback/survey forms in code, store submissions as Kirby child pages, and export responses as CSV. Each form is built from five connected pieces.

## 1. Form definition class

Create a class extending `BaseFormDefinition` in your site's `classes/forms/` directory.

Override `defineForm()` and return any mix of `FormFieldSpec` and `FormSection` objects:

```php
class MyFormDefinition extends BaseFormDefinition
{
    public function getFormType(): string { return 'my_form'; }

    protected function defineForm(): array
    {
        return [
            FormFieldSpec::textbox('name', 'Your name')->required(),
            FormFieldSpec::radioGroup('role', 'Your role', ['Member', 'Volunteer'])
                ->overridable('label')
                ->overridable('options'),
            FormFieldSpec::textarea('feedback', 'Your feedback')->overridable('label'),
        ];
    }
}
```

**Sections with conditional display** — include `FormSection` objects anywhere in the returned array:

```php
protected function defineForm(): array
{
    return [
        FormFieldSpec::textbox('name', 'Your name')->required(),
        FormFieldSpec::radioGroup('contact_pref', 'Preferred contact', ['Email', 'Phone']),

        FormSection::make('email_section', 'Email details')
            ->fields(FormFieldSpec::textbox('email', 'Email address'))
            ->showWhen('contact_pref', 'Email'),

        FormSection::make('phone_section', 'Phone details')
            ->fields(FormFieldSpec::textbox('phone', 'Phone number'))
            ->showWhen('contact_pref', 'Phone'),
    ];
}
```

Sections are revealed client-side via vanilla JS when the controlling radio/select field value matches the expected value. Inputs inside hidden sections are disabled so they are not submitted.

`getFormType()` returns a short string (e.g. `'my_form'`) stored on every submission page for filtering and CSV export.

### FormFieldSpec types

| Factory method | HTML rendered as | Key options |
|----------------|------------------|-------------|
| `FormFieldSpec::textbox($name, $label)` | `<input type="text">` | `.required()`, `$inputType` param for email/date/etc |
| `FormFieldSpec::date($name, $label)` | `<input type="date">` | Shorthand for `textbox(..., 'date')` |
| `FormFieldSpec::textarea($name, $label)` | `<textarea>` | `.required()` |
| `FormFieldSpec::radioGroup($name, $label, $options)` | Radio buttons | `.overridable('options')` |
| `FormFieldSpec::checkboxGroup($name, $label, $options)` | Checkboxes | `.overridable('options')` |
| `FormFieldSpec::select($name, $label, $options)` | `<select>` | `.overridable('options')` |
| `FormFieldSpec::likert($name, $label)` | Scale buttons | `.overridable('leftLabel')`, `.overridable('rightLabel')` |

Radio and checkbox groups render as a `<fieldset>` whose `<legend>` is the label, so
screen readers announce the question alongside each option. Help text set with `.help()`
is linked to the fieldset by `aria-describedby` (`<name>-help`).

### Overridable properties

Calling `.overridable('label')` (or `'options'`, `'leftLabel'`, etc.) on a spec:
- Exposes that property as an editable panel field
- The panel field name is auto-derived: `{field_name}_{property_name}` in snake_case
  e.g. `knowledge_Start` + `leftLabel` → `knowledge_start_left_label`
- To generate ready-to-paste blueprint YAML for all overridable fields, call:
  ```php
  (new MyFormDefinition())->toBlueprintFields()
  ```

## 2. Page model class

Your consuming site needs a thin `FormPage` base class that wires the kirby-base
`FormPageInterface` and `FormProperties` trait into the site's own `WebPage` hierarchy.
This class cannot live in kirby-base because it must extend the site's `WebPage` (not
`BaseWebPage`), and PHP only allows single class inheritance:

```php
// classes/models/FormPage.php  (create once per site)
class FormPage extends WebPage implements FormPageInterface
{
    use FormProperties;
}
```

All actual logic is in the `FormProperties` trait — `FormPage` is three lines of glue.

For each distinct form type, create a subclass:

```php
class MyFormPage extends FormPage {}
```

No additional logic is needed unless you want to add form-specific properties.

## 3. Setter method in KirbyHelper

Add a protected setter method to your site's `KirbyHelper` class. The naming convention `set{ModelClass}(Page $page, ModelClass $model)` is important — `KirbyBaseHelper::getSpecificPage()` discovers and calls it automatically by matching the model class name.

Use `populateFormPage()` (inherited from `KirbyBaseHelper`) to resolve fields and custom blocks in one call, then `setFormPage()` to handle submission:

```php
protected function setMyFormPage(Page $page, MyFormPage $myFormPage): MyFormPage
{
    $definition = new MyFormDefinition();
    $this->populateFormPage($page, $myFormPage, $definition);
    $this->setFormPage($page, $myFormPage, $definition->getFormType());
    return $myFormPage;
}
```

- `populateFormPage()` resolves all fields/sections from the definition against panel overrides and populates `setFormFields()`, `setFormFieldGroups()`, and (if present) `setCustomFormBlocks()` on the model.
- `setFormPage()` handles CSRF validation, Turnstile CAPTCHA, storing the submission as a child page, and sending the confirmation email.

## 4. Page blueprint

Create `blueprints/pages/my_form.yml`. The `form` tab includes:
- `formSection: sections/formFields` — standard fields (email recipient, success content, custom form blocks)
- `overridesSection` — panel-editable overrides for any `.overridable()` properties in your definition

```yaml
title: "My Form"
icon: draft
extends: layouts/bsbi-web-page

tabs:
  form:
    icon: file-text
    sections:
      formSection: sections/formFields
      overridesSection:
        type: fields
        label: Field overrides
        help: Leave any field blank to use the built-in default.
        fields:
          # Paste output of (new MyFormDefinition())->toBlueprintFields() here
          role_label:
            type: text
            label: "Label: Your role"
            help: Leave blank to use the default.
          role_options:
            type: textarea
            label: "Options: Your role (one per line)"
            help: Leave blank to use the default.

  responsesTab:
    icon: file-document
    label: Form Responses
    sections:
      exportSection:
        type: formsubmissionexport
        headline: Export Submissions
      responsesSection:
        label: Form Responses
        type: pages
        template: form_submission
```

## 5. Controller and template

**Controller** (`controllers/my_form.php`):

```php
return function ($page) {
    $helper = new KirbyHelper();
    $currentPage = $helper->getSpecificPage($page->id(), MyFormPage::class);
    return compact('currentPage');
};
```

**Template** (`templates/my_form.php`) — call `getResolvedForm()` on the page model and pass the result to the `form/definition-form` snippet:

```php
snippet('layout/content-page', slots: true);
  slot('lowerBody');
    snippet('form/definition-form', ['form' => $currentPage->getResolvedForm()]);
  endslot();
endsnippet();
```

Create an intermediate snippet (e.g. `snippets/forms/my_form.php`) if you need content or logic specific to that form page surrounding the form itself.

## Summary

| Piece | Location | Purpose |
|-------|----------|---------|
| `MyFormDefinition` | `classes/forms/` | Declares fields, sections, and overridable properties |
| `MyFormPage` | `classes/models/` | Kirby page model — carries the resolved form state |
| `setMyFormPage()` | `KirbyHelper` | Wires definition → model; handles submission storage |
| `my_form.yml` | `blueprints/pages/` | Panel UI — field overrides, responses tab |
| `my_form.php` | `controllers/` + `templates/` | Fetches model; renders the form snippet |

## Panel-built forms

A form can also be read from panel content instead of a PHP class. Editors build it from
**sections**, and everything downstream (resolving, rendering, submission handling, CSV
export) is the same as for a hand-written definition.

### Content model

- **Library section**: a page using the `form_section` blueprint. `legend` is the title
  shown above its questions; `formFields` holds its questions (the `fields/formFieldBlocks`
  blocks field). It can set `extends` to another section, making it a **variation**: the
  base section's questions come first, then its own. Bases are read live, so editing a
  section changes every variation of it and every form that uses any of them. The
  blueprint's **What forms get** section (`formsectioncheck`, `PanelSectionCheck`) lists
  the questions a form receives, inherited ones marked with their section
  (`PanelSectionReader::readWithOrigins()`), and the section's own problems.
- **Form page**: a `formSections` blocks field holding, in order:
  - `form-section-ref`: a library section (`section` pages field), with an optional
    `title` overriding its legend
  - `form-section-inline`: a section written for this form only (`title`, `formFields`)

  Either can set `showWhen` to show the section only when a radio or dropdown question
  in an **earlier** section has a given answer. It is a select whose choices come from
  the page method `page.formConditionChoices` (`PanelFormDefinition::conditionChoices()`):
  one per answer of every radio and dropdown question on the form as last saved, stored
  as `key:answer` (keys never contain `:`, so it splits at the first) and labelled
  "Question: Answer (Section)". A stored choice that no longer matches stays in the list
  marked "No longer on this form", so the select's value is always an option (the Form
  check reports it). Content saved before 3.45.0 uses `showWhenField` (a field key) and
  `showWhenValue`. Those are still read, but they are no longer in the block blueprints,
  so re-saving such a block without picking a choice drops the condition. The Form check
  flags each one (since 3.45.1), naming the question and answer to pick again.

Question blocks: `form-textbox` (with `inputType`: text, email, tel, number, date, url),
`form-textarea`, `form-radio-group`, `form-checkbox-group`, `form-select`, `form-likert`,
`form-rating-matrix` and `form-info` (display-only markdown).

The section pickers look for `form_section` pages under a page with the slug
`form-library`. A site keeping its library elsewhere overrides the
`blocks/form-section-ref` and `pages/form_section` blueprints.

### Field keys

A question's POST key (and CSV column) is its `name` if one is set, otherwise `f_` plus
the first eight hex digits of its block id. Block ids never change, so a generated key
survives label edits and reordering. Set a name only when something downstream needs a
particular key (a CRM mapping, or a migration preserving an old form's columns). Names must
start with a letter and use letters, digits, underscores or hyphens (at most 64
characters); `csrf` and `submit` are reserved. `PanelFieldReader::keyFor()` is the one
source of keys; the legacy `customFormElements` block snippets use it too.

**Scripts that write form content must give every block an `id`** (a UUID, as the panel
does). Kirby invents a random id for a block stored without one, on every load, so the
reader substitutes an id derived from the owner, field and position and reports the block
through `validate()`. That stand-in lasts only until the panel next saves the content, so
re-save such content before the form takes responses.

### Using it

```php
use BSBI\WebBase\forms\panel\KirbySectionPageResolver;
use BSBI\WebBase\forms\panel\PanelFormDefinition;

$definition = new PanelFormDefinition($page, 'my_form', new KirbySectionPageResolver(kirby()));
$this->populateFormPage($page, $formPage, $definition);

foreach ($definition->validate() as $problem) {
    $this->writeToLog('forms', $problem); // or show to editors
}
```

Bad content never stops a form rendering. Missing or looping sections, invalid or duplicate
keys, and unusable conditions are left out (a duplicate key keeps its first occurrence; an
unusable condition means the section always shows), and `validate()` describes each one in
words an editor can act on.

Editor text (labels, help, options, Likert end labels) is HTML-escaped when read, because
the field snippets print those properties unescaped for developer-authored strings.
`form-info` text is rendered in markdown **safe mode** (`FormFieldSpec::info(..., true)`):
formatting and ordinary links work, raw HTML shows as text, and `javascript:`/`data:` link
targets are neutralised. Developer-authored `info()` content keeps full markdown.

### What a submission stores

`BaseFormDefinition::getSubmissionColumns()` returns, per POST key, the label the
respondent saw and the export column. It is empty by default, and an empty map keeps the
original behaviour: every POST key except `csrf`/`submit` is stored as
`{question: "Title Cased Key", answer}`. `PanelFormDefinition` returns a map, so a
panel-built form stores **only the questions it defines** (in form order, unanswered ones
blank), each as `{question: <label>, answer, key, column}`. Pass the map through:

```php
$this->createFormSubmission($page, $definition->getFormType(), $definition->getSubmissionColumns());
```

Answers are flattened to one cell: checkbox lists joined with commas, rating-matrix rows as
`row: answer, row: answer`. The building is `FormSubmissionBuilder`.

A question's column is its key unless the editor sets **Report as (export column)** on the
question block (a tags field offering `site.formReportColumns`, the columns already in use).
Questions sharing a Report-as name, on any form of the same type, share one CSV column.

### Exports

`FormSubmissionExporter` builds the rows for both export routes:

- `form-export/<page id>` (a form page's Responses section) and `form-export-all`
  (`?form_type=` to filter; the Forms page): **wide**, one row per submission. Items with
  a column are columned by it, headed by the Report-as name if set, otherwise by the most
  recent label the question was asked with — so relabelling a question keeps its column.
- **Old items** (stored before keys, `{question, answer}` with the key title-cased as the
  question) join the column of the key they were stored for, when keyed items of the same
  form type show that key and its column: "Event Name" joins `event_name`, ignoring case
  and treating `_` and `-` as spaces. An old item matching no key, or more than one key or
  column, keeps a column of its own text, as do old per-row rating-matrix items ("Event
  Rating: Venue"). Stored data is never changed; this is done on each export.
- `form-export-all?format=long`: **long**, one row per answer (Form Type, Submission, Date,
  Column, Question, Answer), for pivoting. Linked from the Forms tab per type and for all.

Merging different columns at export time is specified separately (bsbi-web
`specs/forms-export-collation.md`) and not built.

### Form types

Editors set a form's type: the Forms tab groups and exports by it, and editors deliberately
collate different forms under one type. Types are managed in one place, the **Form types**
list on the Form library page (`formTypes` structure: `name`, `description`). A form picks
one from a select (`site.formTypes`, stored value => label), so near-duplicates can't be
typed in. The stored value is the name in lower snake case
(`FormBuilderOptions::normaliseFormType()`: `Event feedback` → `event_feedback`). Types
found only on submissions (older hand-written forms) are offered too, exactly as stored,
so a new form can be collated with one of those. `FormBuilderOptions::formTypeOptions()`
merges the two lists.

Panel-built form pages are indexed by the `form_builders` content index (form type and
Report-as columns), for templates in the `forms.builderTemplates` option (default
`['form_builder']`), so none of these lists walks the page tree. Unlike other content
indexes it includes unlisted pages and drafts (`shouldIndex()`), because forms usually are
one or the other. It is kept up to date by the page hooks (create, update, duplicate,
status, move, delete; a duplicate's copied sub-pages are not indexed); rebuild it from the Indexes panel after adding forms by other means
(e.g. copying content files).

### Starting a form from another

`site.formBuilderChoices` lists every panel-built form, page UUID => "Title (Parent
title)" (`FormBuilderOptions::formBuilderChoices()`, from the index), for a select such as
a "Start from an existing form" field in the page-create dialog. Copying is the site's job
(bsbi-web: `FormBuilderStarter` in a `page.create:after` hook).

### The Forms page (side menu) and form check

- `forms.libraryPanel: true` adds a **Forms** side-menu entry (once "Form library"), shown
  to the roles in `forms.libraryRoles` (default `['admin', 'editor']`). Opening it creates
  the unlisted `form-library` page (template `form_library`) if missing, and renames one
  still titled "Form library". The page has two tabs: **Submissions** (the
  `formsubmissionsindex` section: count, CSV and Long CSV per form type) and **Library**
  (form types and sections). The site blueprint's `tabs/forms` remains for other sites.
- **Forms in use** (third tab, `formsinuse` section): every form on the site, with where it
  is, its kind, form type, status, responses, latest response and content folder, latest
  responses first, drafts behind a toggle. `FormsInUse` builds it:
  - **Finding the pages:** it finds pages by **content file name**
    (`ContentTemplateScanner`), never loading the page tree, for the templates in
    `FormsInUse::kindsFor()`: builder and extra-sections templates, plus the
    `forms.inUseTemplates` option (template => kind label). It also lists any other page
    that has responses, so nothing is missed.
  - **Responses:** counts and dates come from the `form_submissions` index.
  - **Caching:** the list is cached for ten minutes, and the tab's Refresh rebuilds it.
  - **API:** `GET /api/forms/in-use` (`?refresh=1`), for the roles in
    `forms.libraryRoles`.
- Looking up a page by id: `findPageOrDraft()` misses a draft inside a published page, so
  `FormsInUse` and `IndexedFormsUsingSection` fall back to `draft($id)`, which walks
  both. The library and its
  `form_section` pages render the 404 page on the site, and their blueprints allow only
  admin and editor to change them.
- The `formproblems` section (`type: formproblems`, optional `field`, default
  `formSections`) lists what `validate()` reports, on the form page, refreshed after each
  save. It is a section rather than an `info` field because problem messages quote editor
  text, which an info field would run through KirbyText.

### Locked questions

Once a form's stored responses use a question's key, that key is **locked**: renaming the
question's `name`, or removing the question (which includes deleting it and adding it again,
because the new block id gives a new key), would break the CSV column. Labels, help text,
options and order are never locked. Keys no response uses yet (a question added since the
last response) stay free.

- **The save is refused.** A `page.update:before` hook (`guardFormKeys()` in `hooks.php`)
  runs `FormKeyLockGuard::check()` for the `forms.builderTemplates` pages and `form_section`
  pages. It reads the page as it would be after the save (an in-memory clone with the saved
  values over its content; nothing is written) and compares question keys before and
  after. A loss of a key that responses use throws `FormKeyLockedException`, a Kirby
  `InvalidArgumentException` whose message the panel shows, naming the questions and the
  forms. Any other failure while checking is logged and the save goes ahead.
- **Responses are read only when a key would be lost.** `KirbyResponseKeySource` reads
  the `key` of each item on the form's `form_submission` children, stopping once every
  key it was asked about is found. Hand-written forms store their fixed questions without
  keys, so only their extra sections (below) are ever locked.
- **Library sections.** A section save that loses any of the section's keys finds the
  forms using it (directly or through a variation) from the `section_ids` column of the
  `form_builders` and `form_extra_sections` indexes (`IndexedFormsUsingSection`), and compares each form with
  responses as it would be with the edited section (`SectionOverrideResolver`). After a
  section save, the forms using it are re-indexed so their `section_ids` follow a
  variation pointed at another base.
- **Shown before a save.** The `formproblems` section lists the form's locked questions
  under "Locked by responses", and `formsectioncheck` lists a section's locked questions
  with the forms whose responses use them.

**After deploying a release that adds `section_ids`**, rebuild the `form_builders` index
(Indexes panel, or `/content-index-rebuild?name=form_builders`). Until then the column is
empty, and library-section saves aren't checked (form saves are).

## Extra sections on hand-written forms

A hand-written definition can take editor-defined sections too, read from a blocks field
(conventionally `extraSections`) with the same `form-section-ref` / `form-section-inline`
fieldsets as a panel-built form. The fixed questions stay in PHP; editors add to them.

- **Attaching.** Build a `PanelFormDefinition` for the page with the extras field and the
  fixed definition's `getFieldNames()` as `reservedKeys`, then call
  `$definition->withExtraSections($extras)` before `handleFormPage()`. `getFields()`,
  `getFieldGroups()` and `getFieldNames()` then include the extras; `toBlueprintFields()`
  and `getSubmissionColumns()` don't.
- **Where they go.** At the end, unless the definition overrides `extraSectionsAt()` to
  return how many `defineForm()` items come before them.
- **Reserved keys.** An extra question using a reserved key is left out, and the Form
  check says so ("is a question this form always asks"). The panel learns a page's
  reserved keys from the `forms.reservedKeys` option: a callable given the page, returning
  its keys (or null).
- **Conditions** work among the extra questions only: the show-when choices come from the
  extras' own definition.
- **Storing.** The form's handler stores the extras: `extraSubmissionColumns()` gives them
  as key => label and column, the same shape as a panel-built form's. Store them with
  `FormSubmissionBuilder::items($postData, $columns)` so they carry `key` and `column`,
  which is what the Forms-tab exports and key locking read.
- **Config.** `forms.sectionsFields` maps each such template to its field, e.g.
  `['form_event_feedback' => 'extraSections']`. It drives the show-when choices
  (`FormBuilderOptions::sectionsFieldFor()`), key locking (`FormKeyLockGuard`) and the
  `form_extra_sections` index. Builder templates always map to `formSections`.
- **Blueprint.** Extend `sections/extraSections` (the blocks field) and add a
  `formproblems` section with `field: extraSections`.
- **Index.** `form_extra_sections` records the library sections each such form uses
  (`section_ids`), so key locking finds them. It's separate from `form_builders`, which
  also feeds "Start from an existing form", the form types and the Report-as columns.
  After deploying the release that adds it, rebuild it from the Indexes panel.

### Required questions

`RequiredFields::missing($groups, $postData)` is the server-side required check for forms
with a submission handler. It skips any section whose show-when condition the submission
doesn't meet, because the respondent never saw that section's questions.

## Separate copies and updating within a session

- **"Also save as"** (`alsoSaveAs` on a `form-section-ref` or `form-section-inline` block,
  a form type picked from `site.formTypes`): when that section was shown (its show-when
  condition met) and has at least one answer, its answers are also saved on their own as
  a response of that type. They stay on the main response too. Sections with the same
  type share one copy. `PanelFormDefinition::separateCopies($postData)` gives type => keys;
  `createFormSubmission()` saves them. Hand-written definitions return none.
- **Update within session** (`updateWithinSession`, a toggle on the form page): a resubmit
  from the same browser session updates the responses saved earlier (main and copies)
  instead of adding new ones. `FormSubmissionWriter` remembers each response it saves per
  form page and form type in `SessionSlots` (`KirbySessionSlots` in the Kirby session); a
  remembered response that has since been deleted is replaced by a new one.

## Likert scales

A `form-likert` block has **Scale starts at / ends at**: whole numbers from 0 to 10, the
start below the end. Anything else gives 1 to 5 (`PanelFieldReader::scale()`).

## Moving a hand-written form into the panel

`SpecBlocks::block(ResolvedFormField, $seed)` turns a hand-written field, resolved against
its page (so panel overrides are carried), into panel field-block data whose field name is
the PHP key. It is the inverse of `PanelFieldReader` (the tests check the round trip for
every type). Markup in labels, help and options is dropped, because panel text is plain;
a site-blocks field becomes display-only text holding its text as markdown. Block ids come
from the seed and key, so converting twice gives the same ids.

## Custom form elements: deprecated

The `customFormElements` field (`sections/formFields`) still works, but panel-built forms
and extra sections replace it. bsbi-web has moved off it; it stays for other sites.

