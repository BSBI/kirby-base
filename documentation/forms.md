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
  section changes every variation of it and every form that uses any of them.
- **Form page**: a `formSections` blocks field holding, in order:
  - `form-section-ref`: a library section (`section` pages field), with an optional
    `title` overriding its legend
  - `form-section-inline`: a section written for this form only (`title`, `formFields`)

  Either can set `showWhenField` (a field key) and `showWhenValue`, to show the section
  only when a radio or dropdown question in an **earlier** section has that answer.

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
`form-info` text is rendered as markdown, like any editor textarea.
