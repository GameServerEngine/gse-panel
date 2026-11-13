<?php
/*
 * Default storefront text for a new install. Neutral: it describes the product and the service,
 * and does not say anything about who runs the site. Replace it per site with a profile file in this
 * folder (see includes/content.php) or with 'content' => [...] in site_config.local.php.
 * {name}, {company} and {email} are filled from the brand settings.
 */
return array(
    'site' => array(
        'tagline' => 'Game server hosting from {company}.',
    ),
    'home' => array(
        'title_suffix' => 'Game Server Hosting',
        'meta_description' => 'Rent a game server from {name}. Pick a game, choose a size and manage it from one control panel.',
        'games' => array(
            array('name' => 'DayZ', 'image' => 'images/games/day_z.jpg'),
            array('name' => 'Rust', 'image' => 'images/games/rust_header.jpg'),
            array('name' => 'Minecraft', 'image' => 'images/games/minecraft.jpg'),
            array('name' => 'Counter-Strike Source', 'image' => 'images/games/cstrikesource.jpg'),
            array('name' => 'Arma 2', 'image' => 'images/games/arma2.jpg'),
        ),
        'hero' => array(
            'eyebrow' => 'Game Server Hosting',
            'title' => 'Your own game server, ready in minutes.',
            'lede' => 'Pick a game, choose how many players you need, and manage everything from one control panel.',
            'primary' => 'Browse Game Servers',
            'secondary' => 'Get Help',
        ),
        'trust' => array(
            array('title' => 'Ready after payment', 'text' => 'Your server is created automatically when your payment clears.'),
            array('title' => 'Full control', 'text' => 'Start, stop, edit files and change settings from the control panel.'),
            array('title' => 'Support when you need it', 'text' => 'Open a ticket and get an answer from a person.'),
            array('title' => 'Many games', 'text' => 'Popular titles and older favourites, each with its own setup guide.'),
        ),
        'why' => array(
            'eyebrow' => 'Why {name}',
            'title' => 'Everything you need to run your server.',
            'copy' => 'A simple order, a clear control panel and help close by.',
            'cards' => array(
                array('kicker' => 'Simple', 'title' => 'Order in a few steps', 'text' => 'Choose a game, a size and a location, pay, and your server is set up for you.'),
                array('kicker' => 'Control', 'title' => 'Your server, your settings', 'text' => 'Edit config files, install mods and change options without waiting for anyone.'),
                array('kicker' => 'Reliable', 'title' => 'Watched and restarted', 'text' => 'A crashed server is noticed and restarted, and you can see its real status.'),
                array('kicker' => 'Help', 'title' => 'Support tickets', 'text' => 'Ask a question from your account and follow the answer in one place.'),
            ),
        ),
        'included' => array(
            'eyebrow' => "What's Included",
            'title' => 'Everything in one control panel.',
            'copy' => 'Every server comes with the tools to run it, not just a machine to run it on.',
            'cards' => array(
                array('kicker' => 'Ports', 'title' => 'Game, query and admin ports', 'text' => 'The ports your server needs are set up and shown in the panel, ready to share with players.'),
                array('kicker' => 'Mods', 'title' => 'Steam Workshop support', 'text' => 'Install and manage Workshop mods and collections for supported games from the panel.'),
                array('kicker' => 'Automation', 'title' => 'Scheduled restarts and tasks', 'text' => 'Schedule restarts, updates and other routine tasks for the times that suit you.'),
                array('kicker' => 'Files', 'title' => 'File manager and FTP', 'text' => 'Manage configs, maps and mod files in the browser or over FTP.'),
                array('kicker' => 'Safety Net', 'title' => 'Backups', 'text' => 'Back up your server and restore it yourself if a change goes wrong.'),
                array('kicker' => 'Assistant', 'title' => 'AI assistant', 'text' => 'Ask configuration questions in plain language. It is a guide next to the panel, so review changes before you save them.', 'link' => 'ai.php', 'link_label' => 'Try the assistant'),
            ),
        ),
        'services' => array(
            'enabled' => '1',
            'eyebrow' => 'Services',
            'title' => 'Need a hand with your server?',
            'copy' => 'Order help the same way you order a server: setup, configuration and other services.',
            'link_label' => 'See services',
        ),
        'locations' => array(
            'eyebrow' => 'Hosting Locations',
            'title' => 'Choose a region close to your players.',
            'copy' => 'Game availability can differ by location.',
            'list' => array(),
        ),
        'about' => array(
            'eyebrow' => '',
            'title' => '',
            'copy' => '',
            'link_label' => '',
        ),
        'cta' => array(
            'eyebrow' => 'Ready To Start',
            'title' => 'Pick a game and get going.',
            'copy' => 'Browse the available game servers, or open a ticket if you have a question first.',
            'primary' => 'Browse Game Servers',
            'secondary' => 'Get Help',
        ),
    ),
    'services' => array(
        'title' => 'Services',
        'eyebrow' => 'Services',
        'heading' => 'Extra help for your game server',
        'intro' => 'Choose a service, tell us what you need, and we open a ticket tied to your invoice. You pay the invoice, we do the work, and we keep you posted in the ticket.',
        'empty' => 'No services are available right now. You can still open a support ticket.',
        'how_title' => 'How it works',
        'how_steps' => array(
            'Choose a service and describe what you need.',
            'We open a ticket and send you an invoice.',
            'Pay the invoice from your cart.',
            'We work in the ticket and update you there.',
        ),
    ),
    'support' => array(
        'eyebrow' => 'Support',
        'intro' => 'Open a ticket and we will answer from here. Choose Support Request for a problem or a question, or pick a service if you want us to do the work.',
    ),
    'company' => array(
        'intro' => '{name} is a game server hosting service operated by {company}.',
    ),
    'contact' => array(
        'intro' => 'Contact {company} for {name} support, account questions, billing questions or other enquiries.',
        'tip' => 'For faster support, include your account email, server name, invoice or order number, game title and the last change made before the issue started.',
        'address_lines' => array(),
        'phone' => '',
    ),
    'careers' => array(
        'enabled' => '',
    ),
);
