<?php

namespace BSBI\WebBase\models;

/**
 *
 */
enum ImageType: string
{
    case SQUARE = 'Square';
    case MAIN = 'Main';
    case FIXED = 'Fixed';
    case THUMBNAIL = 'Thumbnail';
    case PANEL = 'Panel';
    case PANEL_SMALL = 'Panel_small';
    case SIXTEEN_NINE = '16_9';
    /** Upright images such as book covers; uses the site's `portrait` srcset presets. */
    case PORTRAIT = 'Portrait';
    case DEFAULT = 'Default';

    // Add more types as needed
}