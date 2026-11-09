<?php
/*
 * Apache vhost template rendering for the Panel and storefront (modules/billing).
 *
 * The web installer (install.php) runs as the web server user (e.g. www-data)
 * and cannot write to /etc/apache2/sites-available, so it only renders a
 * PREVIEW of these files for the admin to copy or apply via the printed root
 * command. scripts/install-panel.sh --configure-vhosts (run by a human with
 * sudo) writes the real files, enables the sites, reloads Apache, and
 * optionally runs certbot.
 *
 * Storefront domain handling: if no storefront_domain is configured (blank,
 * or equal to the panel domain), the storefront is treated as sharing the
 * panel's domain and served from a configurable sub-path alias (default
 * /store) under the panel vhost instead of a separate storefront vhost file.
 * This is a deliberate simplification — see includes/lib_vhosts.php callers
 * for where this choice is applied.
 */

if (!defined('STOREFRONT_DEFAULT_SUBPATH')) {
    define('STOREFRONT_DEFAULT_SUBPATH', '/store');
}

function vhost_template_path($base_dir, $template_name)
{
    return install_path($base_dir, 'install/vhost-templates/' . $template_name);
}

function vhost_render_template($template_path, array $tokens)
{
    if (!is_readable($template_path)) {
        return null;
    }
    $contents = file_get_contents($template_path);
    foreach ($tokens as $key => $value) {
        $contents = str_replace('{{' . $key . '}}', (string)$value, $contents);
    }
    return $contents;
}

/*
 * Returns whether the storefront should be treated as sharing the panel's
 * domain (blank storefront_domain, or explicitly equal to panel_domain).
 */
function vhost_storefront_shares_panel_domain($panel_domain, $storefront_domain)
{
    $storefront_domain = trim((string)$storefront_domain);
    if ($storefront_domain === '') {
        return true;
    }
    return strcasecmp($storefront_domain, trim((string)$panel_domain)) === 0;
}

/*
 * Builds the rendered vhost file(s) for the given options. Returns an array:
 *   [
 *     'panel' => array('filename' => ..., 'contents' => ...),
 *     'storefront' => array('filename' => ..., 'contents' => ...) | null,
 *     'shared_domain' => bool,
 *     'storefront_subpath' => string,
 *   ]
 * When the storefront shares the panel domain, 'storefront' is null and the
 * panel vhost's DocumentRoot-relative Alias for the sub-path is described in
 * 'storefront_subpath' for the caller to mention in guidance text; actual
 * Alias directives are intentionally left to manual/documented follow-up
 * since they depend on the live install path structure already handled by
 * modules/billing routing.
 */
function vhost_build_previews($base_dir, array $options)
{
    $panel_domain = trim((string)($options['panel_domain'] ?? ''));
    $storefront_domain = trim((string)($options['storefront_domain'] ?? ''));
    $https_mode = (string)($options['https_mode'] ?? 'http-only');
    $document_root = rtrim((string)($options['document_root'] ?? $base_dir), '/');
    $cert_fullchain = (string)($options['cert_fullchain'] ?? "/etc/letsencrypt/live/{$panel_domain}/fullchain.pem");
    $cert_privkey = (string)($options['cert_privkey'] ?? "/etc/letsencrypt/live/{$panel_domain}/privkey.pem");

    $shared_domain = vhost_storefront_shares_panel_domain($panel_domain, $storefront_domain);

    $panel_tokens = array(
        'SERVER_NAME' => $panel_domain !== '' ? $panel_domain : 'panel.example.com',
        'DOCUMENT_ROOT' => $document_root,
        'PANEL_ROOT' => dirname(dirname(rtrim($document_root, '/'))),
        'ERROR_LOG_NAME' => preg_replace('/[^a-zA-Z0-9_.-]/', '_', $panel_domain !== '' ? $panel_domain : 'panel'),
        'CERT_FULLCHAIN' => $cert_fullchain,
        'CERT_PRIVKEY' => $cert_privkey,
    );

    $panel_contents = vhost_render_template(
        vhost_template_path($base_dir, 'panel.conf.tpl'),
        $panel_tokens
    );

    $result = array(
        'panel' => array(
            'filename' => ($panel_domain !== '' ? $panel_domain : 'panel') . '.conf',
            'contents' => $panel_contents,
        ),
        'storefront' => null,
        'shared_domain' => $shared_domain,
        'storefront_subpath' => STOREFRONT_DEFAULT_SUBPATH,
        'https_mode' => $https_mode,
    );

    if (!$shared_domain) {
        $storefront_tokens = array(
            'SERVER_NAME' => $storefront_domain,
            'DOCUMENT_ROOT' => $document_root . '/modules/billing',
            'ERROR_LOG_NAME' => preg_replace('/[^a-zA-Z0-9_.-]/', '_', $storefront_domain),
            'CERT_FULLCHAIN' => (string)($options['storefront_cert_fullchain'] ?? "/etc/letsencrypt/live/{$storefront_domain}/fullchain.pem"),
            'CERT_PRIVKEY' => (string)($options['storefront_cert_privkey'] ?? "/etc/letsencrypt/live/{$storefront_domain}/privkey.pem"),
        );
        $result['storefront'] = array(
            'filename' => $storefront_domain . '.conf',
            'contents' => vhost_render_template(
                vhost_template_path($base_dir, 'storefront.conf.tpl'),
                $storefront_tokens
            ),
        );
    }

    return $result;
}
