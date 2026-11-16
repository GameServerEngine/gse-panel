<?php
/*
 * Text for the GameServers World storefront. Written for people who play games and want their own
 * server so they can build a world and a community around it. Only the keys listed here replace
 * content/default.php. Select it with 'content_profile' => 'gameservers-world'.
 */
return array(
    'site' => array(
        'tagline' => 'Classic game servers, managed by developers, hosted by {company}.',
    ),
    'home' => array(
        'games' => array(
            array('name' => 'DayZ', 'image' => 'images/games/day_z.jpg'),
            array('name' => 'Rust', 'image' => 'images/games/rust_header.jpg'),
            array('name' => 'Counter-Strike Source', 'image' => 'images/games/cstrikesource.jpg'),
            array('name' => 'Minecraft', 'image' => 'images/games/minecraft.jpg'),
            array('name' => 'Arma 2', 'image' => 'images/games/arma2.jpg'),
        ),
        'title_suffix' => 'Classic Game Servers, Managed by Developers',
        'meta_description' => 'Affordable game servers for Counter-Strike, Rust, DayZ and every other game we can host. {name} is managed by developers who have run game servers for 20 years, and we also do setup, mods and custom scripting.',
        'hero' => array(
            'eyebrow' => 'Classic game servers, managed by developers',
            'title' => 'The old games are the best games.',
            'lede' => 'We host every game we can, and we specialize in the classics: Counter-Strike, Rust and DayZ. We have played them for years, we run them well, and we keep the price low. Your server is managed by developers, so it is set up and looked after by people who know the game.',
            'primary' => 'Browse Game Servers',
            'secondary' => 'See Services',
        ),
        'trust' => array(
            array('title' => 'Managed by developers', 'text' => 'We make games and the tools that run servers, so your server is in expert hands.'),
            array('title' => 'Classics at a low price', 'text' => 'Counter-Strike, Rust and DayZ are our specialty, and we host them for less.'),
            array('title' => '24/7 support', 'text' => 'Real people on the other end of a ticket, any hour. Better support than most hosts give.'),
            array('title' => 'Money-back guarantee', 'text' => 'Not what you hoped for? Get your money back, no questions asked.'),
        ),
        'why' => array(
            'eyebrow' => 'Why players choose {name}',
            'title' => 'More than a server. A team that knows the game.',
            'copy' => 'Most hosts hand you a machine and a login. We hand you the control, and the developers who can help you use it.',
            'cards' => array(
                array('kicker' => 'Hands-on help', 'title' => 'We work on your server with you', 'text' => 'Tell us the game and the mods you want and we install and configure them. Need something that does not exist yet? We can write the mod or script.'),
                array('kicker' => 'Real control', 'title' => 'Change what you want, when you want', 'text' => 'Edit config files in the browser or over FTP, install Steam Workshop mods, schedule restarts and updates. No locked-down templates.'),
                array('kicker' => 'Self-healing', 'title' => 'Crashes get handled', 'text' => 'A crashed server is detected and restarted, status shows whether your game port really answers, and repeated failures open a ticket for you automatically.'),
                array('kicker' => 'Safety net', 'title' => 'Backups you can restore yourself', 'text' => 'Back up your server and bring it back without waiting for support when a mod update goes wrong.'),
                array('kicker' => 'Classics', 'title' => 'We specialize in the games that last', 'text' => 'We will host any game you can rent, but Counter-Strike, Rust and DayZ are the ones we love. We have played them for years, so we know how to run them well. More than 150 game setups in all, each with its own setup guide.'),
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
            'title' => 'Need it set up your way? We build it.',
            'copy' => 'We install and configure your game, or create mods, scripts and custom changes for it whenever you need them. The price depends on the work involved, so tell us what you want and we will send you a quote. Each job gets an invoice and a ticket, so you always know where it stands.',
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
            'copy' => 'We have run game servers for two decades and we are building games of our own right now. {name} is managed by developers who play these games, so we specialize in the classics and are glad to help with anything else you want to run.',
            'link_label' => 'Browse hosting documentation',
        ),
        'cta' => array(
            'eyebrow' => 'Ready to play',
            'title' => 'Start with a classic. Make it yours.',
            'copy' => 'Browse the game servers, or tell us what you want and we will set it up, mod it and script it for you.',
            'primary' => 'Browse Game Servers',
            'secondary' => 'See Services',
        ),
    ),
    'services' => array(
        'heading' => 'Hands-on help from game developers',
        'intro' => 'We can install and configure your game, or create mods, scripts and custom changes. Tell us what you want your server to be and we send a quote based on the work required. Once you agree, we open a ticket tied to your invoice, build it, and keep you posted in the ticket.',
    ),
    'support' => array(
        'eyebrow' => 'Support',
        'intro' => 'Talk to the people who run your server. Open a ticket for a problem or a question, or pick a service when you want us to do the work: setup, mods, scripts or custom development.',
    ),
    'company' => array(
        'intro' => '{name} is operated by {company}: game developers who have hosted game servers for 20 years. We host every game we can and specialize in the classics, for communities, clans and modders.',
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
