<?php
/*
 * Text for the GameServers World storefront. Written for people who play games and want their own
 * server so they can build a world and a community around it. Only the keys listed here replace
 * content/default.php. Select it with 'content_profile' => 'gameservers-world'.
 */
return array(
    'site' => array(
        'tagline' => 'Game servers from developers who build games, hosted by {company}.',
    ),
    'home' => array(
        'title_suffix' => 'Game Servers for Creators',
        'meta_description' => 'Rent your own game server and build the world you imagine. {name} has hosted game servers for 20 years and builds games, so we can help with setup, mods and custom development.',
        'hero' => array(
            'eyebrow' => 'Game servers for creators',
            'title' => 'Build the game world you have been imagining.',
            'lede' => 'Rent your own server and make it yours: your rules, your mods, your community. We have hosted game servers for 20 years and make games ourselves, so we do more than keep your server online. We help you build it.',
            'primary' => 'Browse Game Servers',
            'secondary' => 'See Services',
        ),
        'trust' => array(
            array('title' => 'Built by game developers', 'text' => 'We make games and the tools that run servers, so we know what you are trying to do.'),
            array('title' => '20 years of hosting', 'text' => 'Two decades of running game servers, from new releases to old classics.'),
            array('title' => '24/7 support', 'text' => 'Real people on the other end of a ticket, any hour. Better support than most hosts give.'),
            array('title' => 'Money-back guarantee', 'text' => 'Not what you hoped for? Get your money back, no questions asked.'),
        ),
        'why' => array(
            'eyebrow' => 'Why creators choose {name}',
            'title' => 'More than a server. A partner in your idea.',
            'copy' => 'Most hosts hand you a machine and a login. We hand you the control, and the people who can help you use it.',
            'cards' => array(
                array('kicker' => 'Hands-on help', 'title' => 'We work on your server with you', 'text' => 'Tell us the game and the mods you want and we install and configure them. Need something that does not exist yet? We can write the mod or script.'),
                array('kicker' => 'Real control', 'title' => 'Change what you want, when you want', 'text' => 'Edit config files in the browser or over FTP, install Steam Workshop mods, schedule restarts and updates. No locked-down templates.'),
                array('kicker' => 'Self-healing', 'title' => 'Crashes get handled', 'text' => 'A crashed server is detected and restarted, status shows whether your game port really answers, and repeated failures open a ticket for you automatically.'),
                array('kicker' => 'Safety net', 'title' => 'Backups you can restore yourself', 'text' => 'Back up your server and bring it back without waiting for support when a mod update goes wrong.'),
                array('kicker' => 'Games', 'title' => 'New favourites and old classics', 'text' => 'More than 150 game setups, including older titles that are hard to find. Each comes with its own setup guide, and one-click modpacks get a community going fast.'),
                array('kicker' => 'Protected', 'title' => 'DDoS protection built in', 'text' => 'Our hosting providers filter attack traffic on their networks, so every server is protected without extra setup or fees.'),
            ),
        ),
        'included' => array(
            'eyebrow' => 'Included with every server',
            'title' => 'The tools to run your community.',
            'copy' => 'Everything is in one control panel, so you spend your time on your players and not on plumbing.',
            'cards' => array(
                array('kicker' => 'Ports', 'title' => 'Game, query and admin ports', 'text' => 'The ports your server needs are set up and shown in the panel, ready to share with players.'),
                array('kicker' => 'Mods', 'title' => 'Workshop mods and modpacks', 'text' => 'Install Steam Workshop mods and collections, or pick a ready-made modpack and install it in one click.'),
                array('kicker' => 'Database', 'title' => 'Free MySQL', 'text' => 'A MySQL database for the games and mods that need one, at no extra charge, managed from the same panel.'),
                array('kicker' => 'Automation', 'title' => 'Scheduled restarts and tasks', 'text' => 'Schedule restarts, updates and other routine tasks for the times that suit you.'),
                array('kicker' => 'Files', 'title' => 'File manager and FTP', 'text' => 'Manage configs, maps and mod files in the browser or over FTP.'),
                array('kicker' => 'Safety Net', 'title' => 'Backups', 'text' => 'Back up your server and restore it yourself if a change goes wrong.'),
            ),
        ),
        'services' => array(
            'enabled' => '1',
            'eyebrow' => 'Hire the developers',
            'title' => 'Have a vision? We can help build it.',
            'copy' => 'Order server setup, mod and script work or custom development the same way you order a server. Each job gets an invoice and a ticket, so you always know where it stands. Larger jobs can be paid in stages: a deposit to start and the rest on delivery.',
            'link_label' => 'See services',
        ),
        'locations' => array(
            'eyebrow' => 'Hosting Locations',
            'title' => 'Servers close to your players.',
            'copy' => 'Pick the region nearest your community. Game availability can differ by location.',
            'list' => array('Kansas City', 'Los Angeles', 'New York City', 'Dallas', 'Ireland'),
        ),
        'about' => array(
            'eyebrow' => 'Built on experience',
            'title' => 'Developers and gamers, 20 years in.',
            'copy' => 'We have run game servers for two decades and we are building games of our own right now. When you host with {name} you are not just a customer. You can be part of what we build, and we are glad to help you build yours.',
            'link_label' => 'Browse hosting documentation',
        ),
        'cta' => array(
            'eyebrow' => 'Ready to build',
            'title' => 'Start with a server. Bring your vision.',
            'copy' => 'Browse the game servers, or tell us what you want to create and we will help you get there.',
            'primary' => 'Browse Game Servers',
            'secondary' => 'See Services',
        ),
    ),
    'services' => array(
        'heading' => 'Hands-on help from game developers',
        'intro' => 'Tell us what you want your server to be. Choose a service, describe the job, and we open a ticket tied to your invoice. You pay, we build, and we keep you posted in the ticket. Bigger jobs can be paid in stages.',
    ),
    'support' => array(
        'eyebrow' => 'Support',
        'intro' => 'Talk to the people who run your server. Open a ticket for a problem or a question, or pick a service when you want us to do the work: setup, mods, scripts or custom development.',
    ),
    'company' => array(
        'intro' => '{name} is operated by {company}: game developers who have hosted game servers for 20 years, for communities, modders, clans and creators building their own worlds.',
    ),
    'contact' => array(
        'intro' => 'Contact {company} for {name} support, account questions, billing questions or service enquiries.',
        'address_lines' => array('3400 Laurel Rd.', 'Brunswick, OH 44212'),
        'phone' => '330-786-8223',
    ),
    'careers' => array(
        'enabled' => '1',
    ),
);
