# Storefront install

The storefront is `modules/billing`. It is a required module of the panel. It is served as its own
website, on its own domain, and it uses the panel's database and code.

Full guide, with the DNS, Apache and nginx examples explained: https://gameserverengine.com/storefront.html

## Checklist

1. Install and sign in to the panel. Add a remote agent and a game.
2. DNS: add an A record (and AAAA if you have IPv6) for the storefront name pointing at the web server.
3. Web server: make a virtual host whose document root is the panel's `modules/billing` folder.
   - Apache: `AllowOverride All` and `mod_rewrite` on. The `.htaccess` in the folder blocks
     `includes`, `classes`, `sql`, `tools`, `data`, `logs`, `config`, `_archived` and `site_config*.php`.
   - nginx: `.htaccess` is ignored, so copy the deny rules from the guide.
4. HTTPS: get a certificate (certbot) and redirect port 80.
5. Check that `/site_config.php` and `/includes/` return 403.
6. Copy `site_config.example.php` to `site_config.local.php` and set `panel_base`, `brand` and
   `paypal_key_file`. Leave `base_path` empty on a host of its own.
7. In the panel, Administration > Billing: add locations and plans, then set up PayPal (client ID and
   secret, Test connection, webhook to `https://<storefront>/paypal/webhook.php`).
8. Cron: `0 6 * * * www-data php <panel>/cron/cron-shop.php`. Optional, once a minute:
   `php <panel>/modules/billing/tools/refresh_status_snapshot.php`.
9. Place a sandbox order from sign-up to a running game server before you open the shop.

## Apache

```apache
<VirtualHost *:80>
    ServerName shop.example.com
    ServerAlias www.shop.example.com
    DocumentRoot /var/www/html/GSE/Panel/modules/billing

    <Directory /var/www/html/GSE/Panel/modules/billing>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/shop.example.com-error.log
    CustomLog ${APACHE_LOG_DIR}/shop.example.com-access.log combined
</VirtualHost>
```

## nginx

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name shop.example.com www.shop.example.com;

    root /var/www/html/GSE/Panel/modules/billing;
    index index.php;
    autoindex off;

    location ^~ /.well-known/acme-challenge/ {
        allow all;
    }
    location ~ ^/(_archived|classes|includes|sql|tools|data|logs|config)(/|$) {
        return 403;
    }
    location ~ ^/site_config(\.local|\.example)?\.php$ {
        return 403;
    }
    location ~ /\. {
        return 403;
    }
    location ~* \.(bak|backup|sql|md|json|log|txt|ya?ml|lock)$ {
        return 403;
    }
    location / {
        try_files $uri $uri/ =404;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

## Accounts and status

- Sign-up creates a panel account with the user role. The storefront and the panel share accounts but
  each host keeps its own session, so customers sign in on both.
- The support page status monitor always shows the Control Panel as Online (the page is served by the
  panel). Agents and game servers come from a snapshot refreshed on visits, at most once a minute.
