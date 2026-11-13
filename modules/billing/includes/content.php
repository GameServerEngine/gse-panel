<?php
/*
 * Storefront text.
 *
 * The words on the storefront pages come from content files, not from the page code:
 *
 *   content/default.php     Neutral text for a new install. Describes the product, makes no claims
 *                           about who runs the site. Tracked in git; do not edit it for one site.
 *   content/<profile>.php   A ready-made set of text for one site. Only the keys it lists replace the
 *                           default. Choose it with 'content_profile' in site_config.local.php.
 *   'content' => [...]      Your own replacements, in site_config.local.php. They win over both files.
 *
 * Use billing_text('home.hero.title') in a page. {name}, {company} and {email} in the text are filled
 * from the brand settings. Lists (numbered arrays) replace the whole list; other arrays merge by key.
 */

if (!function_exists('billing_content_is_list')) {
    function billing_content_is_list($value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        $i = 0;
        foreach ($value as $key => $_) {
            if ($key !== $i++) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('billing_content_merge')) {
    /** Put $over on top of $base. A list in $over replaces the list in $base; arrays with keys merge. */
    function billing_content_merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !billing_content_is_list($value) && !billing_content_is_list($base[$key])) {
                $base[$key] = billing_content_merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}

if (!function_exists('billing_content_profile_name')) {
    /** The profile name from site_config, reduced to letters, digits, dash and underscore. */
    function billing_content_profile_name(): string
    {
        $cfg = isset($GLOBALS['billing_runtime_site_config']) && is_array($GLOBALS['billing_runtime_site_config'])
            ? $GLOBALS['billing_runtime_site_config'] : array();
        return preg_replace('/[^A-Za-z0-9_-]/', '', (string)($cfg['content_profile'] ?? ''));
    }
}

if (!function_exists('billing_content_load')) {
    /** All text, merged. Pass $reset = true in tests to read the files again. */
    function billing_content_load(bool $reset = false): array
    {
        static $merged = null;
        if ($reset) {
            $merged = null;
        }
        if ($merged !== null) {
            return $merged;
        }
        $dir = dirname(__DIR__) . '/content';
        $merged = array();
        $default = is_file($dir . '/default.php') ? include $dir . '/default.php' : array();
        if (is_array($default)) {
            $merged = $default;
        }
        $profile = billing_content_profile_name();
        if ($profile !== '' && $profile !== 'default' && is_file($dir . '/' . $profile . '.php')) {
            $over = include $dir . '/' . $profile . '.php';
            if (is_array($over)) {
                $merged = billing_content_merge($merged, $over);
            }
        }
        $cfg = isset($GLOBALS['billing_runtime_site_config']) && is_array($GLOBALS['billing_runtime_site_config'])
            ? $GLOBALS['billing_runtime_site_config'] : array();
        if (isset($cfg['content']) && is_array($cfg['content'])) {
            $merged = billing_content_merge($merged, $cfg['content']);
        }
        return $merged;
    }
}

if (!function_exists('billing_content_get')) {
    /** The value at a dotted key such as 'home.hero.title', or $default when there is none. */
    function billing_content_get(string $key, $default = null)
    {
        $node = billing_content_load();
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}

if (!function_exists('billing_content_fill')) {
    /** Replace {name}, {company} and {email} in a text. */
    function billing_content_fill(string $text): string
    {
        if (strpos($text, '{') === false || !function_exists('billing_brand')) {
            return $text;
        }
        return strtr($text, array(
            '{name}' => billing_brand('name'),
            '{company}' => billing_brand('company'),
            '{email}' => billing_brand('email'),
        ));
    }
}

if (!function_exists('billing_text')) {
    /** One text, as plain text (escape it when you print it). Empty string when it is not set. */
    function billing_text(string $key, string $default = ''): string
    {
        $value = billing_content_get($key, null);
        return is_string($value) ? billing_content_fill($value) : $default;
    }
}

if (!function_exists('billing_text_e')) {
    /** One text, escaped for HTML. */
    function billing_text_e(string $key, string $default = ''): string
    {
        return htmlspecialchars(billing_text($key, $default), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('billing_text_list')) {
    /** A list of arrays or strings, with {name} style tokens filled in. Empty array when not set. */
    function billing_text_list(string $key): array
    {
        $value = billing_content_get($key, array());
        if (!is_array($value)) {
            return array();
        }
        $fill = function ($item) use (&$fill) {
            if (is_array($item)) {
                return array_map($fill, $item);
            }
            return is_string($item) ? billing_content_fill($item) : $item;
        };
        return array_map($fill, $value);
    }
}
