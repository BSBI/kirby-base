<?php

declare(strict_types=1);

use BSBI\WebBase\helpers\FileArchiveService;
use Kirby\Cms\File;

/**
 * The File Archive "Permanent URL" field (bsbi-web#570).
 *
 * A text field for the slug whose Panel component (index.js, `permanenturl`) renders
 * the resulting address underneath as a live link with a copy button, computed from
 * what the editor is typing: `prefix` + (value or `filename`), because an empty field
 * means the filename. An info field cannot do this — the Panel renders it once, at
 * view load, so it shows the previous saved value until the page is reloaded.
 */
return [
    'extends' => 'text',
    'computed' => [
        /**
         * The site's `/files/` prefix, absolute, so the preview is the real address.
         */
        'prefix' => function (): string {
            return $this->model()->kirby()->url() . '/' . FileArchiveService::URL_PREFIX . '/';
        },
        /**
         * The filename, which an empty field falls back to.
         */
        'filename' => function (): ?string {
            $model = $this->model();
            return $model instanceof File ? $model->filename() : null;
        },
    ],
];
