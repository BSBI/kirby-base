<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Resolves a template-wide password from the 'templatePasswords' config option.
 *
 * The built-in password gate keys off a per-page `password` field, which does not
 * suit whole classes of auto-generated pages (e.g. customer order pages living at
 * checkout/<uuid>). This policy lets a site gate every page of a given template
 * behind one shared, temporary password via config, e.g.
 *
 *     'templatePasswords' => ['order' => 'letmein'],
 *
 * Removing the entry lifts the gate — no per-page or plugin change required.
 */
final class TemplatePasswordPolicy
{
    /**
     * Resolve the shared password configured for a template, if any.
     *
     * @param string $template the page's template name
     * @param mixed $config the raw 'templatePasswords' option value (expected: array<string, string>)
     * @return string the configured password, or '' when none applies
     */
    public static function passwordForTemplate(string $template, mixed $config): string
    {
        if (!is_array($config)) {
            return '';
        }

        $password = $config[$template] ?? '';

        return is_string($password) ? $password : '';
    }
}
