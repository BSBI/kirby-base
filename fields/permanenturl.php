<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\FileArchiveService;
use Kirby\Cms\File;

/**
 * The File Archive "Permanent URL" field (bsbi-web#570): a text field that shows the
 * address live as the editor types — the site's `/files/` prefix before the input,
 * and the filename as placeholder, because an empty field means the filename.
 *
 * An info field cannot do this: the Panel renders it once, at view load, so it shows
 * the previous saved value until the page is reloaded.
 */
return [
    'extends' => 'text',
    'computed' => [
        'before' => function (): string {
            return $this->model()->kirby()->url() . '/' . FileArchiveService::URL_PREFIX . '/';
        },
        'placeholder' => function (): ?string {
            $model = $this->model();
            return $model instanceof File ? $model->filename() : null;
        },
    ],
];
