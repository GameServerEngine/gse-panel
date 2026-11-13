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
            array('title' => 'We can do the work', 'text' => 'Server setup, mods, scripts and custom development, ordered like a game server.'),
            array('title' => 'Real people', 'text' => 'Open a ticket and talk to the team that runs the platform.'),
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
                array('kicker' => 'Games', 'title' => 'New favourites and old classics', 'text' => 'More than 150 game setups, including older titles that are hard to find. Each comes with its own setup guide.'),
                array('kicker' => 'Experience', 'title' => 'Developers on your side', 'text' => 'The people behind the platform host servers every day and build games. Your questions go to people who can answer them.'),
            ),
        ),
        'included' => array(
            'eyebrow' => 'Included with every server',
            'title' => 'The tools to run your community.',
            'copy' => 'Everything is in one control panel, so you spend your time on your players and not on plumbing.',
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
        'intro' => 'Open a ticket and the team answers from here. Choose Support Request for a problem or a question. Choose a service when you want us to do the work, such as server setup or custom development.',
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
