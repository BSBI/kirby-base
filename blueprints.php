<?php


$user = kirby()->user();
$basePath = kirby()->root('blueprints');
$siteBluePrintPath =  $basePath . '/site_admin.yml';

return [
    'site' => $siteBluePrintPath,

    //pages blueprints
    'pages/file_archive' => __DIR__ . '/blueprints/pages/file_archive.yml',
    'pages/file_link' => __DIR__ . '/blueprints/pages/file_link.yml',
    'pages/form_submission' => __DIR__ . '/blueprints/pages/form_submission.yml',
    'pages/page_link' => __DIR__ . '/blueprints/pages/page_link.yml',
    'pages/image_bank' => __DIR__ . '/blueprints/pages/image_bank.yml',
    'pages/search_log' => __DIR__ . '/blueprints/pages/search_log.yml',
    'pages/search_log_item' => __DIR__ . '/blueprints/pages/search_log_item.yml',
    'pages/glossary_listing' => __DIR__ . '/blueprints/pages/glossary_listing.yml',
    'pages/glossary_item' => __DIR__ . '/blueprints/pages/glossary_item.yml',

    //block blueprints
    'blocks/text' => __DIR__ . '/blueprints/blocks/text.yml',
    'blocks/list' => __DIR__ . '/blueprints/blocks/list.yml',
    'blocks/accordion' => __DIR__ . '/blueprints/blocks/accordion.yml',
    'blocks/button' => __DIR__ . '/blueprints/blocks/button.yml',
    'blocks/cards' => __DIR__ . '/blueprints/blocks/cards.yml',
    'blocks/description-list' => __DIR__ . '/blueprints/blocks/description-list.yml',
    'blocks/faq' => __DIR__ . '/blueprints/blocks/faq.yml',
    'blocks/file' => __DIR__ . '/blueprints/blocks/file.yml',
    'blocks/gallery' => __DIR__ . '/blueprints/blocks/gallery.yml',
    'blocks/heading' => __DIR__ . '/blueprints/blocks/heading.yml',
    'blocks/links' => __DIR__ . '/blueprints/blocks/links.yml',
    'blocks/image' => __DIR__ . '/blueprints/blocks/image.yml',
    'blocks/note' => __DIR__ . '/blueprints/blocks/note.yml',
    'blocks/quote' => __DIR__ . '/blueprints/blocks/quote.yml',
    'blocks/ready-made' => __DIR__ . '/blueprints/blocks/ready-made.yml',
    'blocks/table-2col' => __DIR__ . '/blueprints/blocks/table-2col.yml',
    'blocks/table-3col' => __DIR__ . '/blueprints/blocks/table-3col.yml',
    'blocks/table-4col' => __DIR__ . '/blueprints/blocks/table-4col.yml',
    'blocks/table-5col' => __DIR__ . '/blueprints/blocks/table-5col.yml',
    'blocks/table-6col' => __DIR__ . '/blueprints/blocks/table-6col.yml',
    'blocks/form-textbox' => __DIR__ . '/blueprints/blocks/form-textbox.yml',
    'blocks/form-textarea' => __DIR__ . '/blueprints/blocks/form-textarea.yml',
    'blocks/form-checkbox-group' => __DIR__ . '/blueprints/blocks/form-checkbox-group.yml',
    'blocks/form-radio-group' => __DIR__ . '/blueprints/blocks/form-radio-group.yml',
    'blocks/form-likert' => __DIR__ . '/blueprints/blocks/form-likert.yml',
    'blocks/form-select' => __DIR__ . '/blueprints/blocks/form-select.yml',

    //field blueprints
    'fields/mainContent' => __DIR__ . '/blueprints/fields/mainContent.yml',
    'fields/simpleContent' => __DIR__ . '/blueprints/fields/simpleContent.yml',
    'fields/image' => __DIR__ . '/blueprints/fields/image.yml',
    'fields/mainImage' => __DIR__ . '/blueprints/fields/mainImage.yml',
    'fields/pageLinks' => __DIR__ . '/blueprints/fields/pageLinks.yml',
    'fields/panelContent' => __DIR__ . '/blueprints/fields/panelContent.yml',
    'fields/panelImage' => __DIR__ . '/blueprints/fields/panelImage.yml',
    'fields/placeholderImage' => __DIR__ . '/blueprints/fields/placeholderImage.yml',
    'fields/relatedContent' => __DIR__ . '/blueprints/fields/relatedContent.yml',
    'fields/blockImagePicker' => __DIR__ . '/blueprints/fields/blockImagePicker.yml',

    //files blueprints
    'files/default' => __DIR__ . '/blueprints/files/default.yml',
    'files/image' => __DIR__ . '/blueprints/files/image.yml',
    'files/image_bank_item' => __DIR__ . '/blueprints/files/image_bank_item.yml',
    'files/file_archive_item' => __DIR__ . '/blueprints/files/file_archive_item.yml',

    //section blueprints
    'sections/corePageFields' => __DIR__ . '/blueprints/sections/corePageFields.yml',
    'sections/formFields' => __DIR__ . '/blueprints/sections/formFields.yml',
    'sections/menuFields' => __DIR__ . '/blueprints/sections/menuFields.yml',
    'sections/publicationFields' => __DIR__ . '/blueprints/sections/publicationFields.yml',
    'sections/templateName' => __DIR__ . '/blueprints/sections/templateName.yml',

    //tabs blueprints
    'tabs/forms' => __DIR__ . '/blueprints/tabs/forms.yml',
    'tabs/page' => __DIR__ . '/blueprints/tabs/page.yml',
    'tabs/fields' => __DIR__ . '/blueprints/tabs/fields.yml',
    'tabs/panel' => __DIR__ . '/blueprints/tabs/panel.yml',
    'tabs/related' => __DIR__ . '/blueprints/tabs/related.yml',
    'tabs/info' => __DIR__ . '/blueprints/tabs/info.yml',
    'tabs/scheduled' => __DIR__ . '/blueprints/tabs/scheduled.yml',
    'tabs/permissions' => __DIR__ . '/blueprints/tabs/permissions.yml',

    //layout blueprints
    'layouts/page' => __DIR__ . '/blueprints/layouts/page.yml',
    ];